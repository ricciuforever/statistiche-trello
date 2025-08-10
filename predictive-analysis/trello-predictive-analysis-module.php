<?php
/**
 * Modulo Consolidato di Analisi Predittiva Trello - v4.11 (Final Bugfixes)
 */

if (!defined('ABSPATH')) exit;

// --- Configurazione Modulo ---
define('STPA_BENCHMARK_LIST_ID', '6784f2acf6b39ee930d0aa0e');
define('STPA_ALLOWED_ANALYSIS_BOARDS', [
    '67150ff7fd841a4fd89c3171', // 03_GESTIONE DEL CLIENTE
    '671629a403fdffe3d16182c0', // 04_CALLCENTER CLIENTI
    '67473c1045d9c0ff2bb67eab', // 04B_CallCenter Clienti
    '67473c25b76cff31362d2e57'  // 04C_CallCenter Clienti
]);

define('STPA_CACHE_DURATION', 2 * HOUR_IN_SECONDS);
$GLOBALS['stpa_analysis_periods'] = [30, 60, 90, 120, 150, 180];
$GLOBALS['stpa_probability_thresholds'] = ['high' => 0.7, 'medium' => 0.4, 'low' => 0.4];

// --- Logica AJAX ---
add_action('wp_ajax_stpa_predictive_analysis', 'stpa_ajax_handler');

function stpa_ajax_handler() {
    if (!check_ajax_referer('stpa_nonce', 'nonce', false) || !current_user_can('manage_options')) {
        wp_send_json_error('Autorizzazione fallita.', 403);
    }
    $action_type = isset($_POST['action_type']) ? sanitize_text_field($_POST['action_type']) : '';
    try {
        switch ($action_type) {
            case 'start_background_analysis': stpa_ajax_start_background_analysis(); break;
            case 'get_background_status': stpa_ajax_get_background_status(); break;
            case 'clear_previous_analysis': stpa_ajax_clear_previous_analysis(); break;
            case 'test_benchmark_list': stpa_ajax_test_benchmark_list(); break;
            default: wp_send_json_error('Azione non riconosciuta.');
        }
    } catch (Exception $e) {
        wp_send_json_error('Errore imprevisto: ' . $e->getMessage());
    }
}

function stpa_ajax_test_benchmark_list() {
    $list_id = STPA_BENCHMARK_LIST_ID;
    $api_key = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : '';
    $api_token = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : '';

    if(empty($api_key) || empty($api_token)) {
        wp_send_json_error(['message' => 'Le costanti TRELLO_API_KEY o TRELLO_API_TOKEN sono vuote in wp-config.php.']);
        return;
    }

    $url = "https://api.trello.com/1/lists/{$list_id}?fields=name,idBoard&key={$api_key}&token={$api_token}";
    $response = wp_remote_get($url, ['timeout' => 20]);

    $result = [];
    $result['url_chiamato'] = $url;

    if (is_wp_error($response)) {
        $result['codice_risposta'] = 'WP_Error';
        $result['corpo_risposta'] = $response->get_error_message();
    } else {
        $result['codice_risposta'] = wp_remote_retrieve_response_code($response);
        $result['corpo_risposta'] = wp_remote_retrieve_body($response);
    }
    wp_send_json_success($result);
}

function stpa_ajax_start_background_analysis() {
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permessi insufficienti.');
        return;
    }

    global $wpdb;
    $cards_cache_table = STPA_CARDS_CACHE_TABLE;
    $queue_table = STPA_QUEUE_TABLE;

    $max_cards_from_frontend = isset($_POST['max_cards_for_analysis']) ? intval($_POST['max_cards_for_analysis']) : 1000;

    $allowed_board_ids = STPA_ALLOWED_ANALYSIS_BOARDS;
    if (empty($allowed_board_ids)) {
        wp_send_json_error('Nessuna board consentita per l\'analisi.');
        return;
    }

    $board_ids_placeholder = implode(', ', array_fill(0, count($allowed_board_ids), '%s'));
    $query = $wpdb->prepare(
        "SELECT card_id FROM $cards_cache_table WHERE is_numeric_name = TRUE AND board_id IN ($board_ids_placeholder) ORDER BY date_last_activity DESC LIMIT %d",
        array_merge($allowed_board_ids, [$max_cards_from_frontend])
    );
    $pre_selected_card_ids = $wpdb->get_col($query);

    if (empty($pre_selected_card_ids)) {
        wp_send_json_error('Nessuna scheda valida trovata per l\'analisi.');
        return;
    }

    $benchmark_cards = stpa_get_cards_from_list(STPA_BENCHMARK_LIST_ID);
    $benchmark_text = '';
    if (!is_wp_error($benchmark_cards) && !empty($benchmark_cards)) {
        foreach ($benchmark_cards as $card) {
            if (!empty($card['desc'])) {
                $benchmark_text .= $card['desc'] . "\n\n---\n\n";
            }
        }
    }

    $analysis_id = 'analysis_' . time();
    $total_cards_to_process = count($pre_selected_card_ids);

    $values = [];
    $place_holders = [];
    $insert_query = "INSERT INTO $queue_table (analysis_id, card_id, status) VALUES ";
    foreach ($pre_selected_card_ids as $card_id) {
        $values[] = $analysis_id;
        $values[] = $card_id;
        $values[] = 'pending';
        $place_holders[] = "(%s, %s, %s)";
    }
    $insert_query .= implode(', ', $place_holders);
    $wpdb->query($wpdb->prepare($insert_query, $values));

    wp_clear_scheduled_hook(STPA_CRON_EVENT);

    $queue_meta = [
        'analysis_id'       => $analysis_id,
        'status'            => 'running',
        'periods'           => $GLOBALS['stpa_analysis_periods'],
        'total_cards'       => $total_cards_to_process,
        'started_at'        => current_time('mysql'),
        'finished_at'       => null,
        'benchmark_text'    => $benchmark_text,
    ];

    update_option(STPA_QUEUE_OPTION, $queue_meta);

    wp_schedule_event(time(), 'stpa_one_minute', STPA_CRON_EVENT);

    wp_send_json_success([
        'message' => 'Processo avviato per ' . $total_cards_to_process . ' schede.',
        'analysis_id' => $analysis_id,
        'total_cards' => $total_cards_to_process,
    ]);
}

function stpa_ajax_get_background_status() {
    global $wpdb;
    $queue_meta = get_option(STPA_QUEUE_OPTION, false);
    $results_to_send = [];

    if (!$queue_meta) {
        wp_send_json_success(['status' => 'idle', 'results' => $results_to_send]);
        return;
    }

    if ($queue_meta['status'] === 'running' && !wp_next_scheduled(STPA_CRON_EVENT)) {
        $queue_meta['status'] = 'stalled';
        update_option(STPA_QUEUE_OPTION, $queue_meta);
    }

    $analysis_id = $queue_meta['analysis_id'];
    $queue_table = STPA_QUEUE_TABLE;

    $processed_cards = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(id) FROM $queue_table WHERE analysis_id = %s AND status = 'completed'", $analysis_id));
    $queue_meta['processed_cards'] = $processed_cards;

    // **BUGFIX**: Recalculate chunk info and add to the response for the UI.
    $batch_size = 100;
    $queue_meta['total_chunks'] = !empty($queue_meta['total_cards']) ? ceil($queue_meta['total_cards'] / $batch_size) : 0;
    $queue_meta['processed_chunks'] = !empty($processed_cards) ? floor($processed_cards / $batch_size) : 0;

    if (in_array($queue_meta['status'], ['running', 'completed', 'completed_with_errors', 'stalled'])) {
        $table_name = STPA_RESULTS_TABLE;
        $db_results = $wpdb->get_results($wpdb->prepare("SELECT * FROM $table_name WHERE analysis_id = %s ORDER BY probability DESC", $analysis_id), ARRAY_A);
        foreach ($db_results as $row) {
            $results_to_send[$row['period']][] = $row;
        }
    }

    $queue_meta['results'] = $results_to_send;
    wp_send_json_success($queue_meta);
}

function stpa_ajax_clear_previous_analysis() {
    global $wpdb;
    $results_table = STPA_RESULTS_TABLE;
    $queue_table = STPA_QUEUE_TABLE;
    $analysis_id = isset($_POST['analysis_id']) ? sanitize_text_field($_POST['analysis_id']) : '';

    if (empty($analysis_id)) {
        wp_send_json_error('ID Analisi non fornito.');
    }

    $wpdb->delete($results_table, ['analysis_id' => $analysis_id]);
    $wpdb->delete($queue_table, ['analysis_id' => $analysis_id]);

    delete_option(STPA_QUEUE_OPTION);
    wp_clear_scheduled_hook(STPA_CRON_EVENT);

    wp_send_json_success(['message' => 'Dati analisi precedente e coda ripuliti.']);
}

// --- Funzioni di Supporto ---
function stpa_get_cards_from_list($list_id) {
    $api_key = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : '';
    $api_token = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : '';

    if (empty($api_key) || empty($api_token)) {
        return new WP_Error('api_keys_missing', 'Trello API keys are not defined.');
    }

    $url = "https://api.trello.com/1/lists/{$list_id}/cards?fields=name,desc";
    $url = add_query_arg(['key' => $api_key, 'token' => $api_token], $url);

    $response = wp_remote_get($url, ['timeout' => 20]);

    if (is_wp_error($response)) return $response;

    $body = wp_remote_retrieve_body($response);
    $cards = json_decode($body, true);

    if (json_last_error() !== JSON_ERROR_NONE) return new WP_Error('json_error', 'Failed to decode Trello API response.');

    return $cards;
}

function stpa_get_card_details($card_id) {
    $cache_key = 'stpa_card_details_' . $card_id;
    $cached = get_transient($cache_key);
    if ($cached !== false) return $cached;

    $api_key = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : '';
    $api_token = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : '';

    $url = "https://api.trello.com/1/cards/{$card_id}?fields=name,url&key=" . $api_key . "&token=" . $api_token;
    $response = wp_remote_get($url, ['timeout' => 10]);
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return stpa_generate_fallback_details($card_id);

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (empty($data) || !isset($data['name'])) return stpa_generate_fallback_details($card_id);

    $details = ['name' => sanitize_text_field($data['name']), 'url'  => esc_url_raw($data['url'] ?? "https://trello.com/c/{$card_id}")];
    set_transient($cache_key, $details, STPA_CACHE_DURATION);
    return $details;
}

function stpa_generate_fallback_details($card_id) {
    global $wpdb;
    $table_name = STPA_CARDS_CACHE_TABLE;
    $cached_card = $wpdb->get_row($wpdb->prepare("SELECT card_name, card_url FROM $table_name WHERE card_id = %s", $card_id), ARRAY_A);

    if ($cached_card) {
        return ['name' => $cached_card['card_name'], 'url' => $cached_card['card_url']];
    } else {
        return ['name' => 'Scheda ' . $card_id . ' (dettagli non disponibili)', 'url' => "https://trello.com/c/{$card_id}"];
    }
}

// --- Sezione Display e Enqueue Scripts ---
add_action('admin_enqueue_scripts', 'stpa_enqueue_dashboard_assets');
function stpa_enqueue_dashboard_assets($hook) {
    if (strpos($hook, 'stpa-predictive-analysis') === false) {
        return;
    }

    $plugin_dir_url = plugin_dir_url(__FILE__);

    wp_enqueue_style('stpa-styles', $plugin_dir_url . 'css/stpa-styles.css', [], '1.1.0');
    wp_enqueue_script('stpa-scripts', $plugin_dir_url . 'js/stpa-scripts.js', [], '1.1.0', true);

    // **BUGFIX**: Usa wp_add_inline_script per passare dati, metodo moderno e corretto.
    $last_analysis_status = get_option(STPA_QUEUE_OPTION, ['status' => 'idle', 'analysis_id' => '']);
    $data_for_js = [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('stpa_nonce'),
        'thresholds' => $GLOBALS['stpa_probability_thresholds'],
        'periods' => $GLOBALS['stpa_analysis_periods'],
        'last_analysis_status' => $last_analysis_status
    ];
    wp_add_inline_script('stpa-scripts', 'const stpa_data = ' . json_encode($data_for_js) . ';', 'before');
}


function stpa_display_dashboard() {
    $periods = $GLOBALS['stpa_analysis_periods'];
    ?>
    <div class="stpa-dashboard">
        <div class="stpa-header">
            <h2>🔮 Analisi Predittiva Trello con OpenAI (ChatGPT)</h2>
            <p>Avvia un'analisi in background per stimare la probabilità di chiusura dei contatti.</p>
        </div>
        <div id="stpa-status-container" class="stpa-status-container"></div>
        <div id="stpa-controls" class="stpa-controls"></div>
        <div id="stpa-results-container" style="display: block;">
            <div class="stpa-results-header"><h3>📈 Risultati Ultima Analisi</h3></div>
            <div class="stpa-tab-nav">
                <?php foreach ($periods as $index => $period): ?>
                    <button class="stpa-tab-button <?php echo $index === 0 ? 'active' : ''; ?>" data-period="<?php echo esc_attr($period); ?>">
                        <span class="stpa-tab-period"><?php echo esc_html($period); ?> Giorni</span>
                        <span class="stpa-tab-stats" id="stpa-tab-stats-<?php echo esc_attr($period); ?>"></span>
                    </button>
                <?php endforeach; ?>
            </div>
            <div id="stpa-tab-content"></div>
        </div>
    </div>
    <?php
}

function stpa_get_card_comments_cached($card_id) {
    global $wpdb;
    $table_name = STPA_COMMENTS_CACHE_TABLE;
    $cache_duration = 6 * HOUR_IN_SECONDS;

    $cached_comments_row = $wpdb->get_row($wpdb->prepare("SELECT comments_data_json, last_fetched_at, etag FROM $table_name WHERE card_id = %s", $card_id), ARRAY_A);
    $last_fetched_at = isset($cached_comments_row['last_fetched_at']) ? strtotime($cached_comments_row['last_fetched_at']) : 0;
    $cached_comments = isset($cached_comments_row['comments_data_json']) ? json_decode($cached_comments_row['comments_data_json'], true) : [];
    $cached_etag = isset($cached_comments_row['etag']) ? $cached_comments_row['etag'] : '';
    $should_fetch = !($cached_comments_row && (time() - $last_fetched_at < $cache_duration));
    $comments = $cached_comments;

    if ($should_fetch) {
        $api_key = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : '';
        $api_token = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : '';
        $comments_url = "https://api.trello.com/1/cards/{$card_id}/actions?filter=commentCard&key=" . $api_key . "&token=" . $api_token;

        $args = ['timeout' => 15];
        if (!empty($cached_etag)) {
            $args['headers'] = ['If-None-Match' => $cached_etag];
        }

        $response = wp_remote_get($comments_url, $args);

        if (is_wp_error($response)) {
            error_log('[Trello Analysis] Errore recupero commenti (API Trello) per card ' . $card_id . ': ' . $response->get_error_message());
            return ['comments' => $comments, 'fetched' => false, 'error' => true];
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $new_etag = wp_remote_retrieve_header($response, 'etag');

        if ($status_code === 304) {
            $wpdb->update($table_name, ['last_fetched_at' => current_time('mysql')], ['card_id' => $card_id]);
            return ['comments' => $cached_comments, 'fetched' => false, 'error' => false];
        } elseif ($status_code === 200) {
            $actions = json_decode(wp_remote_retrieve_body($response), true);
            $current_comments = [];
            if (is_array($actions)) {
                $count = 0;
                foreach ($actions as $action) {
                    if ($count >= 5) break;
                    if (isset($action['data']['text'])) {
                        $current_comments[] = ['date' => $action['date'], 'text' => $action['data']['text']];
                        $count++;
                    }
                }
            }
            $comments = $current_comments;

            $data_to_save = [
                'comments_data_json' => json_encode($comments),
                'last_fetched_at' => current_time('mysql'),
                'etag' => $new_etag
            ];
            if ($cached_comments_row) {
                $wpdb->update($table_name, $data_to_save, ['card_id' => $card_id]);
            } else {
                $data_to_save['card_id'] = $card_id;
                $wpdb->insert($table_name, $data_to_save);
            }
            return ['comments' => $comments, 'fetched' => true, 'error' => false];
        } else {
            error_log('[Trello Analysis] Errore HTTP ' . $status_code . ' recupero commenti per card ' . $card_id . ': ' . wp_remote_retrieve_body($response));
            return ['comments' => $comments, 'fetched' => false, 'error' => true];
        }
    }
    return ['comments' => $comments, 'fetched' => false, 'error' => false];
}

function recupera_ultima_analisi_completata() {
    if (!is_admin() || !current_user_can('manage_options')) return;
    if (get_option('stpa_recupero_eseguito')) return;

    global $wpdb;
    $table_name = $wpdb->prefix . 'stpa_predictive_results';
    $latest_analysis_id = $wpdb->get_var("SELECT analysis_id FROM {$table_name} ORDER BY created_at DESC LIMIT 1");

    if ($latest_analysis_id) {
        update_option('stpa_last_completed_analysis_id', $latest_analysis_id);
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Recupero Analisi Trello:</strong> Trovata e impostata l\'ultima analisi completata.</p></div>';
        });
        update_option('stpa_recupero_eseguito', true);
    } else {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>Recupero Analisi Trello:</strong> Nessun risultato trovato nel database.</p></div>';
        });
    }
}
add_action('admin_init', 'recupera_ultima_analisi_completata');
