<?php
/**
 * Plugin Name: Statistiche Trello
 * Description: Plugin per Trello con analisi costi marketing, grafici e modulo di unione schede, esteso con Analisi Predittiva Avanzata.
 * Version: 2.16.0
 * Author: Emanuele Tolomei
 */

if (!defined('ABSPATH')) exit;

// Includi i file delle funzionalità esistenti
include_once plugin_dir_path(__FILE__) . 'trello-helpers.php';
include_once plugin_dir_path(__FILE__) . 'trello-csv-parser.php';
include_once plugin_dir_path(__FILE__) . 'trello-filter.php';
include_once plugin_dir_path(__FILE__) . 'trello-table.php';
include_once plugin_dir_path(__FILE__) . 'trello-stats.php';
include_once plugin_dir_path(__FILE__) . 'trello-marketing-charts.php';
include_once plugin_dir_path(__FILE__) . 'trello-card-merger.php';

// ... altri include ...
include_once plugin_dir_path(__FILE__) . 'predictive-analysis/trello-predictive-analysis-module.php';
include_once plugin_dir_path(__FILE__) . 'predictive-analysis/trello-background-processor.php';
// AGGIUNGI QUESTA RIGA SOTTO
include_once plugin_dir_path(__FILE__) . 'predictive-analysis/trello-real-data-exporter.php';
// NUOVO: Modulo per la generazione del Google Sheet Settimanale
include_once plugin_dir_path(__FILE__) . 'sheet/sheet-generator.php';
// NUOVO: Modulo per la generazione del Foglio Preventivi
include_once plugin_dir_path(__FILE__) . 'sheet/trello-quotes-sheet-generator.php'; // Nuovo file

// ========= INIZIO MODULO MARKETING ADVISOR CONSOLIDATO =========

/**
 * Funzione per renderizzare la pagina di amministrazione del Marketing Advisor.
 * Include CSS e JS inline per essere auto-contenuta.
 */
function stma_render_advisor_page() {
    ?>
    <style>
        .stma-wrap { max-width: 800px; margin-top: 20px; }
        .stma-wrap h1 .dashicons-before { font-size: 32px; height: 32px; width: 32px; margin-right: 8px; }
        .stma-controls { margin-top: 20px; margin-bottom: 30px; }
        .stma-results-container { border: 1px solid #c3c4c7; background: #fff; padding: 1px 20px 20px; margin-top: 20px; box-shadow: 0 1px 1px rgba(0,0,0,.04); display: none; }
        .stma-loader { text-align: center; padding: 40px 0; }
        .stma-loader .spinner { float: none; margin: 0 auto 10px auto; vertical-align: middle; }
        .stma-loader p { font-size: 1.1em; }
        .stma-results-content h3 { font-size: 1.2em; margin-top: 1.5em; margin-bottom: 0.5em; border-bottom: 1px solid #eee; padding-bottom: 5px;}
        .stma-results-content ul { list-style: disc; padding-left: 20px; }
        .stma-results-content li { margin-bottom: 0.8em; }
        #stma-error-container { display: none; margin-top: 15px; }
    </style>

    <div class="wrap stma-wrap">
        <h1><span class="dashicons-before dashicons-robot"></span> Marketing Advisor AI</h1>
        <p>Questo strumento analizza i dati di marketing degli ultimi 7 giorni, li combina con le previsioni di chiusura dei lead e fornisce suggerimenti strategici per ottimizzare il budget.</p>

        <div class="stma-controls">
            <button id="stma-run-analysis" class="button button-primary button-hero">
                <span class="dashicons dashicons-analytics"></span> Avvia Analisi Strategica
            </button>
        </div>

        <div id="stma-results-container" class="stma-results-container">
            <h2><span class="dashicons dashicons-lightbulb"></span> Risultati dell'Analisi</h2>
            <div id="stma-loader" class="stma-loader" style="display:none;">
                <span class="spinner is-active"></span>
                <p>Analisi in corso... L'intelligenza artificiale sta elaborando i dati. Potrebbero volerci fino a 2 minuti.</p>
            </div>
            <div id="stma-error-container" class="notice notice-error"></div>
            <div id="stma-results-content" class="stma-results-content"></div>
        </div>
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#stma-run-analysis').on('click', function() {
                var $button = $(this);
                var $resultsContainer = $('#stma-results-container');
                var $loader = $('#stma-loader');
                var $resultsContent = $('#stma-results-content');
                var $errorContainer = $('#stma-error-container');

                // Reset UI
                $button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt spin"></span> Analisi in corso...');
                $resultsContent.html('');
                $errorContainer.hide().html('');
                $resultsContainer.show();
                $loader.show();

                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'stma_get_marketing_analysis',
                        nonce: '<?php echo wp_create_nonce('stma_marketing_advisor_nonce'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $resultsContent.html(response.data.analysis).hide().fadeIn();
                        } else {
                            var errorMessage = '<p><strong>Errore durante l\'analisi:</strong> ' + (response.data.message || 'Errore sconosciuto.') + '</p>';
                            $errorContainer.html(errorMessage).show();
                            $resultsContent.html('');
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        var errorMessage = '<p><strong>Errore di comunicazione (AJAX):</strong> ' + textStatus + ' - ' + errorThrown + '</p>';
                        $errorContainer.html(errorMessage).show();
                        $resultsContent.html('');
                    },
                    complete: function() {
                        $loader.hide();
                        $button.prop('disabled', false).html('<span class="dashicons dashicons-analytics"></span> Avvia Analisi Strategica');
                    }
                });
            });
        });
    </script>
    <?php
}

/**
 * Funzione handler per la chiamata AJAX che esegue l'analisi di marketing.
 */
function stma_get_marketing_analysis_ajax() {
    check_ajax_referer('stma_marketing_advisor_nonce', 'nonce');

    // Recupera le chiavi API necessarie
    $openai_api_key = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
    $trello_api_key = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : '';
    $trello_api_token = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : '';

    if (empty($openai_api_key) || empty($trello_api_key) || empty($trello_api_token)) {
        wp_send_json_error(['message' => 'Una o più chiavi API (OpenAI, Trello) non sono definite in wp-config.php.']);
        return;
    }

    // 1. Ottieni i dati di marketing dalla fonte dati esistente
    if (!function_exists('wp_trello_get_marketing_data_with_leads_costs_v19')) {
        wp_send_json_error(['message' => 'La funzione per recuperare i dati di marketing (`wp_trello_get_marketing_data_with_leads_costs_v19`) non è disponibile.']);
        return;
    }
    $all_marketing_data = wp_trello_get_marketing_data_with_leads_costs_v19();

    // Il Marketing Advisor analizza i dati più recenti. Usiamo i costi della settimana corrente o dell'ultima settimana disponibile.
    $current_iso_week = date('o-\WW');
    $marketing_costs_for_period = null;
    if (isset($all_marketing_data[$current_iso_week]['costs'])) {
        $marketing_costs_for_period = $all_marketing_data[$current_iso_week]['costs'];
    } elseif (!empty($all_marketing_data)) {
        $latest_week_key = array_key_last($all_marketing_data);
        if(isset($all_marketing_data[$latest_week_key]['costs'])) {
            $marketing_costs_for_period = $all_marketing_data[$latest_week_key]['costs'];
        }
    }

    if (empty($marketing_costs_for_period)) {
        wp_send_json_error(['message' => 'Nessun dato sui costi di marketing trovato per il periodo recente. Controlla la fonte dati (Google Sheet).']);
        return;
    }

    // 2. Ottieni i lead da Trello degli ultimi 7 giorni
    $trello_leads = stma_get_trello_leads_for_period($trello_api_key, $trello_api_token);
    if (is_wp_error($trello_leads)) {
        wp_send_json_error(['message' => 'Errore nel recupero dei lead da Trello: ' . $trello_leads->get_error_message()]);
        return;
    }

    // 3. Ottieni le previsioni per le schede Trello
    $card_ids = wp_list_pluck($trello_leads, 'id');
    $predictions = !empty($card_ids) ? stma_get_predictions_for_cards($card_ids) : [];
    if (is_wp_error($predictions)) {
        wp_send_json_error(['message' => 'Errore nel recupero delle previsioni: ' . $predictions->get_error_message()]);
        return;
    }

    // 4. Mappa la "Provenienza" di Trello ai nomi interni delle piattaforme usate nel CSV
    $provenance_to_internal_platform_map = [
        'iol' => 'italiaonline', 'chiamate' => 'chiamate', 'Organic Search' => 'organico',
        'taormina' => 'taormina', 'fb' => 'facebook', 'Google Ads' => 'google ads',
        'Subito' => 'subito', 'poolindustriale.it' => 'pool industriale', 'archiexpo' => 'archiexpo',
        'Bakeka' => 'bakeka', 'Europage' => 'europage',
    ];

    // 5. Aggrega i dati
    $aggregated_data = [];
    foreach ($marketing_costs_for_period as $internal_platform => $cost) {
        $aggregated_data[$internal_platform] = ['costo_totale' => $cost, 'numero_lead' => 0, 'lead_con_previsione' => 0, 'somma_probabilita' => 0.0];
    }

    if (!empty($trello_leads)) {
        foreach ($trello_leads as $lead) {
            $provenance = trim($lead['provenance']);
            $internal_platform = $provenance_to_internal_platform_map[$provenance] ?? null;

            if ($internal_platform && isset($aggregated_data[$internal_platform])) {
                $aggregated_data[$internal_platform]['numero_lead']++;
                if (isset($predictions[$lead['id']])) {
                     $aggregated_data[$internal_platform]['lead_con_previsione']++;
                     $aggregated_data[$internal_platform]['somma_probabilita'] += (float)$predictions[$lead['id']];
                }
            }
        }
    }

    // 6. Prepara il riassunto per l'AI
    $summary_for_ai = [];
    $total_budget = array_sum($marketing_costs_for_period);
    foreach ($aggregated_data as $platform => $data) {
        $display_name = array_search($platform, $provenance_to_internal_platform_map) ?: ucfirst($platform);
        if ($data['numero_lead'] > 0) {
            $cpl = $data['costo_totale'] / $data['numero_lead'];
            $avg_quality = ($data['lead_con_previsione'] > 0) ? ($data['somma_probabilita'] / $data['lead_con_previsione']) * 100 : 0;
            $summary_for_ai[$display_name] = [
                'costo_canale' => $data['costo_totale'], 'costo_per_lead' => round($cpl, 2),
                'qualita_media_lead_percentuale' => round($avg_quality, 2), 'numero_lead_generati' => $data['numero_lead']
            ];
        } else {
             $summary_for_ai[$display_name] = [
                'costo_canale' => $data['costo_totale'], 'costo_per_lead' => 'N/A',
                'qualita_media_lead_percentuale' => 'N/A', 'numero_lead_generati' => 0
            ];
        }
    }

    if (empty($summary_for_ai)) {
        wp_send_json_error(['message' => 'Nessun dato aggregato da analizzare.']);
        return;
    }

    // 7. Costruisci il prompt per OpenAI e invia la richiesta
    $prompt = "Sei un consulente di marketing strategico per un'azienda italiana. Analizza i dati di performance dei canali di marketing del periodo più recente (budget totale speso: {$total_budget} EUR) e fornisci consigli pratici su come riallocare il budget per la prossima settimana. La 'qualità lead' è una stima della probabilità di chiusura.

Regole:
- Rispondi in italiano, con formato HTML (`<h3>`, `<p>`, `<ul>`, `<li>`, `<strong>`).
- Inizia con `<h3>Analisi Generale</h3>`.
- Prosegui con `<h3>Raccomandazioni per Canale</h3>`, analizzando ogni canale.
- Concludi con `<h3>Proposta di Allocazione Budget</h3>`, fornendo chiare percentuali di riallocazione.
- Sii diretto, pratico e orientato all'azione.

Dati da analizzare:
" . json_encode($summary_for_ai, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    $ai_response = stma_call_openai_api($prompt, $openai_api_key);
    if (is_wp_error($ai_response)) {
        wp_send_json_error(['message' => 'Chiamata a OpenAI fallita: ' . $ai_response->get_error_message()]);
        return;
    }

    wp_send_json_success(['analysis' => $ai_response]);
}
add_action('wp_ajax_stma_get_marketing_analysis', 'stma_get_marketing_analysis_ajax');

function get_trello_card_details($card_id, $api_key, $api_token) {
    $url = "https://api.trello.com/1/cards/{$card_id}?customFieldItems=true&key={$api_key}&token={$api_token}";
    $response = wp_remote_get($url, ['timeout' => 20]);
    if (is_wp_error($response)) return $response;
    return json_decode(wp_remote_retrieve_body($response), true);
}

function get_trello_board_custom_fields($board_ids, $api_key, $api_token) {
    if (!is_array($board_ids)) $board_ids = [$board_ids];
    $all_fields = [];
    foreach ($board_ids as $board_id) {
        $url = "https://api.trello.com/1/boards/{$board_id}/customFields?key={$api_key}&token={$api_token}";
        $response = wp_remote_get($url, ['timeout' => 20]);
        if (!is_wp_error($response)) {
            $fields = json_decode(wp_remote_retrieve_body($response), true);
            if (is_array($fields)) $all_fields[$board_id] = $fields;
        }
    }
    return $all_fields;
}

function stma_get_trello_leads_for_period($api_key, $api_token) {
    $all_user_boards = get_trello_boards($api_key, $api_token);
    if (is_wp_error($all_user_boards) || empty($all_user_boards)) return new WP_Error('no_boards', 'Nessuna bacheca Trello trovata.');

    $board_ids = wp_list_pluck($all_user_boards, 'id');
    $provenance_map = wp_trello_get_provenance_field_ids_map($board_ids, $api_key, $api_token);
    $all_custom_fields_defs = get_trello_board_custom_fields(array_keys($provenance_map), $api_key, $api_token);
    $seven_days_ago_timestamp = strtotime('-7 days');
    $recent_leads = [];

    global $wpdb;
    $cache_table = defined('STPA_CARDS_CACHE_TABLE') ? STPA_CARDS_CACHE_TABLE : $wpdb->prefix . 'stpa_cards_cache';

    foreach ($board_ids as $board_id) {
        $cards_from_db = $wpdb->get_results($wpdb->prepare("SELECT card_id, card_name FROM {$cache_table} WHERE board_id = %s AND is_numeric_name = 1", $board_id), ARRAY_A);
        if (empty($cards_from_db)) continue;

        foreach ($cards_from_db as $card_data) {
            if ((int) hexdec(substr($card_data['card_id'], 0, 8)) < $seven_days_ago_timestamp) continue;

            $card_details = get_trello_card_details($card_data['card_id'], $api_key, $api_token);
            if (is_wp_error($card_details) || empty($card_details)) continue;

            $provenance = 'Sconosciuta';
            if (isset($provenance_map[$board_id]) && !empty($card_details['customFieldItems'])) {
                $provenance_field_id = $provenance_map[$board_id];
                foreach ($card_details['customFieldItems'] as $field) {
                    if ($field['idCustomField'] !== $provenance_field_id || !isset($field['idValue'])) continue;

                    if (isset($all_custom_fields_defs[$board_id])) {
                        foreach ($all_custom_fields_defs[$board_id] as $def) {
                            if ($def['id'] === $provenance_field_id && isset($def['options'])) {
                                foreach ($def['options'] as $option) {
                                    if ($option['id'] === $field['idValue']) {
                                        $provenance = $option['value']['text'];
                                        break 3;
                                    }
                                }
                            }
                        }
                    }
                }
            }
            $recent_leads[] = ['id' => $card_data['card_id'], 'name' => $card_data['card_name'], 'provenance' => $provenance];
        }
    }
    return $recent_leads;
}

function stma_get_predictions_for_cards($card_ids) {
    global $wpdb;
    if (empty($card_ids)) return [];
    $results_table = $wpdb->prefix . 'stpa_analysis_results';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $results_table)) != $results_table) return new WP_Error('db_table_not_found', "Tabella `{$results_table}` non trovata.");

    $placeholders = implode(', ', array_fill(0, count($card_ids), '%s'));
    $results = $wpdb->get_results($wpdb->prepare("SELECT card_id, closing_probability FROM {$results_table} WHERE card_id IN ($placeholders)", $card_ids), OBJECT_K);
    if ($wpdb->last_error) return new WP_Error('db_query_error', 'Query fallita: ' . $wpdb->last_error);

    return wp_list_pluck($results, 'closing_probability');
}

function stma_call_openai_api($prompt, $api_key) {
    $endpoint = 'https://api.openai.com/v1/chat/completions';
    $body = ['model' => 'gpt-4o', 'messages' => [['role' => 'user', 'content' => $prompt]], 'temperature' => 0.5, 'max_tokens' => 1500];
    $args = ['body' => json_encode($body), 'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key], 'timeout' => 120];
    $response = wp_remote_post($endpoint, $args);

    if (is_wp_error($response)) return new WP_Error('openai_request_failed', 'Richiesta a OpenAI fallita: ' . $response->get_error_message());
    $response_body = json_decode(wp_remote_retrieve_body($response), true);
    if (isset($response_body['error'])) return new WP_Error('openai_api_error', 'Errore API OpenAI: ' . $response_body['error']['message']);
    if (empty($response_body['choices'][0]['message']['content'])) return new WP_Error('openai_empty_response', 'Risposta da OpenAI vuota o non valida.');

    return $response_body['choices'][0]['message']['content'];
}

// ========= FINE MODULO MARKETING ADVISOR CONSOLIDATO =========

// Aggiunge il dashboard alla pagina principale del plugin
//add_action('wp_trello_plugin_page_end', 'stpa_display_dashboard_hook');
/*function stpa_display_dashboard_hook() {
    if (function_exists('stpa_display_dashboard')) {
        echo '<hr class="uk-divider-icon uk-margin-large-top uk-margin-large-bottom">';
        stpa_display_dashboard();
    }
}*/

// --- Logica Plugin ---
add_action('admin_menu', 'wp_trello_plugin_menu');
function wp_trello_plugin_menu() {
    // Pagina principale delle statistiche
    add_menu_page(
        'Statistiche Avanzate',
        'Statistiche Avanzate',
        'manage_options',
        'wp-trello-plugin',
        'wp_trello_plugin_page_callback',
        'dashicons-analytics',
        20
    );
    // Sottopagina per la gestione duplicati
    add_submenu_page(
        'wp-trello-plugin',
        'Gestione Duplicati',
        'Gestione Duplicati',
        'manage_options',
        'wp-trello-merger',
        'wp_trello_merger_page_callback'
    );
    
    // NUOVO: Aggiungi il sottomenù per l'analisi predittiva
    add_submenu_page(
        'wp-trello-plugin',                 // Slug del menu genitore
        'Analisi Predittiva',               // Titolo della pagina
        '🔮 Analisi Predittiva',            // Titolo nel menu (con emoji)
        'manage_options',                   // Permessi richiesti
        'stpa-predictive-analysis',         // Slug univoco per questa pagina
        'stpa_render_predictive_page'       // Funzione che mostrerà la pagina
    );

    // NUOVO: Sottomenù per il Generatore Sheet Settimanale
    add_submenu_page(
        'wp-trello-plugin',                 // Slug del menu genitore
        '📊 Generatore Sheet',               // Titolo della pagina
        '📊 Generatore Sheet',              // Titolo nel menu
        'manage_options',                   // Permessi richiesti
        'stsg-sheet-generator',             // Slug univoco per questa pagina
        'stsg_render_admin_page'            // Funzione che mostrerà la pagina
    );
    // NUOVO: Sottomenù per il Foglio Preventivi
    add_submenu_page(
        'wp-trello-plugin',                 // Slug del menu genitore
        '📝 Foglio Preventivi',            // Titolo della pagina
        '📝 Foglio Preventivi',             // Titolo nel menu
        'manage_options',                   // Permessi richiesti
        'wtqsg-quotes-sheet',               // Slug univoco per questa pagina
        'wtqsg_render_quotes_sheet_page'    // Funzione che mostrerà la pagina
    );

    // Aggiunto il sottomenù del Marketing Advisor qui
    add_submenu_page(
        'wp-trello-plugin',
        'Marketing Advisor',
        '🤖 Marketing Advisor',
        'manage_options',
        'stma-marketing-advisor',
        'stma_render_advisor_page'
    );
}


// AGGIUNGI QUESTA NUOVA FUNZIONE
/**
 * Mostra il contenuto della pagina del sottomenù "Analisi Predittiva".
 */
function stpa_render_predictive_page() {
    echo '<div class="wrap">';

    // Recupera le credenziali API Trello
    $apiKey = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : '';
    $apiToken = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : '';

    if (empty($apiKey) || empty($apiToken)) {
        echo '<div class="notice notice-error"><p><strong>Errore:</strong> Le API Key e Token di Trello non sono configurate nel file wp-config.php.</p></div></div>';
        return;
    }

    global $wpdb;
    $cards_cache_table = STPA_CARDS_CACHE_TABLE;

    // --- NUOVO: Pulsante per forzare la sincronizzazione ---
    echo '<h3>Gestione Cache Schede</h3>';
    echo '<p>Il plugin sincronizza le schede da Trello ogni ora. Se hai fatto modifiche recenti o riscontri problemi, puoi forzare una nuova sincronizzazione qui.</p>';
    echo '<form method="POST" action="" style="margin-bottom: 25px;">';
    echo '    <input type="hidden" name="stpa_force_sync" value="1">';
    echo '    <button type="submit" class="button button-primary">🚀 Forza Sincronizzazione Schede Trello</button>';
    echo '</form>';
    echo '<hr>';
    // --- FINE NUOVO ---

    // --- LOGICA DI SINCRONIZZAZIONE (Stadio 1) ---
    $force_sync = isset($_POST['stpa_force_sync']) && $_POST['stpa_force_sync'] === '1';
    $last_sync_timestamp = get_option('stpa_last_trello_cards_sync', 0);
    $sync_interval = 1 * HOUR_IN_SECONDS; 

    // MODIFICA: Il sync parte se è passato l'intervallo O se viene forzato dal pulsante
    if ($force_sync || (time() - $last_sync_timestamp > $sync_interval)) {
        
        if ($force_sync) {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Sincronizzazione forzata in corso...</strong> Potrebbe richiedere qualche minuto.</p></div>';
        }

        $all_user_boards = get_trello_boards($apiKey, $apiToken);
        if (is_wp_error($all_user_boards) || empty($all_user_boards)) {
            echo '<div class="notice notice-error"><p><strong>Errore:</strong> Nessuna bacheca Trello trovata. Controlla le credenziali API.</p></div>';
        } else {
            $targetBoardIds = array_column($all_user_boards, 'id');
            $synced_cards_count = 0;

            foreach ($targetBoardIds as $boardId) {
                $cards_from_single_board = get_trello_cards($boardId, $apiKey, $apiToken);
                if (is_array($cards_from_single_board)) {
                    foreach ($cards_from_single_board as $card) {
                        if (empty($card['id']) || empty($card['idBoard'])) continue; // Salta schede corrotte

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
             if ($force_sync) {
                echo '<div class="notice notice-info is-dismissible"><p>Sincronizzazione completata. Processate <strong>' . $synced_cards_count . '</strong> schede in totale.</p></div>';
            }
        }
        // Aggiorna il timestamp dell'ultima sincronizzazione solo se il sync ha avuto successo
        update_option('stpa_last_trello_cards_sync', time());
    }

    // --- FINE LOGICA DI SINCRONIZZAZIONE ---

    // Mostra il dashboard dell'analisi predittiva
    if (function_exists('stpa_display_dashboard')) {
        stpa_display_dashboard();
    } else {
        echo '<div class="notice notice-error"><p><strong>Errore critico:</strong> Il modulo di analisi predittiva non è stato trovato.</p></div>';
    }
    echo '</div>'; // close .wrap
}

function wp_trello_plugin_page_callback() {
    echo '<div class="uk-container uk-container-expand uk-margin-top">';

    // Credenziali API Trello per le funzionalità generali del plugin (diverse da quelle dell'analisi predittiva)
    $apiKey = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : '';
    $apiToken = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : '';

    if (empty($apiKey) || empty($apiToken)) {
        echo '<div class="notice notice-error"><p>Errore: Le API Key e Token di Trello non sono configurate nel file wp-config.php. Le statistiche generali potrebbero non essere visualizzate correttamente.</p></div></div>';
        return;
    }

    $all_user_boards = get_trello_boards($apiKey, $apiToken);
    $targetBoardIds = array_map(function($board) { return $board['id']; }, is_array($all_user_boards) ? $all_user_boards : []);
    $targetBoardIds = array_filter($targetBoardIds);

    if (empty($targetBoardIds)) {
        echo '<div class="notice notice-error"><p>Errore: Nessuna board Trello trovata per l\'utente corrente. Assicurati che le credenziali API siano corrette e che tu sia membro di almeno un workspace Trello valido.</p></div></div>';
        return;
    }

    $board_provenance_ids_map = wp_trello_get_provenance_field_ids_map($targetBoardIds, $apiKey, $apiToken);

    $all_cards_from_target_boards = [];
    foreach ($targetBoardIds as $boardId) {
        if (empty($boardId)) continue;
        $cards_from_single_board = get_trello_cards($boardId, $apiKey, $apiToken);
        if(is_array($cards_from_single_board)) {
            $all_cards_from_target_boards = array_merge($all_cards_from_target_boards, $cards_from_single_board);
        } else {
             error_log("Statistiche Trello: Impossibile recuperare schede per la board ID: " . $boardId);
        }
    }

    $base_cards_for_all_filtering = wp_trello_filter_cards_by_numeric_name($all_cards_from_target_boards);
    $filtered_trello_cards_for_charts_and_table = wp_trello_apply_filters_to_cards($base_cards_for_all_filtering, $board_provenance_ids_map);

    // Rende la variabile delle schede filtrate disponibile globalmente per lo script JS
    // Questo è fondamentale per l'analisi predittiva che ha bisogno degli ID delle schede
    echo '<script type="text/javascript">';
    echo 'var filtered_trello_cards_for_charts_and_table = ' . json_encode($filtered_trello_cards_for_charts_and_table) . ';';
    echo '</script>';

    $marketing_data_from_csv = null;
    if (function_exists('wp_trello_get_marketing_data_with_leads_costs_v19')) {
        $marketing_data_from_csv = wp_trello_get_marketing_data_with_leads_costs_v19();
    }

    $current_lead_source = isset($_GET['lead_source']) && $_GET['lead_source'] === 'csv' ? 'csv' : 'trello';

    echo '<div uk-grid class="uk-grid-large">';
    echo '<div class="uk-width-1-5@m">';
    echo '<h2>Filtri Dati</h2>';
    generate_combined_filters($base_cards_for_all_filtering, $board_provenance_ids_map, $current_lead_source);
    echo '</div>';

    echo '<div class="uk-width-4-5@m">';
    echo '<h2>Analisi Performance</h2>';
    if (function_exists('wp_trello_display_combined_performance_charts')) {
        wp_trello_display_combined_performance_charts($filtered_trello_cards_for_charts_and_table, $marketing_data_from_csv, $board_provenance_ids_map, $current_lead_source);
    } else {
        echo '<p>Errore: la funzione per visualizzare i grafici combinati non è disponibile.</p>';
    }
    echo '<hr class="uk-divider-icon uk-margin-medium-top uk-margin-medium-bottom">';
    echo '<h2>Dettaglio Schede Trello Filtrate</h2>';
    $dateForStats = isset($_GET['date_from']) && !empty($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : date('Y-m-d');
    $daily_stats_data = wp_trello_calculate_daily_statistics($filtered_trello_cards_for_charts_and_table, $board_provenance_ids_map, $dateForStats);
    generate_filtered_table($filtered_trello_cards_for_charts_and_table, $board_provenance_ids_map, $daily_stats_data, $dateForStats);
    echo '</div>';
    echo '</div>'; // Fine uk-grid
    echo '</div>'; // Fine uk-container

    // Includi il display del modulo di analisi predittiva
    // Assicurati che la funzione esista prima di chiamarla
    /*if (function_exists('trello_predictive_display_final_dashboard')) {
        echo '<hr class="uk-divider-icon uk-margin-large-top uk-margin-large-bottom">';
        trello_predictive_display_final_dashboard();
    } else {
        echo '<div class="notice notice-warning"><p>Attenzione: Il modulo di analisi predittiva non è stato caricato correttamente. Verifica il file trello-predictive-analysis-module.php.</p></div>';
    }*/
    // Esegui l'hook per aggiungere contenuti alla fine della pagina
    do_action('wp_trello_plugin_page_end');
}


if (!function_exists('wp_trello_plugin_enqueue_styles')) {
    function wp_trello_plugin_enqueue_styles($hook_suffix){
        // Carica gli stili e gli script solo sulle pagine del plugin
        if(strpos($hook_suffix, 'wp-trello-plugin') === false && strpos($hook_suffix, 'wp-trello-merger') === false && strpos($hook_suffix, 'stsg-sheet-generator') === false && strpos($hook_suffix, 'wtqsg-quotes-sheet') === false && strpos($hook_suffix, 'stpa-predictive-analysis') === false) { // Aggiunto il nuovo slug
            return;
        }
        wp_enqueue_style('uikit-css','https://cdn.jsdelivr.net/npm/uikit@3.19.2/dist/css/uikit.min.css',[],'3.19.2');
        wp_enqueue_script('uikit-js','https://cdn.jsdelivr.net/npm/uikit@3.19.2/dist/js/uikit.min.js',['jquery'],'3.19.2',true);
        wp_enqueue_script('chart-js','https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',[],'3.9.1',true);
        // Il file style.css è vuoto, quindi non lo includiamo a meno che non contenga stili futuri.
        // wp_enqueue_style('wp-trello-plugin-style',plugin_dir_url(__FILE__).'style.css',['uikit-css'],'2.15.3');
    }
}
add_action('admin_enqueue_scripts','wp_trello_plugin_enqueue_styles');

// Aggiunge lo script per le variabili JavaScript necessarie al modulo di analisi predittiva
// Questo hook è necessario per passare l'URL AJAX e il nonce allo script JS del frontend
function wp_trello_predictive_add_final_script_to_admin_footer() {
    // Carica lo script solo per la pagina dell'analisi predittiva
    if (is_admin() && (strpos($_SERVER['REQUEST_URI'], 'stpa-predictive-analysis') !== false)) { // Modificato il controllo sulla URI
        wp_localize_script(
            'uikit-js', // Dipende da uno script già caricato per assicurarsi che sia disponibile
            'trello_predictive_final',
            array(
                'url'   => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce('trello_predictive_final_nonce')
            )
        );
    }
}
add_action('admin_enqueue_scripts', 'wp_trello_predictive_add_final_script_to_admin_footer');