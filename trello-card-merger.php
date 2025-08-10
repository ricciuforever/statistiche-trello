<?php
/**
 * File per il nuovo modulo di unione schede Trello duplicate
 * e visualizzazione di tutti i duplicati.
 */

if (!defined('ABSPATH')) exit;

// Costanti per la board e la lista target dove la scheda NUOVA deve trovarsi e la VECCHIA verrà spostata.
define('TRELLO_MERGE_TRIGGER_BOARD_ID', '67150e5ad352db42e640d453');
define('TRELLO_MERGE_TRIGGER_LIST_ID', '67150e65e952c5d60b55ff6b');

// In trello-card-merger.php

/**
 * Funzione per effettuare richieste GET all'API di Trello.
 */
if (!function_exists('wp_trello_merger_api_get_request')) {
    function wp_trello_merger_api_get_request($url) {
        $response = wp_remote_get($url, ['timeout' => 20]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        return json_decode(wp_remote_retrieve_body($response), true);
    }
}

/**
 * Funzione per effettuare richieste POST all'API di Trello.
 */
if (!function_exists('wp_trello_merger_api_post_request')) {
    function wp_trello_merger_api_post_request($url, $args = []) {
        $response = wp_remote_post($url, [
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode($args),
        ]);
        return $response;
    }
}

/**
 * Funzione per effettuare richieste PUT all'API di Trello.
 */
if (!function_exists('wp_trello_merger_api_put_request')) {
    function wp_trello_merger_api_put_request($url, $args = []) {
        $response = wp_remote_request($url, [
            'method'  => 'PUT',
            'timeout' => 20,
            'headers' => ['Content-Type' => 'application/json'],
            'body'    => json_encode($args),
        ]);
        return $response;
    }
}

/**
 * Funzione DEFINITIVA per trovare e unire le schede duplicate.
 * La scheda più VECCHIA viene mantenuta come MASTER.
 * Logica di gestione della descrizione resa più robusta.
 */
function wp_trello_merge_duplicate_cards($card_name, $apiKey, $apiToken) {
    if (empty($card_name)) {
        return ['success' => false, 'message' => 'Per favore, inserisci un nome scheda da cercare.'];
    }

    // 1. Recupera tutte le schede (aperte e archiviate)
    // Usiamo le funzioni helper originali senza prefisso
    if (!function_exists('get_trello_boards') || !function_exists('get_trello_cards')) {
        return ['success' => false, 'message' => 'Errore: Le funzioni helper get_trello_boards o get_trello_cards non sono disponibili.'];
    }
    $all_user_boards = get_trello_boards($apiKey, $apiToken); // CHIAMATA MODIFICATA
    if (empty($all_user_boards)) {
        return ['success' => false, 'message' => 'Nessuna board Trello trovata.'];
    }

    $all_cards_with_name = [];
    foreach ($all_user_boards as $board) {
        $cards_on_board = get_trello_cards($board['id'], $apiKey, $apiToken); // CHIAMATA MODIFICATA
        if (is_array($cards_on_board)) {
            foreach ($cards_on_board as $card) {
                if (isset($card['name']) && $card['name'] === $card_name) {
                    $all_cards_with_name[] = $card;
                }
            }
        }
    }

    if (count($all_cards_with_name) < 2) {
        return ['success' => false, 'message' => 'Trovate meno di 2 schede con il nome "' . esc_html($card_name) . '". Nessuna unione necessaria.'];
    }

    // 2. Ordina le schede per data (dalla più vecchia alla più nuova)
    usort($all_cards_with_name, function($a, $b) {
        return hexdec(substr($a['id'], 0, 8)) - hexdec(substr($b['id'], 0, 8));
    });
    
    // 3. Identifica la master (vecchia) e quelle da archiviare (le altre)
    $oldest_card = $all_cards_with_name[0];
    $cards_to_archive = array_slice($all_cards_with_name, 1);
    $newest_card = end($cards_to_archive); // La più recente in assoluto è l'ultima di questo array

    // 4. Controlla la condizione di trigger sulla scheda più recente
    if ($newest_card['idBoard'] !== TRELLO_MERGE_TRIGGER_BOARD_ID || $newest_card['idList'] !== TRELLO_MERGE_TRIGGER_LIST_ID) {
        $message = 'Operazione annullata. La scheda più recente con questo nome non si trova nella board/lista corretta ("01_GESTIONALE" / "RICHIESTE RICEVUTE").';
        return ['success' => false, 'message' => $message];
    }
    
    // 5. --- BLOCCO DESCRIZIONE ROBUSTO ---
    $description_parts = [];
    // Itera su TUTTE le schede (vecchia e nuove) per raccogliere le descrizioni
    foreach ($all_cards_with_name as $card) {
    // Sanifica e controlla la descrizione in modo sicuro
    $desc_text = isset($card['desc']) ? trim($card['desc']) : ''; // MODIFICA APPORTATA QUI
    if (!empty($desc_text)) {
        $created_date = date('d/m/Y H:i', hexdec(substr($card['id'], 0, 8)));
        $description_parts[] = "**Nuova richiesta " . $created_date . ":**\n" . $desc_text;
    }
}

    // Unisci le parti raccolte
    $final_description = implode("\n\n", $description_parts);
    
    // Crea il footer
    $merge_date = date('d/m/Y H:i');
    $footer = "\n\n--- Unione eseguita il " . $merge_date . ". L'ultima richiesta è del " . date('d/m/Y H:i', hexdec(substr($newest_card['id'], 0, 8))) . ". ---";

    // Aggiungi il footer: se c'erano descrizioni lo appende, altrimenti il footer sarà l'unico contenuto.
    $final_description .= $footer;
    // --- FINE BLOCCO DESCRIZIONE ---

    // 6. Aggiorna la scheda più vecchia (master)
    $update_url = "https://api.trello.com/1/cards/{$oldest_card['id']}?key={$apiKey}&token={$apiToken}";
    $update_args = [
        'desc'    => $final_description,
        'idBoard' => TRELLO_MERGE_TRIGGER_BOARD_ID,
        'idList'  => TRELLO_MERGE_TRIGGER_LIST_ID,
        'closed'  => false // Assicura che la scheda master sia e rimanga aperta
    ];
    $update_response = wp_trello_merger_api_put_request($update_url, $update_args);

    if (is_wp_error($update_response) || wp_remote_retrieve_response_code($update_response) !== 200) {
        $error_body = wp_remote_retrieve_body($update_response);
        return ['success' => false, 'message' => 'Errore durante l\'aggiornamento della scheda più vecchia. Dettagli: ' . esc_html($error_body)];
    }

    // 7. Archivia le altre schede
    $archived_count = 0;
    foreach ($cards_to_archive as $card_to_archive) {
        $archive_url = "https://api.trello.com/1/cards/{$card_to_archive['id']}?key={$apiKey}&token={$apiToken}";
        $archive_response = wp_trello_merger_api_put_request($archive_url, ['closed' => 'true']);
        if (!is_wp_error($archive_response) && wp_remote_retrieve_response_code($archive_response) === 200) {
            $archived_count++;
        }
    }
    
    $success_message = 'Unione completata!<ul>';
    $success_message .= '<li>Scheda principale (la più vecchia) aggiornata e spostata: ' . esc_html($oldest_card['id']) . '</li>';
    $success_message .= '<li>' . esc_html($archived_count) . ' su ' . count($cards_to_archive) . ' schede duplicate archiviate.</li>';
    $success_message .= '</ul>';

    return ['success' => true, 'message' => $success_message];
}

// La funzione wp_trello_find_all_duplicate_cards() rimane invariata
if (!function_exists('wp_trello_find_all_duplicate_cards')) {
    function wp_trello_find_all_duplicate_cards($apiKey, $apiToken) {
        // Usiamo le funzioni helper originali senza prefisso
        if (!function_exists('get_trello_boards') || !function_exists('get_trello_cards')) {
            return ['error' => 'Funzioni helper Trello get_trello_boards o get_trello_cards non disponibili.'];
        }
        $all_user_boards = get_trello_boards($apiKey, $apiToken); // CHIAMATA MODIFICATA
        if (empty($all_user_boards)) {
            return ['error' => 'Nessuna board Trello trovata.'];
        }
        $board_map = array_column($all_user_boards, 'name', 'id');
        $cards_by_name = [];
        foreach ($all_user_boards as $board) {
            $cards_on_board = get_trello_cards($board['id'], $apiKey, $apiToken); // CHIAMATA MODIFICATA
            if (is_array($cards_on_board)) {
                foreach($cards_on_board as $card) {
                    if (isset($card['name']) && !empty($card['name'])) {
                        $card['boardName'] = $board_map[$card['idBoard']] ?? $card['idBoard'];
                        $cards_by_name[$card['name']][] = $card;
                    }
                }
            }
        }
        $duplicates = array_filter($cards_by_name, function($group) {
            return count($group) > 1;
        });
        ksort($duplicates);
        return $duplicates;
    }
}


// La funzione wp_trello_merger_page_callback() rimane invariata
if (!function_exists('wp_trello_merger_page_callback')) {
    function wp_trello_merger_page_callback() {
    $feedback_message = '';
    $message_class = '';
    $all_duplicates_data = [];

    // RECUPERA LE CHIAVI DA WP-CONFIG.PHP PER MAGGIORE SICUREZZA
    $apiKey = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : '';
    $apiToken = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : '';

    if (empty($apiKey) || empty($apiToken)) {
        // Gestisci l'errore se le costanti non sono definite
        $message_class = 'notice-error';
        $feedback_message = 'Errore: Le API Key o Token di Trello non sono configurate correttamente.';
    } else {

        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['trello_merge_nonce'])) {
            if (wp_verify_nonce($_POST['trello_merge_nonce'], 'trello_merge_cards_action')) {
                $card_name_to_merge = sanitize_text_field($_POST['card_name']);
                $result = wp_trello_merge_duplicate_cards($card_name_to_merge, $apiKey, $apiToken);
                $message_class = $result['success'] ? 'notice-success' : 'notice-error';
                $feedback_message = $result['message'];
            } else {
                $message_class = 'notice-error';
                $feedback_message = 'Errore di sicurezza. Riprova.';
            }
        }

        if (isset($_GET['action']) && $_GET['action'] === 'view_all_duplicates') {
            if (function_exists('wp_trello_find_all_duplicate_cards')) {
                $all_duplicates_data = wp_trello_find_all_duplicate_cards($apiKey, $apiToken);
            }
        }
    }
        ?>
        <div class="wrap">
            <h1>Gestione Schede Trello Duplicate</h1>
            <hr/>
            <div class="uk-grid-divider" uk-grid>
                <div class="uk-width-1-2@m">
                    <h2>Unione Manuale per Nome</h2>
                    <p>Inserisci un nome scheda per cercare e unire i suoi duplicati secondo le regole definite.</p>
                    <?php if (!empty($feedback_message)): ?>
                        <div class="notice <?php echo esc_attr($message_class); ?> is-dismissible"> 
                            <p><?php echo wp_kses_post($feedback_message); ?></p>
                        </div>
                    <?php endif; ?>
                    <div class="uk-card uk-card-default uk-card-body">
                        <form method="post" action="?page=wp-trello-merger">
                            <?php wp_nonce_field('trello_merge_cards_action', 'trello_merge_nonce'); ?>
                            <div class="uk-margin">
                                <label class="uk-form-label" for="card_name">Nome Scheda da Unire:</label>
                                <div class="uk-form-controls">
                                    <input class="uk-input" id="card_name" name="card_name" type="text" placeholder="Es: +393331234567" required>
                                </div>
                            </div>
                            <div class="uk-margin">
                                <button type="submit" class="uk-button uk-button-primary">Cerca e Unisci</button>
                            </div>
                        </form>
                    </div>
                </div>
                <div class="uk-width-1-2@m">
                    <h2>Riepilogo Globale Duplicati</h2>
                    <p>Clicca il pulsante per avviare una scansione di tutte le board e visualizzare un elenco di tutte le schede con nomi duplicati.</p>
                    <a href="?page=wp-trello-merger&action=view_all_duplicates" class="uk-button uk-button-secondary">Visualizza Tutti i Duplicati Attivi</a>
                </div>
            </div>
            <hr class="uk-divider-icon uk-margin-large-top">
            <?php if (isset($_GET['action']) && $_GET['action'] === 'view_all_duplicates'): ?>
                <h3>Elenco Schede Duplicate</h3>
                <?php if (empty($all_duplicates_data)): ?>
                    <div class="notice notice-info"><p>Nessun duplicato trovato in tutte le board.</p></div>
                <?php elseif (isset($all_duplicates_data['error'])): ?>
                     <div class="notice notice-error"><p><?php echo esc_html($all_duplicates_data['error']); ?></p></div>
                <?php else: ?>
                    <ul uk-accordion>
                        <?php foreach ($all_duplicates_data as $name => $cards): ?>
                            <li>
                                <a class="uk-accordion-title" href="#"><strong><?php echo esc_html($name); ?></strong> (<?php echo count($cards); ?> schede)</a>
                                <div class="uk-accordion-content">
                                    <div class="uk-overflow-auto">
                                        <table class="uk-table uk-table-small uk-table-striped uk-table-hover">
                                            <thead>
                                                <tr>
                                                    <th>Stato</th><th>ID Scheda</th><th>Board</th><th>ID Lista</th><th>Data Creazione</th><th>Link</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php
                                                usort($cards, function($a, $b) {
                                                    return hexdec(substr($a['id'], 0, 8)) - hexdec(substr($b['id'], 0, 8));
                                                });
                                                foreach($cards as $index => $card):
                                                    $created_date = date('d/m/Y H:i', hexdec(substr($card['id'], 0, 8)));
                                                ?>
                                                <tr>
                                                    <td>
    <?php if($index === 0) echo '<span class="uk-text-bold uk-text-success">(Più vecchia)</span>'; ?>
    <?php if(!empty($card['closed'])) echo '<br><span class="uk-text-bold uk-text-danger">(Archiviata)</span>'; ?>
</td>
                                                    <td><?php echo esc_html($card['id']); ?></td>
                                                    <td><?php echo esc_html($card['boardName']); ?></td>
                                                    <td><?php echo esc_html($card['idList']); ?></td>
                                                    <td><?php echo esc_html($created_date); ?></td>
                                                    <td><a href="https://trello.com/c/<?php echo esc_attr($card['id']); ?>" target="_blank" uk-icon="icon: link"></a></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}

// --- Inizio Blocco per l'Automazione Cron ---

// Funzione che verrà eseguita dalla cron.
if (!function_exists('wp_trello_merger_automatic_merge_cron_job')) {
    function wp_trello_merger_automatic_merge_cron_job() {
        error_log('WP Trello Merger: Cron job avviato.'); // LOG INIZIALE

        $apiKey = 'c4e99df1085f9da64e648c059868cfb3';
        $apiToken = 'ATTA03792121772273098c6b4458f2b3e9529ed361f08075722e975ccbd24fe4c81f98FB357C';

        // Verifica la disponibilità delle funzioni API helper prima di procedere
        if (!function_exists('wp_trello_merger_api_get_request')) {
             error_log('WP Trello Merger ERROR: wp_trello_merger_api_get_request non disponibile.');
             return; // Esci se la funzione base per le API non c'è
        }


        $all_duplicates = wp_trello_find_all_duplicate_cards($apiKey, $apiToken);
        error_log('WP Trello Merger: Risultato di wp_trello_find_all_duplicate_cards: ' . print_r($all_duplicates, true)); // LOG RISULTATO FIND ALL DUPLICATES

        if (!empty($all_duplicates) && !isset($all_duplicates['error'])) {
            error_log('WP Trello Merger: Trovati ' . count($all_duplicates) . ' gruppi di duplicati.');
            foreach ($all_duplicates as $card_name => $cards_group) {
                error_log('WP Trello Merger: Tentativo di unione per scheda: ' . $card_name); // LOG PER OGNI SCHEDA DUPLICATA
                $result = wp_trello_merge_duplicate_cards($card_name, $apiKey, $apiToken);
                error_log('WP Trello Merger: Risultato unione per "' . $card_name . '": ' . print_r($result, true)); // LOG RISULTATO UNIONE
            }
            error_log('WP Trello Merger: Processo di unione duplicati completato.');
        } elseif (isset($all_duplicates['error'])) {
            error_log('WP Trello Merger ERROR: Errore durante la ricerca dei duplicati: ' . $all_duplicates['error']); // LOG ERRORE RICERCA DUPLICATI
        } else {
            error_log('WP Trello Merger: Nessun duplicato trovato, nessuna unione necessaria.'); // LOG NESSUN DUPLICATO
        }
        error_log('WP Trello Merger: Cron job terminato.'); // LOG FINALE
    }
}


// Nome dell'evento cron. Usiamo un prefisso custom.
// Ho cambiato il nome per riflettere la nuova frequenza, è buona pratica
define('WP_TRELLO_MERGER_CRON_EVENT', 'wp_trello_merger_five_minutes_auto_merge');

// ---
### Aggiungi questo blocco per definire l'intervallo di 5 minuti
if (!function_exists('wp_trello_merger_add_five_min_cron_interval')) {
    function wp_trello_merger_add_five_min_cron_interval($schedules) {
        $schedules['five_minutes'] = array(
            'interval' => 300, // 300 secondi = 5 minuti
            'display'  => esc_html__('Every 5 Minutes (Trello Merger)', 'your-textdomain'),
        );
        return $schedules;
    }
    add_filter('cron_schedules', 'wp_trello_merger_add_five_min_cron_interval');
}


// Programmare l'evento cron al caricamento del plugin/tema
if (!function_exists('wp_trello_merger_schedule_cron')) {
    function wp_trello_merger_schedule_cron() {
        // Controlla se l'evento è già programmato per evitare duplicazioni.
        if (!wp_next_scheduled(WP_TRELLO_MERGER_CRON_EVENT)) {
            // Programma l'evento per essere eseguito ogni 5 minuti.
            // Ho cambiato 'hourly' in 'five_minutes'
            wp_schedule_event(time(), 'five_minutes', WP_TRELLO_MERGER_CRON_EVENT);
        }
    }
    // Aggancia la funzione allo hook 'wp_loaded' per assicurarsi che WordPress sia completamente caricato.
    add_action('wp_loaded', 'wp_trello_merger_schedule_cron');
}

// Aggancia la funzione di unione automatica al nostro evento cron.
add_action(WP_TRELLO_MERGER_CRON_EVENT, 'wp_trello_merger_automatic_merge_cron_job');

// --- Fine Blocco per l'Automazione Cron ---