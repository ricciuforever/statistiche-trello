<?php
/**
 * Plugin Name: Statistiche Trello
 * Description: Plugin per Trello con analisi costi marketing, grafici e modulo di unione schede, esteso con Analisi Predittiva Avanzata.
 * Version: 2.22.0
 * Author: Emanuele Tolomei
 */

if (!defined('ABSPATH')) exit;

// Includi i file delle funzionalità
include_once plugin_dir_path(__FILE__) . 'trello-helpers.php';
include_once plugin_dir_path(__FILE__) . 'trello-csv-parser.php';
include_once plugin_dir_path(__FILE__) . 'trello-filter.php';
include_once plugin_dir_path(__FILE__) . 'trello-table.php';
include_once plugin_dir_path(__FILE__) . 'trello-stats.php';
include_once plugin_dir_path(__FILE__) . 'trello-marketing-charts.php';
include_once plugin_dir_path(__FILE__) . 'trello-card-merger.php';

// Includi i moduli aggiuntivi
include_once plugin_dir_path(__FILE__) . 'predictive-analysis/trello-predictive-analysis-module.php';
include_once plugin_dir_path(__FILE__) . 'predictive-analysis/trello-background-processor.php';
include_once plugin_dir_path(__FILE__) . 'predictive-analysis/trello-real-data-exporter.php';
include_once plugin_dir_path(__FILE__) . 'sheet/sheet-generator.php';
include_once plugin_dir_path(__FILE__) . 'sheet/trello-quotes-sheet-generator.php';
include_once plugin_dir_path(__FILE__) . 'sheet/email-reporter.php';
include_once plugin_dir_path(__FILE__) . 'marketing-advisor/marketing-advisor-main.php';
include_once plugin_dir_path(__FILE__) . 'marketing-advisor/marketing-advisor-infographic.php'; 
include_once plugin_dir_path(__FILE__) . 'call-to-comments/call-to-comments-main.php';


// --- Logica Plugin ---
add_action('admin_menu', 'wp_trello_plugin_menu');
function wp_trello_plugin_menu() {
    add_menu_page('Statistiche Avanzate', 'Statistiche Avanzate', 'manage_options', 'wp-trello-plugin', 'wp_trello_plugin_page_callback', 'dashicons-analytics', 20);
    add_submenu_page('wp-trello-plugin', 'Gestione Duplicati', 'Gestione Duplicati', 'manage_options', 'wp-trello-merger', 'wp_trello_merger_page_callback');
    add_submenu_page('wp-trello-plugin', 'Analisi Predittiva', '🔮 Analisi Predittiva', 'manage_options', 'stpa-predictive-analysis', 'stpa_render_predictive_page');
    add_submenu_page('wp-trello-plugin', '📊 Generatore Sheet', '📊 Generatore Sheet', 'manage_options', 'stsg-sheet-generator', 'stsg_render_admin_page');
    add_submenu_page('wp-trello-plugin', '📝 Foglio Preventivi', '📝 Foglio Preventivi', 'manage_options', 'wtqsg-quotes-sheet', 'wtqsg_render_quotes_sheet_page');
    add_submenu_page('wp-trello-plugin', 'Marketing Advisor', '🤖 Marketing Advisor', 'manage_options', 'stma-marketing-advisor', 'stma_render_advisor_page');
    add_submenu_page('wp-trello-plugin', '📧 Report Email', '📧 Report Email', 'manage_options', 'stsg-email-reporter', 'stsg_render_email_report_settings_page');
    // ==> AGGIUNGI QUESTA RIGA <==
    if (function_exists('stc_register_submenu_page')) {
        stc_register_submenu_page();
    }
}


/**
 * Mostra il contenuto della pagina del sottomenù "Analisi Predittiva".
 */
function stpa_render_predictive_page() {
    echo '<div class="wrap">';
    $apiKey = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : '';
    $apiToken = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : '';
    if (empty($apiKey) || empty($apiToken)) {
        echo '<div class="notice notice-error"><p><strong>Errore:</strong> Le API Key e Token di Trello non sono configurate nel file wp-config.php.</p></div></div>';
        return;
    }
    global $wpdb;
    $cards_cache_table = STPA_CARDS_CACHE_TABLE;
    echo '<h3>Gestione Cache Schede</h3>';
    echo '<p>Il plugin sincronizza le schede da Trello ogni ora. Se hai fatto modifiche recenti o riscontri problemi, puoi forzare una nuova sincronizzazione qui.</p>';
    echo '<form method="POST" action="" style="margin-bottom: 25px;">';
    echo '<input type="hidden" name="stpa_force_sync" value="1">';
    echo '<button type="submit" class="button button-primary">🚀 Forza Sincronizzazione Schede Trello</button>';
    echo '</form>';
    echo '<hr>';

    $force_sync = isset($_POST['stpa_force_sync']) && $_POST['stpa_force_sync'] === '1';

    if ($force_sync) {
        echo '<div class="notice notice-success is-dismissible"><p><strong>Sincronizzazione forzata in corso...</strong> Vengono richieste TUTTE le schede (attive e archiviate) da Trello.</p></div>';

        $all_user_boards = get_trello_boards($apiKey, $apiToken);
        if (is_wp_error($all_user_boards) || empty($all_user_boards)) {
            echo '<div class="notice notice-error"><p><strong>Errore:</strong> Nessuna bacheca Trello trovata.</p></div>';
        } else {
            set_time_limit(600);
            $targetBoardIds = array_column($all_user_boards, 'id');
            
            // NUOVA LOGICA: Recupera tutti gli ID delle liste archiviate in anticipo
            $archived_list_ids = [];
            if (function_exists('wp_trello_get_all_lists_for_board')) {
                foreach ($targetBoardIds as $boardId) {
                    if(empty($boardId)) continue;
                    $lists_on_board = wp_trello_get_all_lists_for_board($boardId, $apiKey, $apiToken);
                    if (is_array($lists_on_board)) {
                        foreach ($lists_on_board as $list) {
                            if (!empty($list['closed'])) {
                                $archived_list_ids[] = $list['id'];
                            }
                        }
                    }
                }
            }

            $board_provenance_ids_map = wp_trello_get_provenance_field_ids_map($targetBoardIds, $apiKey, $apiToken);
            $synced_cards_count = 0;

            foreach ($targetBoardIds as $boardId) {
                if(empty($boardId)) continue;

                $cards_from_single_board = [];
                $before = null;
                $fields = "id,name,desc,closed,idBoard,idList,labels,customFieldItems,dateLastActivity,url";
                $limit = 1000;

                while (true) {
                    $url = "https://api.trello.com/1/boards/{$boardId}/cards?filter=all&customFieldItems=true&fields={$fields}&limit={$limit}&key={$apiKey}&token={$apiToken}";
                    if ($before) { $url .= "&before=" . $before; }
                    $response = wp_remote_get($url, ['timeout' => 30]);
                    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { break; }
                    $cards_batch = json_decode(wp_remote_retrieve_body($response), true);
                    if (!is_array($cards_batch) || empty($cards_batch)) { break; }
                    $cards_from_single_board = array_merge($cards_from_single_board, $cards_batch);
                    $before = end($cards_batch)['id'];
                    if (count($cards_batch) < $limit) { break; }
                }

                if (is_array($cards_from_single_board)) {
                    foreach ($cards_from_single_board as $card) {
                        if (empty($card['id']) || empty($card['idBoard'])) continue;
                        $provenance = wp_trello_get_card_provenance($card, $board_provenance_ids_map);

// --- INIZIO MODIFICA: Logica migliorata per il controllo del nome numerico ---
$name_to_check = trim($card['name']);
// Rimuovi il '+' iniziale se presente, per gestire i prefissi internazionali
if (strpos($name_to_check, '+') === 0) {
    $name_to_check = substr($name_to_check, 1);
}
$is_valid_numeric_name = is_numeric($name_to_check);
// --- FINE MODIFICA ---

$data = [
    'card_id'            => sanitize_text_field($card['id']),
    'board_id'           => sanitize_text_field($card['idBoard']),
    'card_name'          => sanitize_text_field($card['name']),
    'card_url'           => esc_url_raw($card['url'] ?? "https://trello.com/c/{$card['id']}"),
    'provenance'         => $provenance,
    'date_last_activity' => gmdate('Y-m-d H:i:s', strtotime($card['dateLastActivity'])),
    'is_numeric_name'    => $is_valid_numeric_name, // Usa la nuova variabile
    'is_archived'        => !empty($card['closed']),
    'in_archived_list'   => isset($card['idList']) && in_array($card['idList'], $archived_list_ids),
    'last_synced_at'     => current_time('mysql')
];
$format = ['%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%d', '%s'];
$wpdb->replace($cards_cache_table, $data, $format);
                        $synced_cards_count++;
                    }
                }
            }
             echo '<div class="notice notice-info is-dismissible"><p>Sincronizzazione completata. Processate <strong>' . $synced_cards_count . '</strong> schede in totale (incluse le archiviate per aggiornamento stato).</p></div>';
        }
        update_option('stpa_last_trello_cards_sync', time());
    }

    if (function_exists('stpa_display_dashboard')) {
        stpa_display_dashboard();
    } else {
        echo '<div class="notice notice-error"><p><strong>Errore critico:</strong> Il modulo di analisi predittiva non è stato trovato.</p></div>';
    }
    echo '</div>';
}

function wp_trello_plugin_page_callback() {
    echo '<div class="uk-container uk-container-expand uk-margin-top">';

    $upload_dir = wp_upload_dir();
    $cache_file_path = $upload_dir['basedir'] . '/trello_data_cache.json';
    $cache_duration = 1 * HOUR_IN_SECONDS;
    $force_refresh = isset($_GET['force_refresh']) && $_GET['force_refresh'] === 'true';

    if ($force_refresh && file_exists($cache_file_path)) {
        unlink($cache_file_path);
        echo '<script>window.location.href = "' . esc_url(remove_query_arg('force_refresh')) . '";</script>';
        exit;
    }

    $cached_data = null;
    if (file_exists($cache_file_path) && (time() - filemtime($cache_file_path) < $cache_duration)) {
        $cached_data = json_decode(file_get_contents($cache_file_path), true);
    }

    // Ripristino avviso cache con classi UIkit
    echo '<div class="uk-alert-primary" uk-alert style="margin-bottom: 20px;">';
    echo '<a class="uk-alert-close" uk-close></a>';
    if ($cached_data) {
        $time_remaining = round(($cache_duration - (time() - filemtime($cache_file_path))) / 60);
        echo '<p>I dati mostrati sono in cache. Prossimo aggiornamento automatico tra circa ' . esc_html($time_remaining) . ' minuti.</p>';
    } else {
        echo '<p>Nessun dato in cache o cache scaduta. I dati verranno recuperati ora da Trello.</p>';
    }
    echo '<a href="' . esc_url(add_query_arg('force_refresh', 'true')) . '" class="uk-button uk-button-secondary uk-button-small">Forza Aggiornamento Dati</a>';
    echo '</div>';
    
    if ($cached_data) {
        $all_cards_from_target_boards = $cached_data['cards'];
        $board_provenance_ids_map = $cached_data['provenance_map'];
    } else {
        $apiKey = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : '';
        $apiToken = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : '';

        if (empty($apiKey) || empty($apiToken)) {
            echo '<div class="uk-alert-danger"><p>Errore: Le API Key e Token di Trello non sono configurate.</p></div>';
            return;
        }

        $all_user_boards = get_trello_boards($apiKey, $apiToken);
        $targetBoardIds = array_filter(array_map(function($board) { return $board['id']; }, is_array($all_user_boards) ? $all_user_boards : []));

        if (empty($targetBoardIds)) {
            echo '<div class="uk-alert-danger"><p>Errore: Nessuna board Trello trovata.</p></div>';
            return;
        }

        $board_provenance_ids_map = wp_trello_get_provenance_field_ids_map($targetBoardIds, $apiKey, $apiToken);
        $all_cards_from_target_boards = [];
        foreach ($targetBoardIds as $boardId) {
            if (empty($boardId)) continue;
            $cards_from_single_board = get_trello_cards($boardId, $apiKey, $apiToken);
            if(is_array($cards_from_single_board)) {
                $all_cards_from_target_boards = array_merge($all_cards_from_target_boards, $cards_from_single_board);
            }
        }
        
        file_put_contents($cache_file_path, json_encode([
            'cards' => $all_cards_from_target_boards,
            'provenance_map' => $board_provenance_ids_map
        ]));
    }

    $base_cards_for_all_filtering = wp_trello_filter_cards_by_numeric_name($all_cards_from_target_boards);
    $filtered_trello_cards_for_charts_and_table = wp_trello_apply_filters_to_cards($base_cards_for_all_filtering, $board_provenance_ids_map);
    $marketing_data_from_csv = function_exists('wp_trello_get_marketing_data_with_leads_costs_v19') ? wp_trello_get_marketing_data_with_leads_costs_v19() : null;

    // --- RIPRISTINO STRUTTURA A GRIGLIA UIKIT ---
    echo '<div uk-grid class="uk-grid-large">';
    
    // Colonna Filtri (la larghezza originale era 1-5)
    echo '<div class="uk-width-1-5@m">';
    echo '<h2>Filtri Dati</h2>';
    generate_combined_filters($base_cards_for_all_filtering, $board_provenance_ids_map);
    echo '</div>';

    // Colonna Contenuto Principale
    echo '<div class="uk-width-4-5@m">';
    echo '<h2>Analisi Performance</h2>';
    if (function_exists('wp_trello_display_combined_performance_charts')) {
        wp_trello_display_combined_performance_charts($filtered_trello_cards_for_charts_and_table, $marketing_data_from_csv, $board_provenance_ids_map);
    }

    echo '<hr class="uk-divider-icon uk-margin-medium-top uk-margin-medium-bottom">';
    echo '<h2>Dettaglio Schede Trello Filtrate</h2>';
    $dateForStats = isset($_GET['date_from']) && !empty($_GET['date_from']) ? sanitize_text_field($_GET['date_from']) : date('Y-m-d');
    $daily_stats_data = wp_trello_calculate_daily_statistics($filtered_trello_cards_for_charts_and_table, $board_provenance_ids_map, $dateForStats);
    generate_filtered_table($filtered_trello_cards_for_charts_and_table, $board_provenance_ids_map, $daily_stats_data, $dateForStats);
    
    echo '</div>'; // Fine colonna contenuto
    echo '</div>'; // Fine grid
    echo '</div>'; // Fine container
}

if (!function_exists('wp_trello_plugin_enqueue_styles')) {
    function wp_trello_plugin_enqueue_styles($hook_suffix){
        $plugin_pages = [
            'toplevel_page_wp-trello-plugin',
            'statistiche-avanzate_page_wp-trello-merger',
            'statistiche-avanzate_page_stpa-predictive-analysis',
            'statistiche-avanzate_page_stsg-sheet-generator',
            'statistiche-avanzate_page_wtqsg-quotes-sheet',
            'statistiche-avanzate_page_stma-marketing-advisor',
            'statistiche-avanzate_page_stsg-email-reporter'
        ];

        if(!in_array($hook_suffix, $plugin_pages)) {
            return;
        }
        
        // --- RIPRISTINO UIKIT ---
        wp_enqueue_style('uikit-css','https://cdn.jsdelivr.net/npm/uikit@3.19.2/dist/css/uikit.min.css',[],'3.19.2');
        wp_enqueue_script('uikit-js','https://cdn.jsdelivr.net/npm/uikit@3.19.2/dist/js/uikit.min.js',['jquery'],'3.19.2',true);
        wp_enqueue_script('chart-js','https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js',[],'3.9.1',true);
        // Il file style.css personalizzato non è più necessario
    }
}
add_action('admin_enqueue_scripts','wp_trello_plugin_enqueue_styles');


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

