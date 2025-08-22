<?php
/**
 * Plugin Name: Statistiche Trello - Modulo Call to Comments
 * Description: Scansiona un server FTP (o una cartella di fallback locale) alla ricerca di file audio, li trascrive e aggiunge il testo come commento alle schede Trello corrispondenti, senza eliminare i file sorgente.
 * Version: 1.6 - Autocreazione tabella e controllo errori DB
 * Author: Emanuele Tolomei
 */

if (!defined('ABSPATH')) exit;

// Includi le funzioni helper per le API
include_once plugin_dir_path(__FILE__) . 'api-helpers.php';

// Aggiunge la pagina del sottomenù (verrà chiamata dal file principale del plugin)
function stc_register_submenu_page() {
    add_submenu_page(
        'wp-trello-plugin',
        'Call to Comments',
        '📞 Call to Comments',
        'manage_options',
        'stc-call-to-comments',
        'stc_render_admin_page'
    );
}

/**
 * Renderizza la pagina di amministrazione del modulo.
 */
function stc_render_admin_page() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'stc_processed_calls';
    $page_url = menu_page_url('stc-call-to-comments', false);

    // FIX DEFINITIVO: Controlla se la tabella esiste e, in caso contrario, la crea.
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        stc_install();
        echo '<div class="notice notice-info is-dismissible"><p>La tabella del database per i log delle chiamate è stata creata con successo.</p></div>';
    }

    // Gestione dell'avvio manuale del processo
    if (isset($_POST['stc_manual_run_nonce']) && wp_verify_nonce($_POST['stc_manual_run_nonce'], 'stc_manual_run_action')) {
        $result = stc_process_audio_files();
        if (is_wp_error($result)) {
            wp_redirect(add_query_arg(['stc_message' => 'manual_error', 'error_code' => urlencode($result->get_error_message())], $page_url));
        } else {
            wp_redirect(add_query_arg(['stc_message' => 'manual_success', 'details' => urlencode($result)], $page_url));
        }
        exit;
    }

    // Gestione dell'aggiunta manuale di un file al log
    if (isset($_POST['stc_manual_add_nonce']) && wp_verify_nonce($_POST['stc_manual_add_nonce'], 'stc_manual_add_action')) {
        $filename_to_add = isset($_POST['manual_filename']) ? sanitize_text_field(wp_unslash($_POST['manual_filename'])) : '';
        if (!empty($filename_to_add)) {
            $phone_number_raw = stc_extract_phone_number_from_filename($filename_to_add);
            $trello_card_name = $phone_number_raw ? '+39' . $phone_number_raw : 'N/A (Manuale)';
            
            // FIX: Controlla il risultato dell'inserimento
            $insert_result = stc_log_processed_call(
                basename($filename_to_add),
                $trello_card_name,
                'success',
                'File registrato manualmente come già elaborato.',
                ''
            );

            if ($insert_result === false) {
                 wp_redirect(add_query_arg(['stc_message' => 'db_error', 'details' => urlencode($wpdb->last_error)], $page_url));
            } else {
                wp_redirect(add_query_arg('stc_message', 'added', $page_url));
            }
            exit;

        } else {
             wp_redirect(add_query_arg('stc_message', 'add_failed', $page_url));
             exit;
        }
    }
    
    // Mostra i messaggi di notifica dopo il redirect
    if (isset($_GET['stc_message'])) {
        switch ($_GET['stc_message']) {
            case 'added':
                echo '<div class="notice notice-success is-dismissible"><p>File aggiunto al log con successo. Non verrà più elaborato.</p></div>';
                break;
            case 'add_failed':
                echo '<div class="notice notice-warning is-dismissible"><p>Per favore, inserisci un nome file valido da registrare.</p></div>';
                break;
            case 'manual_success':
                echo '<div class="notice notice-success is-dismissible"><p>Elaborazione manuale completata! ' . esc_html(urldecode($_GET['details'])) . '</p></div>';
                break;
            case 'manual_error':
                 echo '<div class="notice notice-error is-dismissible"><p><strong>Errore durante l\'elaborazione manuale:</strong> ' . esc_html(urldecode($_GET['error_code'])) . '</p></div>';
                 break;
            case 'db_error':
                 echo '<div class="notice notice-error is-dismissible"><p><strong>Errore Database:</strong> Impossibile salvare il log. Dettagli: ' . esc_html(urldecode($_GET['details'])) . '</p></div>';
                 break;
        }
    }

    // Recupera i log dal database
    $processed_calls = $wpdb->get_results("SELECT * FROM {$table_name} ORDER BY processed_at DESC LIMIT 100", ARRAY_A);
    ?>
    <div class="wrap">
        <h1>📞 Call to Comments Dashboard</h1>
        <p>Questa dashboard mostra un log delle ultime chiamate audio elaborate. Il processo automatico ignorerà i file già presenti in questo log con stato "Successo".</p>
        
        <div class="uk-grid-divider" uk-grid>
            <div class="uk-width-1-2@m">
                <div class="uk-card uk-card-default uk-card-body">
                    <h3 class="uk-card-title">Avvio Manuale</h3>
                    <p>Forza una scansione per elaborare i file audio non ancora registrati nel log.</p>
                    <form method="post" action="">
                        <?php wp_nonce_field('stc_manual_run_action', 'stc_manual_run_nonce'); ?>
                        <button type="submit" class="button button-primary">🚀 Avvia Elaborazione</button>
                    </form>
                </div>
            </div>
            <div class="uk-width-1-2@m">
                <div class="uk-card uk-card-default uk-card-body">
                    <h3 class="uk-card-title">Registra File Manualmente</h3>
                    <p>Se hai caricato un file che non vuoi processare, inserisci qui il suo nome per marcarlo come "già elaborato".</p>
                    <form method="post" action="">
                        <?php wp_nonce_field('stc_manual_add_action', 'stc_manual_add_nonce'); ?>
                        <div class="uk-margin">
                            <label class="uk-form-label" for="manual_filename">Nome del file (es. file.wav):</label>
                            <div class="uk-form-controls">
                                <input class="uk-input" id="manual_filename" name="manual_filename" type="text" placeholder="crm4_..._amasrl.wav" required>
                            </div>
                        </div>
                        <button type="submit" class="button button-secondary">✓ Registra come Elaborato</button>
                    </form>
                </div>
            </div>
        </div>

        <h2 class="uk-margin-large-top">Log Ultime Chiamate Elaborate</h2>
        <div class="uk-overflow-auto">
            <table class="wp-list-table widefat striped">
                <thead>
                    <tr>
                        <th>Data Elaborazione</th>
                        <th>Nome File Audio</th>
                        <th>Scheda Trello (Numero)</th>
                        <th>Stato</th>
                        <th>Dettagli</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($processed_calls)) : ?>
                        <tr><td colspan="5">Nessuna chiamata ancora elaborata.</td></tr>
                    <?php else : ?>
                        <?php foreach ($processed_calls as $call) : ?>
                            <tr>
                                <td><?php echo esc_html(date('d/m/Y H:i:s', strtotime($call['processed_at']))); ?></td>
                                <td><?php echo esc_html($call['audio_filename']); ?></td>
                                <td>
                                    <?php if (!empty($call['trello_card_url'])) : ?>
                                        <a href="<?php echo esc_url($call['trello_card_url']); ?>" target="_blank"><?php echo esc_html($call['trello_card_name']); ?></a>
                                    <?php else : ?>
                                        <?php echo esc_html($call['trello_card_name']); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($call['status'] === 'success') : ?>
                                        <span style="color: green;">✓ Successo</span>
                                    <?php else : ?>
                                        <span style="color: red;">✗ Fallito</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo esc_html($call['details']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php
}


/**
 * Funzione principale che orchestra il processo di elaborazione dei file.
 */
function stc_process_audio_files() {
    global $wpdb;
    $file_list = [];
    $source_type = '';
    $ftp_conn = null;
    $local_audio_path = plugin_dir_path(__FILE__) . 'audio/';

    $processed_table_name = $wpdb->prefix . 'stc_processed_calls';
    $already_processed_files = $wpdb->get_col("SELECT DISTINCT audio_filename FROM {$processed_table_name} WHERE status = 'success'");

    // 1. Tenta la connessione FTP
    $ftp_conn_result = stc_connect_ftp();
    if (!is_wp_error($ftp_conn_result)) {
        $source_type = 'ftp';
        $ftp_conn = $ftp_conn_result;
        $files = ftp_nlist($ftp_conn, ".");
        if ($files !== false) {
            $file_list = $files;
        }
    } else {
        // 2. Se FTP fallisce, usa il fallback locale
        error_log('CallToComments - INFO: Connessione FTP fallita. Tento il fallback alla cartella locale.');
        $source_type = 'local';
        if (is_dir($local_audio_path)) {
            $scanned_files = scandir($local_audio_path);
            if ($scanned_files) {
                 $file_list = array_diff($scanned_files, ['.', '..']);
            }
        } else {
            wp_mkdir_p($local_audio_path);
        }
    }

    $all_wav_files = array_filter($file_list, function($file) {
        return pathinfo($file, PATHINFO_EXTENSION) === 'wav';
    });

    $wav_files_to_process = array_filter($all_wav_files, function($file) use ($already_processed_files) {
        return !in_array(basename($file), $already_processed_files);
    });

    if (empty($wav_files_to_process)) {
        if ($ftp_conn) ftp_close($ftp_conn);
        return 'Nessun nuovo file .wav trovato da elaborare.';
    }

    $processed_count = 0;
    $error_count = 0;

    foreach ($wav_files_to_process as $file) {
        $file_path_for_processing = '';
        $original_filename = basename($file);

        if ($source_type === 'ftp') {
            $local_temp_file = download_url('ftp://' . STC_FTP_USER . ':' . STC_FTP_PASS . '@' . STC_FTP_HOST . '/' . $original_filename);
            if (is_wp_error($local_temp_file)) {
                stc_log_processed_call($original_filename, 'N/A', 'error', 'Download da FTP fallito: ' . $local_temp_file->get_error_message());
                $error_count++;
                continue;
            }
            $file_path_for_processing = $local_temp_file;
        } else { // 'local'
            $file_path_for_processing = $local_audio_path . $original_filename;
        }

        $phone_number_raw = stc_extract_phone_number_from_filename($original_filename);
        if (!$phone_number_raw) {
            stc_log_processed_call($original_filename, 'N/A', 'error', 'Impossibile estrarre il numero di telefono dal nome del file.');
            $error_count++;
            if ($source_type === 'ftp') unlink($file_path_for_processing);
            continue;
        }
        $trello_card_name = '+39' . $phone_number_raw;

        $transcription_result = stc_transcribe_audio_openai($file_path_for_processing);
        
        if ($source_type === 'ftp') {
            unlink($file_path_for_processing);
        }

        if (is_wp_error($transcription_result)) {
            stc_log_processed_call($original_filename, $trello_card_name, 'error', 'Trascrizione fallita: ' . $transcription_result->get_error_message());
            $error_count++;
            continue;
        }

        $comment_text = "#calltocomments\n\n" . $transcription_result;
        $trello_result = stc_add_comment_to_trello_card($trello_card_name, $comment_text);

        if (is_wp_error($trello_result)) {
            stc_log_processed_call($original_filename, $trello_card_name, 'error', 'Aggiunta commento Trello fallita: ' . $trello_result->get_error_message());
            $error_count++;
        } else {
            stc_log_processed_call($original_filename, $trello_card_name, 'success', 'Commento aggiunto correttamente.', $trello_result['card_url']);
            $processed_count++;
        }
    }

    if ($ftp_conn) ftp_close($ftp_conn);
    return "File elaborati: {$processed_count}. Errori: {$error_count}.";
}


/**
 * Funzione per installare la tabella di log nel database.
 */
function stc_install() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'stc_processed_calls';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        processed_at datetime DEFAULT '0000-00-00 00:00:00' NOT NULL,
        audio_filename varchar(255) DEFAULT '' NOT NULL,
        trello_card_name varchar(255) DEFAULT '' NOT NULL,
        trello_card_url varchar(255) DEFAULT '' NOT NULL,
        status varchar(20) DEFAULT '' NOT NULL,
        details text NOT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
// Usa un hook che si attiva in admin per garantire la creazione della tabella
add_action('admin_init', 'stc_install_check');
function stc_install_check() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'stc_processed_calls';
    if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
        stc_install();
    }
}


/**
 * Logga un evento di elaborazione nel database.
 * @return false|int
 */
function stc_log_processed_call($filename, $card_name, $status, $details, $card_url = '') {
    global $wpdb;
    $table_name = $wpdb->prefix . 'stc_processed_calls';

    return $wpdb->insert(
        $table_name,
        [
            'processed_at' => current_time('mysql'),
            'audio_filename' => $filename,
            'trello_card_name' => $card_name,
            'trello_card_url' => $card_url,
            'status' => $status,
            'details' => $details,
        ]
    );
}

// --- IMPOSTAZIONE WP CRON ---
if (!wp_next_scheduled('stc_process_audio_cron_hook')) {
    wp_schedule_event(time(), 'every_15_minutes', 'stc_process_audio_cron_hook');
}
add_action('stc_process_audio_cron_hook', 'stc_process_audio_files');

// Aggiungi un intervallo di tempo personalizzato per il cron
function stc_add_cron_interval($schedules) {
    if (!isset($schedules['every_15_minutes'])) {
        $schedules['every_15_minutes'] = [
            'interval' => 900, // 15 minuti in secondi
            'display' => esc_html__('Ogni 15 Minuti'),
        ];
    }
    return $schedules;
}
add_filter('cron_schedules', 'stc_add_cron_interval');