<?php
/**
 * Modulo per l'Elaborazione in Background e Salvataggio Permanente
 * dell'Analisi Predittiva Trello tramite WP-Cron. (Versione 4.2 - Benchmark & Board Filtering)
 */

if (!defined('ABSPATH')) exit;

// Nome della tabella custom per i risultati e dell'opzione per la coda
global $wpdb;
define('STPA_RESULTS_TABLE', $wpdb->prefix . 'stpa_predictive_results');
define('STPA_QUEUE_OPTION', 'stpa_background_analysis_queue');

// Nome dell'evento Cron
define('STPA_CRON_EVENT', 'stpa_process_analysis_batch_event');
// Nome della tabella custom per la cache delle schede base Trello
define('STPA_CARDS_CACHE_TABLE', $wpdb->prefix . 'stpa_trello_cards_cache');

// NUOVO: Costante per l'evento di sincronizzazione oraria
define('STPA_HOURLY_SYNC_EVENT', 'stpa_hourly_trello_cards_sync');
define('STPA_CARD_CACHE_DURATION', 1 * HOUR_IN_SECONDS);

/**
 * Funzione che contiene la logica di sincronizzazione delle schede Trello.
 * Può essere chiamata sia dal pulsante manuale che dal cron job.
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

    // Aggiorna il timestamp dell'ultima sincronizzazione solo se il sync ha avuto successo
    update_option('stpa_last_trello_cards_sync', time());
    
    return $synced_cards_count;
}

/**
 * Hook di attivazione del plugin: crea o aggiorna la tabella custom per la cache delle schede Trello.
 * AGGIUNTO: Campo board_id per filtrare le analisi.
 */
register_activation_hook(WP_PLUGIN_DIR . '/statistiche-trello/statistiche-trello.php', 'stpa_create_cards_cache_table');
function stpa_create_cards_cache_table() {
    global $wpdb;
    $table_name = STPA_CARDS_CACHE_TABLE;
    $charset_collate = $wpdb->get_charset_collate();

    // AGGIUNTO board_id e relativo indice
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


// Funzione per creare la tabella di cache dei commenti
register_activation_hook(WP_PLUGIN_DIR . '/statistiche-trello/statistiche-trello.php', 'stpa_create_comments_cache_table');
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

/**
 * Hook di attivazione del plugin: crea la tabella custom nel database.
 */
register_activation_hook(WP_PLUGIN_DIR . '/statistiche-trello/statistiche-trello.php', 'stpa_create_results_table');
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

/**
 * Aggiunge un intervallo di cron custom (ogni minuto) per l'elaborazione.
 */
add_filter('cron_schedules', 'stpa_add_cron_interval');
function stpa_add_cron_interval($schedules) {
    if (!isset($schedules['stpa_one_minute'])) {
        $schedules['stpa_one_minute'] = [
            'interval' => 60,
            'display'  => esc_html__('Ogni Minuto (Analisi Trello)'),
        ];
    }
    return $schedules;
}

/**
 * Funzione principale eseguita dal Cron Job.
 */
add_action(STPA_CRON_EVENT, 'stpa_process_batch_cron_job');
function stpa_process_batch_cron_job() {
    global $wpdb; // Aggiungi questa riga per rendere $wpdb disponibile
    $table_name = STPA_RESULTS_TABLE; // Definisci il nome della tabella qui
    $queue = get_option(STPA_QUEUE_OPTION, false);

    if (!$queue || $queue['status'] !== 'running' || empty($queue['card_ids_chunks'])) {
        wp_clear_scheduled_hook(STPA_CRON_EVENT);
        if ($queue && $queue['status'] !== 'completed' && $queue['status'] !== 'completed_with_errors') {
            // Se la coda si ferma inaspettatamente, segna come errore e pulisci
            $queue['status'] = 'completed_with_errors';
            $queue['finished_at'] = current_time('mysql');
            unset($queue['temp_results'], $queue['card_ids_chunks'], $queue['benchmark_text']);
            update_option(STPA_QUEUE_OPTION, $queue);
            error_log('[Trello Analysis] Cron job interrotto inaspettatamente. Stato aggiornato a completed_with_errors.');
        }
        return;
    }

    $current_batch_cards = array_shift($queue['card_ids_chunks']);
    $queue['processed_chunks']++;

    $cards_data_for_ai = [];
    foreach ($current_batch_cards as $card_id) {
        $card_details = stpa_get_card_details($card_id);
        
        $comments_result = stpa_get_card_comments_cached($card_id);
        $comments = $comments_result['comments'];
        if ($comments_result['error']) {
            error_log('[Trello Analysis] Errore durante il recupero/cache dei commenti per card ' . $card_id . '. Usando dati cached (se disponibili) o vuoti.');
        }

        $filtered_and_cleaned_comments = stpa_filter_irrelevant_comments($comments);

        $cards_data_for_ai[] = [
            'card_id'   => $card_id,
            'card_name' => $card_details['name'],
            'card_url'  => $card_details['url'],
            'comments'  => $filtered_and_cleaned_comments
        ];
    }

    $benchmark_text = $queue['benchmark_text'] ?? '';
    $analysis_results = stpa_call_openai_api($cards_data_for_ai, $queue['periods'], $benchmark_text);

    // --- NUOVA LOGICA DI SALVATAGGIO DEI RISULTATI DOPO OGNI BATCH ---
    if (!empty($analysis_results)) {
        $analysis_id = $queue['analysis_id'];
        foreach ($analysis_results as $card_analysis) {
            if (!empty($card_analysis['predictions'])) {
                foreach ($card_analysis['predictions'] as $prediction) {
                    // Recupera i dettagli della scheda freschi per il salvataggio
                    $card_details_for_save = stpa_get_card_details($card_analysis['card_id']);
                    $data_to_save = [
                        'analysis_id' => $analysis_id,
                        'period'      => $prediction['period'],
                        'card_id'     => $card_analysis['card_id'],
                        'card_name'   => $card_details_for_save['name'], // Usa i dettagli freschi
                        'card_url'    => $card_details_for_save['url'],   // Usa i dettagli freschi
                        'probability' => $prediction['probability'],
                        'reasoning'   => $prediction['reasoning'],
                        'created_at'  => current_time('mysql')
                    ];
                    // Utilizza wpdb->replace per inserire o aggiornare:
                    // Se esiste già una riga con la stessa unique_analysis_card_period, la aggiorna.
                    // Altrimenti, ne crea una nuova.
                    $wpdb->replace($table_name, $data_to_save);
                }
            } else {
                error_log('[Trello Analysis] DEBUG: Nessuna prediction trovata per card_id: ' . ($card_analysis['card_id'] ?? 'N/D') . ' nel batch corrente.');
            }
        }
        error_log('[Trello Analysis] DEBUG: Salvataggio DB completato per ' . count($analysis_results) . ' schede in questo batch.');
    } else {
        error_log('[Trello Analysis] ERRORE: Nessun risultato di analisi dall\'API OpenAI per il batch corrente. Questo batch non sarà salvato.');
        // Potresti voler segnare la coda come con errori qui se questo accade frequentemente
        // $queue['status'] = 'completed_with_errors';
    }
    // --- FINE NUOVA LOGICA ---
    
    $queue['processed_cards'] += count($current_batch_cards);
    update_option(STPA_QUEUE_OPTION, $queue);

    if (empty($queue['card_ids_chunks'])) {
        // Se tutti i batch sono stati processati, finalizza l'analisi
        stpa_finalize_analysis($queue);
        wp_clear_scheduled_hook(STPA_CRON_EVENT);
    }
}

/**
 * Finalizza l'analisi, calcola e salva i risultati nel DB.
 */
function stpa_finalize_analysis($queue) {
    global $wpdb;
    $table_name = STPA_RESULTS_TABLE;
    $analysis_id = $queue['analysis_id'];

    // Rimozione logica che prima salvava dal temp_results. Ora i dati sono già nel DB.

    // Verifica se ci sono stati dei risultati (anche se solo parziali)
    // Non abbiamo più temp_results, quindi verifichiamo il numero di risultati già salvati per questo analysis_id
    $count_saved_results = $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM $table_name WHERE analysis_id = %s", $analysis_id)
    );

    if (empty($count_saved_results)) {
        $queue['status'] = 'completed_with_errors';
        $queue['finished_at'] = current_time('mysql');
        // Mantieni analysis_id per il clear_previous_analysis
        unset($queue['temp_results'], $queue['card_ids_chunks'], $queue['benchmark_text']);
        update_option(STPA_QUEUE_OPTION, $queue);
        error_log('[Trello Analysis] Finalizzazione: Nessun risultato trovato nel DB per analysis_id ' . $analysis_id . '. Stato impostato a completed_with_errors.');
        return;
    }

    // Se ci sono risultati salvati, anche se il processo si è interrotto prima di completare tutti i batch
    // consideriamo l'analisi "completata" dal punto di vista del salvataggio, ma segnaliamo come errore se non tutti i batch sono andati a buon fine.
    // L'UI potrà comunque mostrare i risultati parziali.

    update_option('stpa_last_completed_analysis_id', $analysis_id);

    // Se l'analisi è arrivata alla fine della coda, è 'completed'.
    // Altrimenti (es. interruzione manuale o errore cron), potrebbe essere stata impostata a 'completed_with_errors' prima.
    if ($queue['status'] !== 'completed_with_errors') {
        $queue['status'] = 'completed';
    }
    
    $queue['finished_at'] = current_time('mysql');
    unset($queue['temp_results'], $queue['card_ids_chunks'], $queue['benchmark_text']);
    update_option(STPA_QUEUE_OPTION, $queue);
    error_log('[Trello Analysis] Finalizzazione: Analisi ' . $analysis_id . ' completata. ' . $count_saved_results . ' risultati salvati nel DB.');
}

/**
 * **MODIFICATO PER GESTIRE IL BENCHMARK**
 * Chiama l'API di OpenAI (ChatGPT) per analizzare un batch di schede.
 */
if (!function_exists('stpa_call_openai_api')) {
    function stpa_call_openai_api($cards_data, $periods, $benchmark_text = '') {
        if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY)) {
            error_log('[Trello Analysis] ERRORE FATALE: La chiave API di OpenAI (OPENAI_API_KEY) non è definita nel file wp-config.php.');
            return [];
        }
        
        $api_key = OPENAI_API_KEY;
        $api_url = "https://api.openai.com/v1/chat/completions";

        // Costruisci il prompt, includendo il benchmark se presente
        $benchmark_prompt_part = !empty($benchmark_text) 
            ? "Per darti un contesto su come appaiono le trattative di successo, ecco una raccolta di commenti reali da lead che abbiamo chiuso (ESITO POSITIVO): \"$benchmark_text\". Usa questi esempi come riferimento per giudicare meglio le probabilità delle nuove schede. "
            : "";

        $prompt_text = $benchmark_prompt_part . "Sei un analista di vendite esperto e scrivi in italiano. Analizza i seguenti dati di schede Trello. Per ogni scheda, basandoti sul contenuto e la data dei commenti, fornisci una stima della probabilità di chiusura per ciascuno dei seguenti periodi: " . implode(', ', $periods) . " giorni. Considera anche etichetta temperatura e agente a cui viene assegnato. Se richiede più preventivi è buon segno. Se trovi sopralluogo nei commenti è ottimo segno. Riguardo solleciti wa, meno sono e più il cliente è reattivo e quindi più propenso alla chiusura. REGOLE: 1. Fornisci la probabilità come un numero decimale tra 0.0 e 1.0. 2. Fornisci una motivazione ('reasoning') discorsiva di circa **80-120 parole**. 3. Se non ci sono commenti rilevanti o il lead è palesemente non interessato, assegna una probabilità bassa. 4. La tua risposta DEVE essere unicamente un oggetto JSON valido contenente una chiave 'data' con un array di risultati, senza testo o spiegazioni aggiuntive. Dati: " . json_encode($cards_data, JSON_UNESCAPED_UNICODE) . " Formato output: {\"data\":[{\"card_id\": \"...\", \"predictions\": [{\"period\": 30, \"probability\": 0.85, \"reasoning\": \"...\"}]}]}";

        $body = [
            'model' => 'gpt-4o-mini',
            'messages' => [['role' => 'user', 'content' => $prompt_text]],
            'response_format' => ['type' => 'json_object'],
            'temperature' => 0.7,
        ];
        
        $response = wp_remote_post($api_url, [
            'method'  => 'POST',
            'timeout' => 120,
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

// Funzione per filtrare i commenti non utili per l'analisi dell'AI
if (!function_exists('stpa_filter_irrelevant_comments')) {
    function stpa_filter_irrelevant_comments($comments) {
        if (!is_array($comments) || empty($comments)) {
            return [];
        }

        $filtered_comments = [];
        $irrelevant_patterns = [
            // Messaggi automatici e link
            '#https?://app\.manychat\.com/#i',         // Link di ManyChat
            '#https?://www\.dropbox\.com/#i',          // Link Dropbox/PDF
            '#^Per chiarire una richiesta utilizzare la scheda aprendo questo link:#i', // Messaggi di chiarimento automatici
            '#^Preventivo: https?://compute\.fattureincloud\.it/#i', // Link preventivo FattureInCloud
            '#@assign {%indirizzo-mail%\}#i',          // Assegnazione email automatica
            '#@email {%indirizzo-mail%}#i',            // Email automatiche

            // Pattern di testo comuni e non rilevanti
            '#^Gentile Cliente,#i',                    // Inizio email automatiche
            '#^Reference: \[REF\d+\]#i',               // Reference email
            '#^To: .*?Cc: .*?#i',                      // Righe To/Cc di email
            '#inoltrata email da ArchiExpo#i',         // Messaggi inoltrati da piattaforme esterne
            '#resendprev: Esito = (?:Richiamato|PD|Segreteria|Chiamato|Email Inviata|Non interessato|Annullato)#i', // Log automatici di richiamo/stato
            '#^Bonjour,#i',                            // Inizio email in francese
            '#^Bonsoir,#i',                            // Inizio email in francese
            '#\\[Email\\]\(https?://app\.sendboard\.com/#i',   // Link email automatici (senza dipendere dall'emoji)
            '#\\[REF\d+\]#i',                          // Riferimento REF nelle email
            // '#^RIASSUNTO CONVERSAZIONE:#i', // Rimosso: Riassunti conversazioni - ORA QUESTI VERRANNO ANALIZZATI
            '#^Buongiorno dal Team AmarantoIdea,#i',   // Email automatiche di "Buongiorno dal Team..."
            '#^ClientHaRisposto$#i',                   // "ClientHaRisposto"
            '#Aggiungere etichetta con la temperatura!!!#i', // Etichette automatiche
            '#^aspetto risposta dal fornitore#i',      // Commenti specifici
            '#_prev\. per #i',                         // Preventivi con "prev. per"
            '#@jalall\d*#i',                           // Menzioni di utenti (generiche)
            '#@callcenter\d*#i',                       // Menzioni di callcenter (generiche)
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
                // Limita a 1000 caratteri. Regola questo valore se necessario.
                $comment['text'] = mb_substr($comment['text'], 0, 1000); 
                $filtered_comments[] = $comment; 
            }
        }
        return $filtered_comments;
    }
}