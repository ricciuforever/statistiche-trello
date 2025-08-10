<?php
/**
 * Modulo Consolidato di Analisi Predittiva Trello - v4.4 (Credentials Fix)
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

// NOTA: Non definiamo più STPA_API_KEY qui, usiamo direttamente TRELLO_API_KEY da wp-config.php

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
    // Il nonce è già controllato in stpa_ajax_handler, ma è buona norma ricontrollare se questa funzione può essere chiamata direttamente.
    // check_ajax_referer('stpa_nonce', 'nonce'); // Se la chiamata arriva direttamente, decommenta.
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Permessi insufficienti.');
        return; // Aggiungi il return qui
    }

    global $wpdb;
    $cards_cache_table = STPA_CARDS_CACHE_TABLE; // Definito in trello-background-processor.php

    // PARAMETRI RICEVUTI DA FRONTEND (per la Top X e batch size)
    $max_cards_from_frontend = isset($_POST['max_cards_for_analysis']) ? intval($_POST['max_cards_for_analysis']) : 1000;
    
    // MODIFICA QUI: Imposta il batch_size fisso a 100 come discusso
    $batch_size = 100;

    // Ottieni gli ID delle board consentite
    $allowed_board_ids = STPA_ALLOWED_ANALYSIS_BOARDS;
    if (empty($allowed_board_ids)) {
        wp_send_json_error('Nessuna board consentita configurata per l\'analisi predittiva.');
        return;
    }

    // Prepara la stringa per la clausola IN() nella query SQL
    // 'id' è il nome della colonna in STPA_ALLOWED_ANALYSIS_BOARDS per l'ID della board
    $board_ids_placeholder = implode(', ', array_fill(0, count($allowed_board_ids), '%s'));

    // Recupera le schede dalla cache locale del DB per la pre-selezione
    // Ordina per data di ultima attività (più recenti), filtra per nome numerico E PER LE BOARD CONSENTITE
    $query = $wpdb->prepare(
        "SELECT card_id FROM $cards_cache_table WHERE is_numeric_name = TRUE AND board_id IN ($board_ids_placeholder) ORDER BY date_last_activity DESC LIMIT %d",
        array_merge($allowed_board_ids, [$max_cards_from_frontend])
    );
    $pre_selected_card_ids = $wpdb->get_col($query); // Usa get_col per ottenere solo l'array di ID

    if (empty($pre_selected_card_ids)) {
        wp_send_json_error('Nessuna scheda selezionata per l\'analisi approfondita dal database. Assicurati di aver sincronizzato le schede Trello e che ci siano schede con nome numerico nelle board consentite.');
        return;
    }

    // Pulisce eventuali job precedenti non completati per questa analisi
    wp_clear_scheduled_hook(STPA_CRON_EVENT);

    $analysis_id = 'analysis_' . time(); 
    $total_cards_to_process = count($pre_selected_card_ids); // Numero effettivo di schede selezionate
    
    $queue = [
        'analysis_id'       => $analysis_id, 
        'status'            => 'running', 
        'periods'           => $GLOBALS['stpa_analysis_periods'], 
        'total_cards'       => $total_cards_to_process, 
        'batch_size'        => $batch_size, 
        'total_chunks'      => ceil($total_cards_to_process / $batch_size), 
        'processed_chunks'  => 0, 
        'processed_cards'   => 0, 
        'started_at'        => current_time('mysql'), 
        'finished_at'       => null, 
        'card_ids_chunks'   => array_chunk($pre_selected_card_ids, $batch_size), // Chunk solo delle pre-selezionate dal DB
    ];
    
    update_option(STPA_QUEUE_OPTION, $queue); 
    
    // Programma l'evento per essere eseguito immediatamente la prima volta e poi ogni minuto
    wp_schedule_event(time(), 'stpa_one_minute', STPA_CRON_EVENT); 

    wp_send_json_success([ 
        'message' => 'Processo in background avviato per ' . $total_cards_to_process . ' schede selezionate dal database.', 
        'analysis_id' => $analysis_id,
        'total_cards' => $total_cards_to_process,
        'total_batches' => $queue['total_chunks']
    ]);
}

function stpa_ajax_get_background_status() {
    global $wpdb;
    $queue = get_option(STPA_QUEUE_OPTION, false);
    $results_to_send = []; // Array per i risultati da inviare al frontend

    if (!$queue) {
        wp_send_json_success(['status' => 'idle', 'results' => $results_to_send]); // Nessun processo attivo
        return;
    }
    
    // Se il cron non sta più girando ma lo stato non è 'completed', segnala come bloccato.
    if ($queue['status'] === 'running' && !wp_next_scheduled(STPA_CRON_EVENT)) {
        $queue['status'] = 'stalled';
        update_option(STPA_QUEUE_OPTION, $queue); // Aggiorna lo stato nella coda
    }

    // --- NUOVA LOGICA: Recupera i risultati dal DB se c'è un'analisi in corso o completata ---
    if ($queue['status'] === 'running' || $queue['status'] === 'completed' || $queue['status'] === 'completed_with_errors' || $queue['status'] === 'stalled') {
        $table_name = STPA_RESULTS_TABLE;
        $current_analysis_id = $queue['analysis_id'];

        // Recupera TUTTI i risultati per l'analisi corrente
        $db_results = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table_name WHERE analysis_id = %s ORDER BY probability DESC", $current_analysis_id),
            ARRAY_A
        );
        
        // Organizza i risultati per periodo, come nel display della dashboard
        foreach ($db_results as $row) {
            $results_to_send[$row['period']][] = $row;
        }
    }
    // --- FINE NUOVA LOGICA ---

    // Aggiungi i risultati recuperati all'array di risposta
    $queue['results'] = $results_to_send;

    wp_send_json_success($queue);
}

function stpa_ajax_clear_previous_analysis() {
    global $wpdb;
    $table_name = STPA_RESULTS_TABLE;
    $analysis_id = isset($_POST['analysis_id']) ? sanitize_text_field($_POST['analysis_id']) : '';

    if (empty($analysis_id)) {
        wp_send_json_error('ID Analisi non fornito.');
    }

    $wpdb->delete($table_name, ['analysis_id' => $analysis_id]);
    delete_option(STPA_QUEUE_OPTION);
    wp_clear_scheduled_hook(STPA_CRON_EVENT);
    
    wp_send_json_success(['message' => 'Dati analisi precedente e coda ripuliti.']);
}

// --- Funzioni di Supporto (API, Fallback, etc.) ---
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
    // Implementa una logica di fallback se i dettagli della scheda non possono essere recuperati
    // Potrebbe essere recuperare dalla cache del DB se disponibile, o fornire un placeholder
    global $wpdb;
    $table_name = STPA_CARDS_CACHE_TABLE;
    $cached_card = $wpdb->get_row($wpdb->prepare("SELECT card_name, card_url FROM $table_name WHERE card_id = %s", $card_id), ARRAY_A);

    if ($cached_card) {
        return ['name' => $cached_card['card_name'], 'url' => $cached_card['card_url']];
    } else {
        return ['name' => 'Scheda ' . $card_id . ' (dettagli non disponibili)', 'url' => "https://trello.com/c/{$card_id}"];
    }
}


// --- Sezione Display (HTML, CSS, JavaScript) ---
add_action('admin_footer', 'stpa_add_admin_footer_script');
function stpa_add_admin_footer_script() {
    $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
    if ($current_page === 'stpa-predictive-analysis') {
        ?><script type="text/javascript">const stpa_ajax = { url: '<?php echo esc_url(admin_url('admin-ajax.php')); ?>', nonce: '<?php echo esc_js(wp_create_nonce('stpa_nonce')); ?>' };</script><?php
    }
}

function stpa_display_dashboard() {
    global $wpdb;
    $periods = $GLOBALS['stpa_analysis_periods'];
    $thresholds = $GLOBALS['stpa_probability_thresholds'];

    // Carica i risultati dell'ultima analisi COMPLETA con successo
    $last_successfully_completed_analysis_id = get_option('stpa_last_completed_analysis_id', '');
    $results_to_display = [];

    if (!empty($last_successfully_completed_analysis_id)) {
        $table_name = STPA_RESULTS_TABLE;
        $db_results = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM $table_name WHERE analysis_id = %s ORDER BY probability DESC", $last_successfully_completed_analysis_id),
            ARRAY_A
        );
        foreach ($db_results as $row) {
            $results_to_display[$row['period']][] = $row;
        }
    }

    // Inizializza la variabile $last_analysis_status qui
    $last_analysis_status = get_option(STPA_QUEUE_OPTION, ['status' => 'idle', 'analysis_id' => '']);

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

    <style>/* Stili CSS compressi per brevità */
    .stpa-dashboard{background:#fff;border:1px solid #e0e0e0;border-radius:8px;padding:25px;margin:20px 0}.stpa-header{text-align:center;margin-bottom:30px;border-bottom:1px solid #eee;padding-bottom:20px}.stpa-header h2{color:#2c3e50}.stpa-header p{color:#7f8c8d;font-size:16px}.stpa-status-container{padding:20px;border-radius:8px;margin-bottom:20px;border:1px solid #eee}.stpa-status-idle{background-color:#f0f8ff}.stpa-status-running{background-color:#fffbe6}.stpa-status-completed{background-color:#f0fff0}.stpa-status-stalled{background-color:#fff0f0}.stpa-controls{display:flex;gap:20px;align-items:flex-end;margin-bottom:30px}.stpa-control-group{display:flex;flex-direction:column}.stpa-button-primary{background:#3498db;color:white;border:none;padding:10px 20px;border-radius:4px;cursor:pointer}.stpa-button-secondary{background:#e74c3c;color:white;border:none;padding:10px 20px;border-radius:4px;cursor:pointer}.stpa-progress-bar{width:100%;background:#ecf0f1;border-radius:10px;overflow:hidden;height:20px;margin-top:10px}.stpa-progress-fill{width:0%;height:100%;background:linear-gradient(90deg,#2ecc71,#27ae60);transition:width .4s ease}.stpa-results-header{border-bottom:1px solid #eee;padding-bottom:15px;margin-bottom:20px}.stpa-tab-nav{display:flex;border-bottom:1px solid #e0e0e0;margin-bottom:20px}.stpa-tab-button{background:0 0;border:none;padding:15px 20px;cursor:pointer;border-bottom:3px solid transparent}.stpa-tab-button.active{border-bottom-color:#3498db;color:#3498db;font-weight:600}.stpa-tab-period{display:block;font-size:14px}.stpa-tab-stats{display:block;font-size:12px;color:#7f8c8d;margin-top:4px}.stpa-stats-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-bottom:30px}.stpa-stat-card{padding:20px;border-radius:8px;text-align:center;color:#fff}.stpa-stat-card.high{background:#2ecc71}.stpa-stat-card.medium{background:#f1c40f}.stpa-stat-card.low{background:#e74c3c}.stpa-stat-number{font-size:28px;font-weight:700}.stpa-results-table{width:100%;border-collapse:collapse}.stpa-results-table th,.stpa-results-table td{padding:12px;text-align:left;border-bottom:1px solid #eee}.stpa-results-table th{background:#f9f9f9}.stpa-card-name{color:#3498db;text-decoration:none;font-weight:500}.stpa-probability.high{color:#27ae60}.stpa-probability.medium{color:#f39c12}.stpa-probability.low{color:#c0392b}
    </style>

    <script type="text/javascript">
    document.addEventListener('DOMContentLoaded', function() {
        const statusContainer = document.getElementById('stpa-status-container');
        const controlsContainer = document.getElementById('stpa-controls');
        const resultsContainer = document.getElementById('stpa-results-container');
        const tabNav = document.querySelector('.stpa-tab-nav');
        const tabContent = document.getElementById('stpa-tab-content');
        
        const thresholds = <?php echo json_encode($thresholds); ?>;
        let statusInterval = null;
        
        let lastAnalysisId = "<?php echo esc_js($last_analysis_status['analysis_id'] ?? ''); ?>";
        let currentResults = {}; // Variabile per memorizzare i risultati correnti

        function renderControls(status) {
            let html = '';
            if (status.status === 'idle' || status.status === 'completed' || status.status === 'stalled' || status.status === 'completed_with_errors') {
                html = `
                    <div class="stpa-control-group">
                        <label>Schede per Batch:</label>
                        <input type="text" id="stpa-batch-size-display" value="100 (Fisso)" disabled class="uk-input">
                        <input type="hidden" id="stpa-batch-size" value="100">
                    </div>
                    <div class="stpa-control-group">
                        <label>Limite Schede (0=tutte):</label>
                        <input type="number" id="stpa-max-cards" value="3000" min="0" step="100">
                    </div>
                    <button id="stpa-start-btn" class="stpa-button-primary">🚀 Avvia Nuova Analisi</button>
                `;
                if(status.status === 'completed' || status.status === 'stalled' || status.status === 'completed_with_errors') {
                    html += `<button id="stpa-clear-btn" class="stpa-button-secondary">🗑️ Pulisci Risultati</button>`;
                }
            } else if (status.status === 'running') {
                html = `
                    <p>Un\'analisi è in corso. Puoi interromperla qui:</p>
                    <button id="stpa-stop-btn" class="stpa-button-secondary">⏹️ Ferma Analisi</button>
                    <button id="stpa-clear-btn" class="stpa-button-secondary">🗑️ Pulisci Risultati</button> `;
            }
            controlsContainer.innerHTML = html;
        }

        function renderStatus(status) {
    let html = '';
    let statusClass = `stpa-status-${status.status || 'idle'}`;
    statusContainer.className = `stpa-status-container ${statusClass}`;
    // La riga 'const logContentEl = document.getElementById('stpa-log-content');'
    // e tutta la logica di 'logMessage' e 'logContentEl.textContent' sono state rimosse.

    switch (status.status) {
        case 'running':
            const progress = status.total_chunks > 0 ? (status.processed_chunks / status.total_chunks) * 100 : 0;
            html = `
                <h4>Analisi in Corso...</h4>
                <p>Elaborazione di <strong>${status.total_cards}</strong> schede. Il processo continuerà in background anche se chiudi la pagina.</p>
                <p>Stato: ${status.processed_chunks} / ${status.total_chunks} batch elaborati (${status.processed_cards} schede).</p>
                <div class="stpa-progress-bar"><div class="stpa-progress-fill" style="width:${progress.toFixed(1)}%;"></div></div>
            `;
            break;
        case 'completed':
            html = `<h4>✅ Analisi Completata</h4><p>Ultima analisi terminata il ${new Date(status.finished_at).toLocaleString('it-IT')}. Sono state elaborate ${status.processed_cards} schede. I risultati sono mostrati di seguito.</p>`;
            break;
        case 'completed_with_errors':
            html = `<h4>⚠️ Analisi Terminata con Errori</h4><p>Il processo è terminato ma non ha prodotto risultati. Controlla la tua <strong>chiave API di OpenAI (ChatGPT)</strong> in <code>wp-config.php</code> o i permessi del tuo account OpenAI (ChatGPT). Per dettagli, verifica il file <code>/wp-content/debug.log</code> sul server.</p>`;
            break;
        case 'stalled':
            html = `<h4>❌ Analisi Bloccata</h4><p>L'ultimo processo sembra bloccato. Puoi ripulire i risultati e avviarne uno nuovo.</p>`;
            break;
        default:
            html = `<h4>Nessuna Analisi in Corso</h4><p>Pronto per avviare una nuova analisi in background.</p>`;
    }
    statusContainer.innerHTML = html;
}
        
        async function checkStatus() {
            const response = await fetch(stpa_ajax.url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({action: 'stpa_predictive_analysis', action_type: 'get_background_status', nonce: stpa_ajax.nonce})
            });
            const status = await response.json();
            
            if (status.success) {
                renderStatus(status.data);
                renderControls(status.data);
                lastAnalysisId = status.data.analysis_id || lastAnalysisId;

                if (JSON.stringify(currentResults) !== JSON.stringify(status.data.results)) {
                    currentResults = status.data.results;
                    updateAllTabStats(currentResults); 
                    const activeTab = tabNav.querySelector('.stpa-tab-button.active');
                    if (activeTab) {
                        handleTabClick(parseInt(activeTab.dataset.period), false); 
                    } else {
                        const periods = <?php echo json_encode($periods); ?>;
                        if (periods.length > 0) {
                            handleTabClick(periods[0], false);
                        }
                    }
                }

                if (status.data.status !== 'running') {
                    clearInterval(statusInterval);
                    statusInterval = null;
                }
            }
        }

        document.addEventListener('click', async (e) => {
            if (e.target.id === 'stpa-start-btn') {
                e.target.disabled = true;
                e.target.textContent = 'Avvio...';
                
                const maxCardsForAnalysis = document.getElementById('stpa-max-cards').value;
                if (maxCardsForAnalysis <= 0) {
                    alert('Il limite di schede per l\'analisi deve essere maggiore di zero.');
                    e.target.disabled = false;
                    e.target.textContent = '🚀 Avvia Nuova Analisi';
                    return;
                }

                const formData = new FormData();
                formData.append('action', 'stpa_predictive_analysis');
                formData.append('action_type', 'start_background_analysis');
                formData.append('nonce', stpa_ajax.nonce);
                formData.append('max_cards_for_analysis', maxCardsForAnalysis);

                const response = await fetch(stpa_ajax.url, { method: 'POST', body: formData });
                const result = await response.json();
                if(result.success) {
                    lastAnalysisId = result.data.analysis_id;
                    statusInterval = setInterval(checkStatus, 5000);
                    checkStatus();
                } else {
                    alert('Errore: ' + result.data);
                    e.target.disabled = false;
                    e.target.textContent = '🚀 Avvia Nuova Analisi';
                }
            }
            if (e.target.id === 'stpa-clear-btn') {
                if (!confirm('Sei sicuro di voler cancellare i risultati dell\'ultima analisi? Questo interromperà anche qualsiasi analisi in corso.')) return;
                const response = await fetch(stpa_ajax.url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({action: 'stpa_predictive_analysis', action_type: 'clear_previous_analysis', nonce: stpa_ajax.nonce, analysis_id: lastAnalysisId})
                });
                const result = await response.json();
                if(result.success) location.reload();
            } else if (e.target.id === 'stpa-stop-btn') {
                if (!confirm('Sei sicuro di voler interrompere l\'analisi in corso? I risultati parziali non verranno salvati.')) return;
                const response = await fetch(stpa_ajax.url, {
                    method: 'POST',
                    headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                    body: new URLSearchParams({
                        action: 'stpa_predictive_analysis',
                        action_type: 'clear_previous_analysis',
                        nonce: stpa_ajax.nonce,
                        analysis_id: lastAnalysisId
                    })  
                });
                const result = await response.json();
                if (result.success) {
                    alert('Analisi interrotta con successo!');
                    location.reload();
                } else {
                    alert('Errore durante l\'interruzione dell\'analisi: ' + result.data);
                }
            }
            if (e.target.closest('.stpa-tab-button')) {
                const button = e.target.closest('.stpa-tab-button');
                handleTabClick(parseInt(button.dataset.period));
            }
        });

        function handleTabClick(period, updateOnly = true) { 
            tabNav.querySelectorAll('.stpa-tab-button').forEach(btn => btn.classList.remove('active'));
            const button = tabNav.querySelector(`.stpa-tab-button[data-period="${period}"]`);
            if (button) {
                button.classList.add('active');
            }
            renderTabContent(period);
        }

        function renderTabContent(period) {
            const results = currentResults[period] || []; 
            const stats = calculateStats(results);
            const tabContentEl = document.getElementById('stpa-tab-content');

            let tableRowsHtml = '';
            if (results.length === 0) {
                tableRowsHtml = `<tr><td colspan="4" style="text-align:center; padding: 20px;"><em>Nessun risultato da mostrare per questo periodo. Avvia una nuova analisi o attendi i progressi.</em></td></tr>`;
            } else {
                tableRowsHtml = results.map((r, i) => `
                    <tr>
                        <td>${i + 1}</td>
                        <td><a href="${r.card_url}" target="_blank" class="stpa-card-name">${r.card_name || 'Nome non disponibile'}</a></td>
                        <td class="stpa-probability ${getProbabilityClass(r.probability)}">${(parseFloat(r.probability) * 100).toFixed(1)}%</td>
                        <td><em>${r.reasoning || 'N/D'}</em></td>
                    </tr>`).join('');
            }

            tabContentEl.innerHTML = `
                <div class="stpa-stats-summary">
                    <div class="stpa-stat-card high"><h4>Alta Prob.</h4><div class="stpa-stat-number">${stats.high}</div></div>
                    <div class="stpa-stat-card medium"><h4>Media Prob.</h4><div class="stpa-stat-number">${stats.medium}</div></div>
                    <div class="stpa-stat-card low"><h4>Bassa Prob.</h4><div class="stpa-stat-number">${stats.low}</div></div>
                </div>
                <h4>Top ${results.length} Schede con Maggiore Probabilità</h4>
                <div class="stpa-results-table">
                    <table>
                        <thead><tr><th>Pos.</th><th>Nome Scheda</th><th>Probabilità</th><th>Motivazione Analisi</th></tr></thead>
                        <tbody>
                            ${tableRowsHtml}
                        </tbody>
                    </table>
                </div>`;
        }
        
        function calculateStats(results) {
            if (!results) return { total: 0, high: 0, medium: 0, low: 0 };
            const stats = { total: results.length, high: 0, medium: 0, low: 0 };
            results.forEach(r => {
                if (r.probability > thresholds.high) stats.high++;
                else if (r.probability >= thresholds.medium) stats.medium++;
                else stats.low++;
            });
            return stats;
        }

        function getProbabilityClass(prob) {
            if (prob > thresholds.high) return 'high';
            if (prob >= thresholds.medium) return 'medium';
            return 'low';
        }
        
        function updateAllTabStats(results) {
            for (const period of <?php echo json_encode($periods); ?>) {
                const periodResults = results[period] || [];
                const stats = calculateStats(periodResults);
                const el = document.getElementById(`stpa-tab-stats-${period}`);
                if(el) el.textContent = `${stats.high} alta, ${stats.total} tot.`;
            }
        }
        
        checkStatus();
        
        if (<?php echo json_encode(!empty($last_analysis_status) && $last_analysis_status['status'] === 'running'); ?>) {
            statusInterval = setInterval(checkStatus, 5000);
        }
    });
    </script>
    <?php
}

// Funzione per recuperare e cachare i commenti della scheda Trello
function stpa_get_card_comments_cached($card_id) {
    global $wpdb;
    $table_name = $wpdb->prefix . 'stpa_card_comments_cache';
    $cache_duration = 6 * HOUR_IN_SECONDS;

    // 1. Cerca nella cache locale del database
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
        $response_body = wp_remote_retrieve_body($response);
        $new_etag = wp_remote_retrieve_header($response, 'etag');

        if ($status_code === 304) { // Not Modified
            $comments = $cached_comments;
            $wpdb->update($table_name, ['last_fetched_at' => current_time('mysql')], ['card_id' => $card_id]);
            return ['comments' => $comments, 'fetched' => false, 'error' => false];
        } elseif ($status_code === 200) {
            $actions = json_decode($response_body, true);
            $current_comments = [];
            if (is_array($actions)) {
                $max_comments_per_card = 5; 
                $count = 0;
                foreach ($actions as $action) {
                    if ($count >= $max_comments_per_card) break; 
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
            error_log('[Trello Analysis] Errore HTTP ' . $status_code . ' recupero commenti per card ' . $card_id . ': ' . $response_body);
            return ['comments' => $comments, 'fetched' => false, 'error' => true];
        }
    }
    return ['comments' => $comments, 'fetched' => false, 'error' => false];
}

function recupera_ultima_analisi_completata() {
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }

    if (get_option('stpa_recupero_eseguito')) {
        return;
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'stpa_predictive_results';

    $latest_analysis_id = $wpdb->get_var("SELECT analysis_id FROM {$table_name} ORDER BY created_at DESC LIMIT 1");

    if ($latest_analysis_id) {
        update_option('stpa_last_completed_analysis_id', $latest_analysis_id);

        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible"><p><strong>Recupero Analisi Trello:</strong> Trovata e impostata l\'ultima analisi completata. I risultati dovrebbero essere ora visibili.</p></div>';
        });

        update_option('stpa_recupero_eseguito', true);
    } else {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning is-dismissible"><p><strong>Recupero Analisi Trello:</strong> Nessun risultato trovato nel database. È necessario eseguire una nuova analisi.</p></div>';
        });
    }
}
add_action('admin_init', 'recupera_ultima_analisi_completata');