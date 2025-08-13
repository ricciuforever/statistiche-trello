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

// NUOVO: Modulo Marketing Advisor
include_once plugin_dir_path(__FILE__) . 'marketing-advisor/marketing-advisor-main.php';

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