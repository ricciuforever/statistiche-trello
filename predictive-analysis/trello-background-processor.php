<?php
/**
 * Modulo per l'Elaborazione in Background e Salvataggio Permanente
 * dell'Analisi Predittiva Trello tramite WP-Cron. (Versione 4.7 - Prompt Refined)
 */

if (!defined('ABSPATH')) exit;

// Nome della tabella custom per i risultati e dell'opzione per la coda
global $wpdb;
define('STPA_RESULTS_TABLE', $wpdb->prefix . 'stpa_predictive_results');
define('STPA_QUEUE_TABLE', $wpdb->prefix . 'stpa_analysis_queue');
define('STPA_QUEUE_OPTION', 'stpa_background_analysis_queue'); // Mantenuto per i metadati

// Nome dell'evento Cron
define('STPA_CRON_EVENT', 'stpa_process_analysis_batch_event');
// Nome della tabella custom per la cache delle schede base Trello
define('STPA_CARDS_CACHE_TABLE', $wpdb->prefix . 'stpa_trello_cards_cache');

// Costante per l'evento di sincronizzazione oraria
define('STPA_HOURLY_SYNC_EVENT', 'stpa_hourly_trello_cards_sync');
define('STPA_CARD_CACHE_DURATION', 1 * HOUR_IN_SECONDS);

/**
 * Funzione che contiene la logica di sincronizzazione delle schede Trello.
 */
function stpa_perform_trello_sync($apiKey, $apiToken) {
    global $wpdb;
    $cards_cache_table = STPA_CARDS_CACHE_TABLE;
    $synced_cards_count = 0;

    $all_user_boards = get_trello_boards($apiKey, $apiToken);
    if (is_wp_error($all_user_boards) || empty($all_user_boards)) {
        error_log("STPA Sync Error: Nessuna bacheca Trello trovata. Controlla le credenziali API.");
        return 0;
    }

    $targetBoardIds = array_column($all_user_boards, 'id');
    foreach ($targetBoardIds as $boardId) {
        $cards_from_single_board = get_trello_cards($boardId, $apiKey, $apiToken);
        if (is_array($cards_from_single_board)) {
            foreach ($cards_from_single_board as $card) {
                if (empty($card['id']) || empty($card['idBoard'])) continue;

                $is_numeric_name = is_numeric($card['name']);

                $data = [
                    'card_id'            => sanitize_text_field($card['id']),
                    'board_id'           => sanitize_text_field($card['idBoard']),
                    'card_name'          => sanitize_text_field($card['name']),
                    'card_url'           => esc_url_raw($card['url'] ?? "https://trello.com/c/{$card['id']}"),
                    'date_last_activity' => gmdate('Y-m-d H:i:s', strtotime($card['dateLastActivity'])),
                    'is_numeric_name'    => $is_numeric_name,
                    'last_synced_at'     => current_time('mysql')
                ];
                $format = ['%s', '%s', '%s', '%s', '%s', '%d', '%s'];

                $wpdb->replace($cards_cache_table, $data, $format);
                $synced_cards_count++;
            }
        }
    }

    update_option('stpa_last_trello_cards_sync', time());
    return $synced_cards_count;
}

/**
 * Hook di attivazione del plugin: crea le tabelle custom.
 */
register_activation_hook(WP_PLUGIN_DIR . '/statistiche-trello/statistiche-trello.php', 'stpa_create_plugin_tables');
function stpa_create_plugin_tables() {
    stpa_create_cards_cache_table();
    stpa_create_queue_table();
    stpa_create_comments_cache_table();
    stpa_create_results_table();
}

function stpa_create_cards_cache_table() {
    global $wpdb;
    $table_name = STPA_CARDS_CACHE_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        card_id VARCHAR(50) NOT NULL UNIQUE,
        board_id VARCHAR(50) NOT NULL,
        card_name TEXT NOT NULL,
        card_url VARCHAR(255) NOT NULL,
        date_last_activity DATETIME NOT NULL,
        is_numeric_name BOOLEAN NOT NULL DEFAULT FALSE,
        last_synced_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        INDEX card_id_idx (card_id),
        INDEX board_id_idx (board_id),
        INDEX date_last_activity_idx (date_last_activity),
        INDEX is_numeric_name_idx (is_numeric_name)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function stpa_create_queue_table() {
    global $wpdb;
    $table_name = STPA_QUEUE_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        analysis_id VARCHAR(50) NOT NULL,
        card_id VARCHAR(50) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        INDEX analysis_status_idx (analysis_id, status),
        UNIQUE KEY unique_analysis_card (analysis_id, card_id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function stpa_create_comments_cache_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'stpa_card_comments_cache';
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        card_id VARCHAR(50) NOT NULL UNIQUE,
        comments_data_json LONGTEXT,
        last_fetched_at DATETIME NOT NULL,
        etag VARCHAR(255),
        PRIMARY KEY  (id),
        INDEX card_id_idx (card_id)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

function stpa_create_results_table() {
    global $wpdb;
    $table_name = STPA_RESULTS_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        analysis_id VARCHAR(50) NOT NULL,
        period INT(11) NOT NULL,
        card_id VARCHAR(50) NOT NULL,
        card_name TEXT NOT NULL,
        card_url VARCHAR(255) NOT NULL,
        probability FLOAT NOT NULL,
        reasoning TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY unique_analysis_card_period (analysis_id, card_id, period)
    ) $charset_collate;";
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

add_filter('cron_schedules', 'stpa_add_cron_interval');
function stpa_add_cron_interval($schedules) {
    if (!isset($schedules['stpa_one_minute'])) {
        $schedules['stpa_one_minute'] = ['interval' => 60, 'display'  => esc_html__('Ogni Minuto (Analisi Trello)')];
    }
    return $schedules;
}

add_action(STPA_CRON_EVENT, 'stpa_process_batch_cron_job');
function stpa_process_batch_cron_job() {
    global $wpdb;
    $queue_meta = get_option(STPA_QUEUE_OPTION, false);
    $queue_table = STPA_QUEUE_TABLE;
    $results_table = STPA_RESULTS_TABLE;

    if (!$queue_meta || $queue_meta['status'] !== 'running') {
        wp_clear_scheduled_hook(STPA_CRON_EVENT);
        return;
    }

    $analysis_id = $queue_meta['analysis_id'];
    $batch_size = 100;

    $card_ids_to_process = $wpdb->get_col($wpdb->prepare(
        "SELECT card_id FROM $queue_table WHERE analysis_id = %s AND status = 'pending' LIMIT %d",
        $analysis_id, $batch_size
    ));

    if (empty($card_ids_to_process)) {
        stpa_finalize_analysis($queue_meta);
        return;
    }

    $ids_placeholder = implode(', ', array_fill(0, count($card_ids_to_process), '%s'));
    $wpdb->query($wpdb->prepare(
        "UPDATE $queue_table SET status = 'processing' WHERE analysis_id = %s AND card_id IN ($ids_placeholder)",
        array_merge([$analysis_id], $card_ids_to_process)
    ));

    $cards_data_for_ai = [];
    foreach ($card_ids_to_process as $card_id) {
        $card_details = stpa_get_card_details($card_id);
        $comments_result = stpa_get_card_comments_cached($card_id);
        $comments = $comments_result['comments'];
        $filtered_comments = stpa_filter_irrelevant_comments($comments);
        $cards_data_for_ai[] = ['card_id' => $card_id, 'card_name' => $card_details['name'], 'card_url' => $card_details['url'], 'comments' => $filtered_comments];
    }

    $benchmark_text = $queue_meta['benchmark_text'] ?? '';
    $analysis_results = stpa_call_openai_api($cards_data_for_ai, $queue_meta['periods'], $benchmark_text);

    if (!empty($analysis_results)) {
        foreach ($analysis_results as $card_analysis) {
            if (!empty($card_analysis['predictions'])) {
                foreach ($card_analysis['predictions'] as $prediction) {
                    $card_details_for_save = stpa_get_card_details($card_analysis['card_id']);
                    $data_to_save = [
                        'analysis_id' => $analysis_id,
                        'period'      => $prediction['period'],
                        'card_id'     => $card_analysis['card_id'],
                        'card_name'   => $card_details_for_save['name'],
                        'card_url'    => $card_details_for_save['url'],
                        'probability' => $prediction['probability'],
                        'reasoning'   => $prediction['reasoning'],
                        'created_at'  => current_time('mysql')
                    ];
                    $wpdb->replace($results_table, $data_to_save);
                }
            }
        }
    }

    $wpdb->query($wpdb->prepare(
        "UPDATE $queue_table SET status = 'completed' WHERE analysis_id = %s AND card_id IN ($ids_placeholder)",
        array_merge([$analysis_id], $card_ids_to_process)
    ));
}

function stpa_finalize_analysis($queue_meta) {
    global $wpdb;
    $results_table = STPA_RESULTS_TABLE;
    $analysis_id = $queue_meta['analysis_id'];

    wp_clear_scheduled_hook(STPA_CRON_EVENT);

    $count_saved_results = $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM $results_table WHERE analysis_id = %s", $analysis_id));

    if (empty($count_saved_results)) {
        $queue_meta['status'] = 'completed_with_errors';
    } else {
        $queue_meta['status'] = 'completed';
    }

    $queue_meta['finished_at'] = current_time('mysql');
    update_option(STPA_QUEUE_OPTION, $queue_meta);
    update_option('stpa_last_completed_analysis_id', $analysis_id);

    error_log('[Trello Analysis] Finalizzazione: Analisi ' . $analysis_id . ' completata.');
}

if (!function_exists('stpa_call_openai_api')) {
    function stpa_call_openai_api($cards_data, $periods, $benchmark_text = '') {
        if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY)) {
            error_log('[Trello Analysis] ERRORE FATALE: La chiave API di OpenAI (OPENAI_API_KEY) non è definita nel file wp-config.php.');
            return [];
        }

        $api_key = OPENAI_API_KEY;
        $api_url = "https://api.openai.com/v1/chat/completions";

        $benchmark_prompt_part = !empty($benchmark_text)
            ? "Per darti un contesto su come appaiono le trattative di successo, ecco una raccolta di commenti reali da lead che abbiamo chiuso (ESITO POSITIVO): \"$benchmark_text\". Usa questi esempi come riferimento per giudicare meglio le probabilità delle nuove schede. "
            : "";

        $periods_string = implode(', ', $periods);
        $cards_json = json_encode($cards_data, JSON_UNESCAPED_UNICODE);

        $prompt_text = <<<PROMPT
Sei un'analista di vendita senior per un'azienda italiana di arredamento. Il tuo compito è valutare il potenziale dei lead basandoti sulle loro interazioni registrate in Trello. Sii analitico, conciso e professionale.

**OBIETTIVO:**
Per ogni lead (scheda Trello), analizza i commenti e fornisci una stima di probabilità di chiusura (conversione in vendita) per i seguenti periodi futuri: $periods_string giorni.

**REGOLE DI ANALISI (Fattori Chiave):**
- **Segnali Molto Positivi (alta probabilità):**
  - Menzione di un "sopralluogo" o appuntamento fissato. Questo è il segnale più forte.
  - Richieste multiple di preventivi o revisioni di preventivi.
- **Segnali Positivi (probabilità media-alta):**
  - Cliente molto reattivo, pochi "solleciti" da parte nostra.
  - Conversazioni dettagliate e tecniche sul prodotto.
- **Segnali Negativi (bassa probabilità):**
  - Lunghi periodi di silenzio o inattività.
  - Commenti vaghi, richieste di informazioni generiche senza seguito.
  - Menzione esplicita di competitor o insoddisfazione per il prezzo.
- **Contesto Aggiuntivo:**
  - Considera l'agente assegnato e l'etichetta di "temperatura" se disponibili, ma basa il tuo giudizio principalmente sul contenuto della conversazione.

**FORMATO DI OUTPUT OBBLIGATORIO:**
La tua risposta deve essere **esclusivamente** un oggetto JSON valido. Non includere testo, spiegazioni o markdown prima o dopo il JSON. La struttura deve essere la seguente:
{
  "data": [
    {
      "card_id": "ID_DELLA_SCHEDA",
      "predictions": [
        {
          "period": 30,
          "probability": 0.75,
          "reasoning": "La probabilità a 30 giorni è alta (75%) perché il cliente ha richiesto una revisione del preventivo e ha menzionato un possibile sopralluogo. La comunicazione è reattiva, con risposte entro 24 ore. Questo indica un forte interesse e un avanzamento concreto nella trattativa. Il lead sembra ben qualificato e sta valutando attivamente l'acquisto."
        }
      ]
    }
  ]
}

**REGOLE SUL JSON:**
1.  **`probability`**: Un numero decimale (float) tra 0.0 e 1.0.
2.  **`reasoning`**: Una stringa concisa in italiano (circa 80-120 parole) che spiega la logica dietro la stima di probabilità più alta.
3.  **Dati da Analizzare**: Ecco l'array di schede da elaborare: $cards_json
PROMPT;

        $prompt_text = $benchmark_prompt_part . $prompt_text;

        $body = [
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => $prompt_text]],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.7,
        ];

        $response = wp_remote_post($api_url, [
            'method'  => 'POST',
            'timeout' => 60,
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'    => json_encode($body)
        ]);

        if (is_wp_error($response)) {
            error_log('[Trello Analysis] ERRORE WP_Error: ' . $response->get_error_message());
            return [];
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($http_code !== 200) {
            error_log('[Trello Analysis] ERRORE: La chiamata API ha fallito con codice ' . $http_code . '. Risposta: ' . $response_body);
            return [];
        }

        $data = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[Trello Analysis] ERRORE: La risposta di OpenAI non è un JSON valido.');
            return [];
        }

        $json_content = $data['choices'][0]['message']['content'] ?? '';
        if (empty($json_content)) {
            error_log('[Trello Analysis] ERRORE: La risposta di OpenAI non contiene il campo "content".');
            return [];
        }

        $decoded_json = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[Trello Analysis] ERRORE: Il contenuto JSON estratto non è valido. Contenuto: ' . $json_content);
            return [];
        }

        if (isset($decoded_json['data']) && is_array($decoded_json['data'])) {
            return $decoded_json['data'];
        }
        return $decoded_json;
    }
}

if (!function_exists('stpa_filter_irrelevant_comments')) {
    function stpa_filter_irrelevant_comments($comments) {
        if (!is_array($comments) || empty($comments)) {
            return [];
        }

        $filtered_comments = [];
        $irrelevant_patterns = [
            '#https?://app\.manychat\.com/#i',
            '#https?://www\.dropbox\.com/#i',
            '#^Per chiarire una richiesta utilizzare la scheda aprendo questo link:#i',
            '#^Preventivo: https?://compute\.fattureincloud\.it/#i',
            '#@assign {%indirizzo-mail%\}#i',
            '#@email {%indirizzo-mail%}#i',
            '#^Gentile Cliente,#i',
            '#^Reference: \[REF\d+\]#i',
            '#^To: .*?Cc: .*?#i',
            '#inoltrata email da ArchiExpo#i',
            '#resendprev: Esito = (?:Richiamato|PD|Segreteria|Chiamato|Email Inviata|Non interessato|Annullato)#i',
            '#^Bonjour,#i',
            '#^Bonsoir,#i',
            '#\\[Email\\]\(https?://app\.sendboard\.com/#i',
            '#\\[REF\d+\]#i',
            '#^Buongiorno dal Team AmarantoIdea,#i',
            '#^ClientHaRisposto$#i',
            '#Aggiungere etichetta con la temperatura!!!#i',
            '#^aspetto risposta dal fornitore#i',
            '#_prev\. per #i',
            '#@jalall\d*#i',
            '#@callcenter\d*#i',
        ];

        foreach ($comments as $comment) {
            $is_irrelevant = false;
            foreach ($irrelevant_patterns as $pattern) {
                if (preg_match($pattern, $comment['text'])) {
                    $is_irrelevant = true;
                    break;
                }
            }
            if (!$is_irrelevant) {
                $comment['text'] = mb_substr($comment['text'], 0, 1000);
                $filtered_comments[] = $comment;
            }
        }
        return $filtered_comments;
    }
}
