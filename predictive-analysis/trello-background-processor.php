<?php
/**
 * Modulo per l'Elaborazione in Background e Salvataggio Permanente
 * dell'Analisi Predittiva Trello tramite WP-Cron. (Versione 5.0 - Robustezza e Stabilità AI)
 */

if (!defined('ABSPATH')) exit;

// --- Costanti del Plugin ---
define('STPA_VERSION', '1.3.0');
define('STPA_DB_VERSION_OPTION', 'stpa_db_version');

global $wpdb;
define('STPA_RESULTS_TABLE', 'wpmq_stpa_predictive_results');
define('STPA_QUEUE_TABLE', $wpdb->prefix . 'stpa_analysis_queue');
define('STPA_QUEUE_OPTION', 'stpa_background_analysis_queue');
define('STPA_CARDS_CACHE_TABLE', $wpdb->prefix . 'stpa_trello_cards_cache');
define('STPA_COMMENTS_CACHE_TABLE', $wpdb->prefix . 'stpa_card_comments_cache');

define('STPA_CRON_EVENT', 'stpa_process_analysis_batch_event');
define('STPA_HOURLY_SYNC_EVENT', 'stpa_hourly_trello_cards_sync');
define('STPA_CARD_CACHE_DURATION', 1 * HOUR_IN_SECONDS);


// --- Logica di Installazione e Aggiornamento ---
add_action('admin_init', 'stpa_check_and_upgrade_db');
function stpa_check_and_upgrade_db() {
    $current_db_version = get_option(STPA_DB_VERSION_OPTION, '1.0.0');

    if (version_compare($current_db_version, STPA_VERSION, '<')) {
        stpa_create_plugin_tables();
        update_option(STPA_DB_VERSION_OPTION, STPA_VERSION);
    }
}

function stpa_create_plugin_tables() {
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
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
        provenance VARCHAR(255) NULL,
        date_last_activity DATETIME NOT NULL,
        is_numeric_name BOOLEAN NOT NULL DEFAULT FALSE,
        is_archived BOOLEAN NOT NULL DEFAULT FALSE,
        in_archived_list BOOLEAN NOT NULL DEFAULT FALSE,
        last_synced_at DATETIME NOT NULL,
        PRIMARY KEY  (id),
        INDEX card_id_idx (card_id),
        INDEX board_id_idx (board_id),
        INDEX date_last_activity_idx (date_last_activity),
        INDEX is_numeric_name_idx (is_numeric_name),
        INDEX is_archived_idx (is_archived),
        INDEX in_archived_list_idx (in_archived_list)
    ) $charset_collate;";
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
    dbDelta($sql);
}

function stpa_create_comments_cache_table() {
    global $wpdb;
    $table_name = STPA_COMMENTS_CACHE_TABLE;
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
    dbDelta($sql);
}


// --- Logica Cron e API ---

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
    $batch_size = 5;

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

    // --- NUOVA LOGICA DI GESTIONE ERRORE ---
    // Se la chiamata API fallisce o restituisce dati vuoti, non procediamo e non marchiamo come completato.
    // Il cron riproverà al prossimo ciclo.
    if (empty($analysis_results)) {
        error_log("[Trello Analysis] ERRORE CRITICO: La chiamata a OpenAI per il batch non ha restituito risultati. Il lotto verrà riprovato. ID Analisi: " . $analysis_id);
        // Ripristiniamo lo stato a 'pending' per un nuovo tentativo
        $wpdb->query($wpdb->prepare(
            "UPDATE $queue_table SET status = 'pending' WHERE analysis_id = %s AND card_id IN ($ids_placeholder)",
            array_merge([$analysis_id], $card_ids_to_process)
        ));
        return; // Interrompe l'esecuzione per questo ciclo
    }

    $valid_card_ids_in_batch = array_flip($card_ids_to_process);
    foreach ($analysis_results as $card_analysis) {
        $response_card_id = $card_analysis['card_id'] ?? null;

        if (!$response_card_id || !isset($valid_card_ids_in_batch[$response_card_id])) {
            error_log("[Trello Analysis] Corruzione dati AI: l'ID '{$response_card_id}' non era nel batch. Risultato scartato.");
            continue;
        }

        if (!empty($card_analysis['predictions'])) {
            foreach ($card_analysis['predictions'] as $prediction) {
                $card_details_for_save = stpa_get_card_details($response_card_id);
                $data_to_save = [
                    'analysis_id' => $analysis_id,
                    'period'      => $prediction['period'],
                    'card_id'     => $response_card_id,
                    'card_name'   => $card_details_for_save['name'],
                    'card_url'    => $card_details_for_save['url'],
                    'probability' => $prediction['probability'],
                    'reasoning'   => $prediction['reasoning'],
                    'created_at'  => current_time('mysql')
                ];
                $wpdb->replace(STPA_RESULTS_TABLE, $data_to_save);
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
            error_log('[Trello Analysis] ERRORE FATALE: La chiave API OpenAI non è definita in wp-config.php.');
            return [];
        }

        $api_key = OPENAI_API_KEY;
        $api_url = "https://api.openai.com/v1/chat/completions";

        $benchmark_prompt_part = !empty($benchmark_text)
            ? "Per darti un contesto su come appaiono le trattative di successo, ecco una raccolta di commenti reali da lead che abbiamo chiuso (ESITO POSITIVO): \"$benchmark_text\". Usa questi esempi come riferimento per giudicare meglio le probabilità delle nuove schede. "
            : "";

        $periods_string = implode(', ', $periods);
        $cards_json = json_encode($cards_data, JSON_UNESCAPED_UNICODE);

        // --- NUOVO PROMPT MIGLIORATO ---
        $prompt_text = <<<PROMPT
Sei un'analista di vendita senior per un'azienda italiana di tendostrutture. Il tuo compito è valutare il potenziale dei lead basandoti sulle interazioni registrate in Trello.

**OBIETTIVO:**
Per OGNI lead fornito, analizza i commenti e fornisci una stima di probabilità di chiusura per **TUTTI** i seguenti periodi futuri: $periods_string giorni. È OBBLIGATORIO che ogni scheda abbia una previsione per ogni singolo periodo.

**REGOLE DI ANALISI (Fattori Chiave):**
- **Priorità Assoluta (Chiusura Negativa):** Se un cliente è "scocciato", "scoglionato", chiede di non essere più contattato, o dice esplicitamente "non sono interessato", "ho scelto un altro", la probabilità per tutti i periodi è quasi zero (0.01). Questa regola ignora qualsiasi commento positivo precedente.
- **Segnali Molto Negativi (bassa probabilità):** Lunghi silenzi, commenti vaghi ("ci penso"), problemi di budget, richieste di ricontatto a lunghissimo termine.
- **Segnali Molto Positivi (alta probabilità):** Menzione di "sopralluogo" fissato, pagamento imminente ("ho fatto il bonifico"), conferma esplicita ("procediamo", "confermo ordine").
- **Segnali Positivi (media probabilità):** Cliente molto reattivo, conversazioni tecniche dettagliate, richieste di revisione preventivo.
- **Regola Fondamentale:** L'ULTIMO commento significativo del cliente ha sempre il peso maggiore.
- **Nessun Commento:** Se una scheda non ha commenti o solo log di sistema, assegna una probabilità di base molto bassa (es. 0.05) per tutti i periodi con la motivazione "Nessuna interazione umana significativa registrata.".
- **Lingua:** La tua intera risposta, motivazioni (`reasoning`) incluse, deve essere esclusivamente in italiano.

**FORMATO DI OUTPUT OBBLIGATORIO:**
La tua risposta deve essere **ESCLUSIVAMENTE** un oggetto JSON valido. Non includere testo o markdown prima o dopo il JSON. Per ogni `card_id` nell'input, deve esserci un oggetto corrispondente nell'output `data`. Ogni oggetto deve contenere un array `predictions` con un elemento per **CIASCUN** periodo richiesto ($periods_string).

{
  "data": [
    {
      "card_id": "ID_SCHEDA_1",
      "predictions": [
        {"period": 30, "probability": 0.75, "reasoning": "Motivazione concisa per il periodo a 30 giorni..."},
        {"period": 60, "probability": 0.80, "reasoning": "Motivazione concisa per il periodo a 60 giorni..."},
        {"period": 90, "probability": 0.85, "reasoning": "Motivazione concisa per il periodo a 90 giorni..."}
        // ...e così via per tutti gli altri periodi
      ]
    },
    {
      "card_id": "ID_SCHEDA_2",
      "predictions": [
        {"period": 30, "probability": 0.10, "reasoning": "Motivazione per 30 giorni..."},
        {"period": 60, "probability": 0.15, "reasoning": "Motivazione per 60 giorni..."},
        {"period": 90, "probability": 0.20, "reasoning": "Motivazione per 90 giorni..."}
        // ...etc.
      ]
    }
  ]
}

**DATI DA ANALIZZARE:**
$cards_json
PROMPT;

        $full_prompt = $benchmark_prompt_part . $prompt_text;
        
        $body = [
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => $full_prompt]],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.5, // Leggermente ridotta per maggiore coerenza
        ];

        $response = wp_remote_post($api_url, [
            'method'  => 'POST',
            'timeout' => 120, // Aumentato a 2 minuti per lotti complessi
            'headers' => [
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer ' . $api_key,
            ],
            'body'    => json_encode($body)
        ]);

        if (is_wp_error($response)) {
            error_log('[Trello Analysis OpenAI] ERRORE WP_Error: ' . $response->get_error_message());
            return [];
        }

        $http_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        if ($http_code !== 200) {
            error_log('[Trello Analysis OpenAI] ERRORE: La chiamata API ha fallito con codice ' . $http_code . '. Risposta: ' . $response_body);
            return [];
        }

        $data = json_decode($response_body, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[Trello Analysis OpenAI] ERRORE: La risposta di OpenAI non è un JSON valido.');
            return [];
        }

        // --- RIPRISTINATO: Estrazione del contenuto dalla risposta di OpenAI ---
        $json_content = $data['choices'][0]['message']['content'] ?? '';
        if (empty($json_content)) {
            error_log('[Trello Analysis OpenAI] ERRORE: La risposta di OpenAI non contiene il campo "content".');
            return [];
        }

        $decoded_json = json_decode($json_content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[Trello Analysis OpenAI] ERRORE: Il contenuto JSON estratto non è valido. Contenuto: ' . $json_content);
            return [];
        }

        if (isset($decoded_json['data']) && is_array($decoded_json['data'])) {
            return $decoded_json['data'];
        }
        return []; // Restituisce array vuoto se il formato non è corretto
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
            '#^aspetto risposta dal fornitore#i',
            '#_prev\. per #i',
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
