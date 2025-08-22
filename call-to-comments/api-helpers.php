<?php
/**
 * Helper per le chiamate API del modulo Call to Comments.
 */

if (!defined('ABSPATH')) exit;

// --- CONFIGURAZIONE ---
// Inserisci qui le credenziali FTP. Per maggiore sicurezza, potresti definirle in wp-config.php
define('STC_FTP_HOST', 'u354837.your-storagebox.de');
define('STC_FTP_USER', 'u354837-sub10');
define('STC_FTP_PASS', '4QRrGc5iYvA7DwMe');

/**
 * Si connette al server FTP.
 * @return resource|WP_Error La risorsa di connessione FTP o un errore.
 */
function stc_connect_ftp() {
    $conn_id = ftp_connect(STC_FTP_HOST);
    if ($conn_id === false) {
        return new WP_Error('ftp_connection_failed', 'Impossibile connettersi al server FTP: ' . STC_FTP_HOST);
    }

    $login_result = ftp_login($conn_id, STC_FTP_USER, STC_FTP_PASS);
    if ($login_result === false) {
        return new WP_Error('ftp_login_failed', 'Login FTP fallito per l\'utente ' . STC_FTP_USER);
    }
    
    // Abilita la modalità passiva (spesso necessaria per superare i firewall)
    ftp_pasv($conn_id, true);

    return $conn_id;
}

/**
 * Estrae il numero di telefono dal nome del file audio.
 * Esempio: crm4_4047730004_3687514018_20250806_121039_amasrl.wav -> 3687514018
 * @param string $filename
 * @return string|false
 */
function stc_extract_phone_number_from_filename($filename) {
    $parts = explode('_', $filename);
    if (count($parts) >= 3) {
        // Il numero di telefono è il terzo elemento
        $phone = $parts[2];
        if (is_numeric($phone)) {
            return $phone;
        }
    }
    return false;
}

/**
 * Invia un file audio all'API Whisper di OpenAI per la trascrizione.
 * @param string $file_path Percorso del file audio locale.
 * @return string|WP_Error Il testo trascritto o un errore.
 */
function stc_transcribe_audio_openai($file_path) {
    if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY)) {
        return new WP_Error('openai_key_missing', 'La chiave API di OpenAI non è definita.');
    }

    $api_key = OPENAI_API_KEY;
    $url = 'https://api.openai.com/v1/audio/transcriptions';

    $cfile = curl_file_create($file_path, 'audio/wav', basename($file_path));

    $post_fields = [
        'file' => $cfile,
        'model' => 'whisper-1',
        'response_format' => 'text',
        'language' => 'it' // Imposta la lingua su Italiano
    ];

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_fields);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $api_key
    ]);
    // Aumenta il timeout per file audio lunghi
    curl_setopt($ch, CURLOPT_TIMEOUT, 300);

    $result = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    if ($result === false) {
        return new WP_Error('openai_curl_error', 'Errore cURL: ' . $curl_error);
    }
    
    if ($http_code !== 200) {
        return new WP_Error('openai_api_error', "Errore API OpenAI (Codice: {$http_code}): " . $result);
    }
    
    return trim($result);
}

/**
 * Cerca una scheda Trello per nome e aggiunge un commento.
 * @param string $card_name Nome della scheda da cercare (es. +393331234567).
 * @param string $comment_text Testo del commento da aggiungere.
 * @return array|WP_Error Dettagli della scheda aggiornata o un errore.
 */
function stc_add_comment_to_trello_card($card_name, $comment_text) {
    $api_key = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : '';
    $api_token = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : '';

    if (empty($api_key) || empty($api_token)) {
        return new WP_Error('trello_keys_missing', 'Le chiavi API di Trello non sono definite.');
    }

    // 1. Cerca la scheda in tutte le board dell'utente
    // Riutilizziamo la logica esistente nel plugin per la ricerca
    $all_user_boards = get_trello_boards($api_key, $api_token);
    if (empty($all_user_boards)) {
        return new WP_Error('no_trello_boards', 'Nessuna board Trello trovata per l\'utente.');
    }

    $found_card = null;
    foreach ($all_user_boards as $board) {
        $cards_on_board = get_trello_cards($board['id'], $api_key, $api_token);
        if (is_array($cards_on_board)) {
            foreach ($cards_on_board as $card) {
                // Cerca una corrispondenza esatta e ignora le schede archiviate
                if (isset($card['name']) && $card['name'] === $card_name && empty($card['closed'])) {
                    $found_card = $card;
                    break 2; // Esce da entrambi i cicli
                }
            }
        }
    }

    if (!$found_card) {
        return new WP_Error('card_not_found', 'Nessuna scheda Trello attiva trovata con il nome: ' . $card_name);
    }

    // 2. Aggiungi il commento alla scheda trovata
    $card_id = $found_card['id'];
    $url = "https://api.trello.com/1/cards/{$card_id}/actions/comments?key={$api_key}&token={$api_token}";
    
    $response = wp_remote_post($url, [
        'body' => ['text' => $comment_text],
        'timeout' => 30
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('trello_comment_api_error', $response->get_error_message());
    }

    $http_code = wp_remote_retrieve_response_code($response);
    if ($http_code !== 200) {
        return new WP_Error('trello_comment_http_error', "Errore API Trello (Codice: {$http_code}): " . wp_remote_retrieve_body($response));
    }

    return [
        'card_id'   => $card_id,
        'card_url'  => $found_card['url'] ?? "https://trello.com/c/{$card_id}",
        'card_name' => $found_card['name']
    ];
}