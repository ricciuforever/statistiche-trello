<?php
/**
 * File: email-reporter.php
 * Descrizione: Gestisce la pagina delle impostazioni, la creazione dei cron e l'invio dei report via email.
 * Author: Jules
 */

if (!defined('ABSPATH')) exit;

// --- REGISTRAZIONE IMPOSTAZIONI, MENU E NOTIFICHE ---

add_action('admin_init', 'stsg_register_email_report_settings');
add_action('admin_notices', 'stsg_display_admin_notices');

function stsg_register_email_report_settings() {
    register_setting(
        'stsg_email_report_options_group',
        'stsg_report_settings',
        'stsg_sanitize_report_settings'
    );
}

function stsg_sanitize_report_settings($input) {
    $existing_options = get_option('stsg_report_settings', []);
    $output = is_array($existing_options) ? $existing_options : [];
    $submitted_section = sanitize_key($_POST['submitted_section'] ?? '');

    if (!empty($submitted_section) && isset($input[$submitted_section])) {
        $section_input = $input[$submitted_section];
        $sanitized_section = [];

        if (isset($section_input['recipients'])) {
            $emails = explode(',', $section_input['recipients']);
            $sanitized_emails = array_filter(array_map(function($email) {
                $trimmed = trim($email);
                return is_email($trimmed) ? sanitize_email($trimmed) : null;
            }, $emails));
            $sanitized_section['recipients'] = implode(',', $sanitized_emails);
        }

        if (isset($section_input['frequency'])) {
            $sanitized_section['frequency'] = sanitize_text_field($section_input['frequency']);
        }

        $sanitized_section['enabled'] = isset($section_input['enabled']) ? 'yes' : 'no';
        $output[$submitted_section] = $sanitized_section;

        // Imposta un transient per mostrare il messaggio di "Impostazioni salvate"
        set_transient('stsg_admin_notice', ['type' => 'success', 'message' => 'Impostazioni salvate correttamente.'], 30);
    }

    return $output;
}

/**
 * Mostra i messaggi di notifica (di successo o errore) salvati nei transient.
 */
function stsg_display_admin_notices() {
    if ($notice = get_transient('stsg_admin_notice')) {
        $type = esc_attr($notice['type']);
        $message = esc_html($notice['message']);
        printf('<div class="notice notice-%s is-dismissible"><p>%s</p></div>', $type, $message);
        delete_transient('stsg_admin_notice');
    }
}


// --- RENDER DELLA PAGINA DI IMPOSTAZIONI ---

function stsg_render_email_report_settings_page() {
    ?>
    <div class="wrap">
        <h1>📧 Impostazioni Report via Email</h1>
        <p>Configura l'invio automatico dei report con il riassunto generato da OpenAI.</p>

        <?php $active_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'weekly_summary'; ?>

        <h2 class="nav-tab-wrapper">
            <a href="?page=stsg-email-reporter&tab=weekly_summary" class="nav-tab <?php echo $active_tab == 'weekly_summary' ? 'nav-tab-active' : ''; ?>">Riassunto Settimanale</a>
            <a href="?page=stsg-email-reporter&tab=predictive_analysis" class="nav-tab <?php echo $active_tab == 'predictive_analysis' ? 'nav-tab-active' : ''; ?>">Analisi Predittiva</a>
            <a href="?page=stsg-email-reporter&tab=marketing_advisor" class="nav-tab <?php echo $active_tab == 'marketing_advisor' ? 'nav-tab-active' : ''; ?>">Marketing Advisor</a>
        </h2>

        <form method="post" action="options.php">
            <?php
            settings_fields('stsg_email_report_options_group');

            if ($active_tab === 'weekly_summary') {
                stsg_render_report_settings_section('weekly_summary', 'Riassunto Settimanale');
            } elseif ($active_tab === 'predictive_analysis') {
                stsg_render_report_settings_section('predictive_analysis', 'Analisi Predittiva');
            } elseif ($active_tab === 'marketing_advisor') {
                stsg_render_report_settings_section('marketing_advisor', 'Marketing Advisor');
            }

            submit_button('Salva Impostazioni');
            ?>
        </form>
    </div>
    <?php
}

function stsg_render_report_settings_section($section_key, $section_title) {
    $options = get_option('stsg_report_settings', []);
    $section_options = $options[$section_key] ?? [];

    $enabled = $section_options['enabled'] ?? 'no';
    $recipients = $section_options['recipients'] ?? '';
    $frequency = $section_options['frequency'] ?? 'disabled';
    ?>
    <h3>Impostazioni per: <?php echo esc_html($section_title); ?></h3>
    <input type="hidden" name="submitted_section" value="<?php echo esc_attr($section_key); ?>" />

    <table class="form-table">
        <tr valign="top">
            <th scope="row">Abilita questo Report</th>
            <td>
                <label><input type="checkbox" name="stsg_report_settings[<?php echo $section_key; ?>][enabled]" value="yes" <?php checked($enabled, 'yes'); ?> /> Sì, invia questo report automaticamente.</label>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row">Indirizzi Email Destinatari</th>
            <td>
                <input type="text" name="stsg_report_settings[<?php echo $section_key; ?>][recipients]" value="<?php echo esc_attr($recipients); ?>" class="regular-text" />
                <p class="description">Inserisci uno o più indirizzi email separati da una virgola (,).</p>
            </td>
        </tr>
        <tr valign="top">
            <th scope="row">Frequenza di Invio</th>
            <td>
                <select name="stsg_report_settings[<?php echo $section_key; ?>][frequency]">
                    <option value="daily" <?php selected($frequency, 'daily'); ?>>Giornaliera (alle 8:00)</option>
                    <option value="weekly" <?php selected($frequency, 'weekly'); ?>>Settimanale (Lunedì alle 8:00)</option>
                    <option value="disabled" <?php selected($frequency, 'disabled'); ?>>Disabilitato</option>
                </select>
            </td>
        </tr>
    </table>

    <hr/>
    <h4>Test di Invio</h4>
    <p>Usa questo pulsante per inviare immediatamente un'email di prova per il report "<?php echo esc_html($section_title); ?>".</p>
    <form method="post" action="">
        <input type="hidden" name="stsg_action" value="send_test_email">
        <input type="hidden" name="report_type" value="<?php echo esc_attr($section_key); ?>">
        <?php wp_nonce_field('stsg_send_test_email_nonce', 'stsg_test_nonce'); ?>
        <?php submit_button('Invia Email di Prova per ' . $section_title, 'secondary'); ?>
    </form>
    <?php
}


// --- GESTIONE CRON JOB ---

add_action('update_option_stsg_report_settings', 'stsg_update_all_cron_schedules', 10, 2);
add_action('add_option_stsg_report_settings', 'stsg_update_all_cron_schedules_on_add', 10, 2);

function stsg_update_all_cron_schedules_on_add($option, $value) {
    stsg_update_all_cron_schedules(null, $value);
}

function stsg_update_all_cron_schedules($old_value, $new_value) {
    $report_types = ['weekly_summary', 'predictive_analysis', 'marketing_advisor'];
    $old_value = is_array($old_value) ? $old_value : [];

    foreach ($report_types as $type) {
        $cron_hook = 'stsg_run_report_cron_' . $type;
        $new_settings = $new_value[$type] ?? ['enabled' => 'no', 'frequency' => 'disabled'];
        $old_settings = $old_value[$type] ?? ['enabled' => 'no', 'frequency' => 'disabled'];

        $has_changed = ($new_settings['enabled'] !== $old_settings['enabled']) || ($new_settings['frequency'] !== $old_settings['frequency']);

        if (!$has_changed) continue; // Salta se non ci sono modifiche per questo report

        $timestamp = wp_next_scheduled($cron_hook);
        if ($timestamp) {
            wp_unschedule_event($timestamp, $cron_hook);
        }

        if ($new_settings['enabled'] === 'yes' && $new_settings['frequency'] !== 'disabled') {
            $frequency = $new_settings['frequency'];
            $next_run = ($frequency === 'daily') ? strtotime('tomorrow 8:00:00') : strtotime('next monday 8:00:00');
            wp_schedule_event($next_run, $frequency, $cron_hook);
            error_log("STSG Email Report: Cron job per '{$type}' schedulato (frequenza: {$frequency}).");
        } else {
            error_log("STSG Email Report: Cron job per '{$type}' disabilitato e rimosso.");
        }
    }
}


// --- ESECUZIONE CRON JOB E INVIO EMAIL ---

add_action('stsg_run_report_cron_weekly_summary', 'stsg_send_weekly_summary_report');
add_action('stsg_run_report_cron_predictive_analysis', 'stsg_send_predictive_analysis_report');
add_action('stsg_run_report_cron_marketing_advisor', 'stsg_send_marketing_advisor_report');

add_action('admin_init', 'stsg_handle_test_email_sending');

function stsg_handle_test_email_sending() {
    if (isset($_POST['stsg_action']) && $_POST['stsg_action'] === 'send_test_email' && isset($_POST['report_type']) && isset($_POST['stsg_test_nonce']) && wp_verify_nonce($_POST['stsg_test_nonce'], 'stsg_send_test_email_nonce')) {
        $report_type = sanitize_key($_POST['report_type']);
        $result = false;

        switch ($report_type) {
            case 'weekly_summary': $result = stsg_send_weekly_summary_report(); break;
            case 'predictive_analysis': $result = stsg_send_predictive_analysis_report(); break;
            case 'marketing_advisor': $result = stsg_send_marketing_advisor_report(); break;
        }

        if (is_wp_error($result)) {
            set_transient('stsg_admin_notice', ['type' => 'error', 'message' => 'Errore: ' . $result->get_error_message()], 30);
        } elseif ($result === true) {
            set_transient('stsg_admin_notice', ['type' => 'success', 'message' => 'Email di prova inviata con successo!'], 30);
        } else {
            set_transient('stsg_admin_notice', ['type' => 'warning', 'message' => 'Invio fallito. La funzione di invio non è implementata o ha restituito un risultato imprevisto.'], 30);
        }

        // Ricarica la pagina per mostrare il transient
        wp_safe_redirect(wp_get_referer());
        exit;
    }
}

function stsg_send_weekly_summary_report() {
    $options = get_option('stsg_report_settings')['weekly_summary'] ?? [];
    $recipients_str = $options['recipients'] ?? '';
    if (empty($recipients_str)) return new WP_Error('no_recipients', 'Nessun destinatario configurato per questo report.');
    $recipients = array_filter(array_map('trim', explode(',', $recipients_str)), 'is_email');
    if (empty($recipients)) return new WP_Error('no_valid_recipients', 'Nessun indirizzo email valido configurato.');
    $report_data = stsg_get_data_for_email_report();
    if (is_wp_error($report_data)) return $report_data;
    $summary = stsg_get_openai_summary($report_data);
    if (is_wp_error($summary)) return $summary;
    $current_week = $report_data['current_week'];
    $subject = "Report Settimanale Performance: " . $current_week['start_date'] . " - " . $current_week['end_date'];
    $message = "<html><body style='font-family: sans-serif;'><h1>Report Performance Settimanale</h1><p><strong>Periodo:</strong> " . $current_week['start_date'] . " - " . $current_week['end_date'] . "</p><h2 style='color: #2c3e50;'>Riassunto Analitico</h2><p style='border-left: 3px solid #3498db; padding-left: 15px; font-style: italic;'>" . nl2br(esc_html($summary)) . "</p><h2 style='color: #2c3e50;'>Dati della Settimana</h2><ul><li><strong>Richieste Totali:</strong> " . (int)$current_week['data']['total_requests'] . "</li><li><strong>Preventivi Fatti:</strong> " . (int)$current_week['data']['total_quotes'] . "</li></ul><p>Per un'analisi dettagliata, puoi consultare il foglio di calcolo completo:</p><p><a href='https://docs.google.com/spreadsheets/d/" . STSG_SPREADSHEET_ID . "/' style='background-color: #3498db; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;'>Apri Google Sheet</a></p></body></html>";
    return wp_mail($recipients, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
}

function stsg_send_predictive_analysis_report() {
    $options = get_option('stsg_report_settings')['predictive_analysis'] ?? [];
    $recipients_str = $options['recipients'] ?? '';
    if (empty($recipients_str)) return new WP_Error('no_recipients', 'Nessun destinatario configurato per questo report.');
    $recipients = array_filter(array_map('trim', explode(',', $recipients_str)), 'is_email');
    if (empty($recipients)) return new WP_Error('no_valid_recipients', 'Nessun indirizzo email valido configurato.');
    $report_data = stsg_get_predictive_analysis_report_data();
    if (is_wp_error($report_data)) return $report_data;
    if (empty($report_data['results'])) {
        $subject = "Report Analisi Predittiva: Nessun risultato rilevante";
        $message = "<p>Il report di Analisi Predittiva è stato eseguito, ma non sono stati trovati contatti con una probabilità di chiusura superiore al 75% nell'ultima analisi.</p>";
        return wp_mail($recipients, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
    }
    $html_table = stsg_format_predictive_data_as_table($report_data['results']);
    $subject = "Report Analisi Predittiva: Contatti ad Alta Probabilità di Chiusura";
    $message = "<html><body style='font-family: sans-serif;'><h1>Report Analisi Predittiva</h1><p>Di seguito sono elencati i contatti con una probabilità di chiusura stimata <strong>superiore al 75%</strong>, basata sull'ultima analisi eseguita il " . esc_html($report_data['analysis_date']) . ".</p>" . $html_table . "</body></html>";
    return wp_mail($recipients, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
}

function stsg_send_marketing_advisor_report() {
    $options = get_option('stsg_report_settings')['marketing_advisor'] ?? [];
    $recipients_str = $options['recipients'] ?? '';
    if (empty($recipients_str)) return new WP_Error('no_recipients', 'Nessun destinatario per il report Marketing Advisor.');
    $recipients = array_filter(array_map('trim', explode(',', $recipients_str)), 'is_email');
    if (empty($recipients)) return new WP_Error('no_valid_recipients', 'Nessun indirizzo email valido configurato.');
    $report_data = stsg_get_marketing_advisor_report_data();
    if (is_wp_error($report_data)) return $report_data;
    $html_content = stsg_format_marketing_advisor_data_as_html($report_data);
    $subject = "Report Marketing Advisor: Analisi e Raccomandazioni (Ultime 4 Settimane)";
    $message = "<html><body style='font-family: sans-serif;'><h1>Report Marketing Advisor</h1><p>Di seguito l'analisi delle performance di marketing delle ultime 4 settimane e le raccomandazioni per l'ottimizzazione del budget.</p>" . $html_content . "</body></html>";
    return wp_mail($recipients, $subject, $message, ['Content-Type: text/html; charset=UTF-8']);
}

// --- Funzioni di raccolta dati e utility ---

function stsg_get_predictive_analysis_report_data() {
    global $wpdb;
    $results_table = defined('STPA_RESULTS_TABLE') ? STPA_RESULTS_TABLE : $wpdb->prefix . 'stpa_predictive_results';
    $analysis_id = null;
    $analysis_date = 'N/A';

    // 1. Prova a ottenere l'ID dall'opzione stabile dell'ultima analisi completata
    $analysis_id = get_option('stpa_last_completed_analysis_id', null);

    // 2. Se fallisce, prova con la meta-coda (potrebbe essere di un'analisi in corso o fallita)
    if (empty($analysis_id)) {
        $queue_meta = get_option('stpa_queue_meta', false);
        if (!empty($queue_meta) && isset($queue_meta['analysis_id'])) {
            $analysis_id = $queue_meta['analysis_id'];
            if(isset($queue_meta['finished_at'])) {
                 $analysis_date = date('d/m/Y H:i', strtotime($queue_meta['finished_at']));
            }
        }
    }

    // 3. Come ultimo fallback, cerca l'ID più recente direttamente nel database
    if (empty($analysis_id)) {
        $latest_id = $wpdb->get_var("SELECT analysis_id FROM {$results_table} ORDER BY created_at DESC LIMIT 1");
        if ($latest_id) {
            $analysis_id = $latest_id;
        }
    }

    if (empty($analysis_id)) {
        return new WP_Error('no_analysis_found', 'Nessun ID di analisi predittiva valido trovato.');
    }

    // Se abbiamo trovato un ID ma non una data, cerchiamola
    if ($analysis_date === 'N/A') {
        $latest_date = $wpdb->get_var($wpdb->prepare("SELECT created_at FROM {$results_table} WHERE analysis_id = %s ORDER BY created_at DESC LIMIT 1", $analysis_id));
        if ($latest_date) {
            $analysis_date = date('d/m/Y H:i', strtotime($latest_date));
        }
    }

    $query = $wpdb->prepare(
        "SELECT card_name, card_url, probability, period
         FROM {$results_table}
         WHERE analysis_id = %s AND probability > 0.75 AND period = 30
         ORDER BY probability DESC",
        $analysis_id
    );
    $results = $wpdb->get_results($query, ARRAY_A);

    if ($wpdb->last_error) {
        return new WP_Error('db_query_error', 'Errore nella query al database: ' . $wpdb->last_error);
    }

    return ['results' => $results, 'analysis_date' => $analysis_date];
}

function stsg_format_predictive_data_as_table($data) {
    if (empty($data)) return '<p>Nessun risultato da mostrare.</p>';
    $html = '<table style="width: 100%; border-collapse: collapse;"><thead><tr><th style="border: 1px solid #ddd; padding: 8px; text-align: left; background-color: #f2f2f2;">Contatto</th><th style="border: 1px solid #ddd; padding: 8px; text-align: left; background-color: #f2f2f2;">Periodo (Giorni)</th><th style="border: 1px solid #ddd; padding: 8px; text-align: left; background-color: #f2f2f2;">Probabilità Chiusura</th></tr></thead><tbody>';
    foreach ($data as $row) {
        $probability_percent = round($row['probability'] * 100, 2) . '%';
        $html .= '<tr><td style="border: 1px solid #ddd; padding: 8px;"><a href="' . esc_url($row['card_url']) . '">' . esc_html($row['card_name']) . '</a></td><td style="border: 1px solid #ddd; padding: 8px;">' . esc_html($row['period']) . '</td><td style="border: 1px solid #ddd; padding: 8px;"><strong>' . esc_html($probability_percent) . '</strong></td></tr>';
    }
    $html .= '</tbody></table>';
    return $html;
}

function stsg_get_marketing_advisor_report_data() {
    global $wpdb;

    // Assicura che il file con le funzioni helper sia caricato, specialmente in contesto cron
    include_once(ABSPATH . 'wp-content/plugins/statistiche-trello/marketing-advisor/marketing-advisor-ajax.php');

    if (!function_exists('wp_trello_get_marketing_data_with_leads_costs_v19') || !function_exists('stma_create_advisor_prompt') || !function_exists('stma_call_openai_api')) {
        return new WP_Error('missing_functions', 'Le funzioni del modulo Marketing Advisor non sono disponibili.');
    }

    $provenance_map = ['iol' => 'italiaonline', 'chiamate' => 'chiamate', 'Organic Search' => 'organico', 'taormina' => 'taormina', 'fb' => 'facebook', 'Google Ads' => 'google ads', 'Subito' => 'subito', 'poolindustriale.it' => 'pool industriale', 'archiexpo' => 'archiexpo', 'Bakeka' => 'bakeka', 'Europage' => 'europage'];

    // Aggregazione costi su 4 settimane
    $marketing_data = wp_trello_get_marketing_data_with_leads_costs_v19();
    $four_weeks_costs = [];
    $today = new DateTime('now', new DateTimeZone('Europe/Rome'));
    for ($i = 0; $i < 4; $i++) {
        $date_in_week = (clone $today)->modify("-$i weeks");
        $iso_week = $date_in_week->format("o-\WW");
        $week_costs = $marketing_data[$iso_week]['costs'] ?? [];
        foreach ($week_costs as $channel => $cost) {
            $four_weeks_costs[$channel] = ($four_weeks_costs[$channel] ?? 0) + $cost;
        }
    }

    // Recupero schede degli ultimi 28 giorni
    $twenty_eight_days_ago = new DateTime('-28 days', new DateTimeZone('Europe/Rome'));
    $trello_timestamp_28_days_ago = dechex($twenty_eight_days_ago->getTimestamp());

    $apiKey = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : '';
    $apiToken = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : '';
    $all_boards = get_trello_boards($apiKey, $apiToken);
    $board_ids = array_column($all_boards, 'id');
    $board_provenance_ids_map = wp_trello_get_provenance_field_ids_map($board_ids, $apiKey, $apiToken);
    $recent_cards = [];
    foreach ($board_ids as $board_id) {
        $cards = get_trello_cards($board_id, $apiKey, $apiToken);
        foreach ($cards as $card) {
            if (hexdec(substr($card['id'], 0, 8)) >= $trello_timestamp_28_days_ago) $recent_cards[] = $card;
        }
    }
    $card_ids = array_column($recent_cards, 'id');
    $predictive_results = [];
    if (!empty($card_ids)) {
        $ids_placeholder = implode(', ', array_fill(0, count($card_ids), '%s'));
        $results_table = $wpdb->prefix . 'stpa_predictive_results';
        $db_results = $wpdb->get_results($wpdb->prepare("SELECT card_id, probability FROM $results_table WHERE card_id IN ($ids_placeholder) ORDER BY created_at DESC", $card_ids), ARRAY_A);
        foreach ($db_results as $row) {
            if (!isset($predictive_results[$row['card_id']])) $predictive_results[$row['card_id']] = (float)$row['probability'];
        }
    }
    $channel_data = [];
    foreach ($provenance_map as $trello_prov => $internal_name) {
        $channel_data[$trello_prov] = ['cost' => $four_weeks_costs[$internal_name] ?? 0, 'leads' => 0, 'quality_scores' => ['high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0]];
    }
    foreach ($recent_cards as $card) {
        $provenance = wp_trello_get_card_provenance($card, $board_provenance_ids_map) ?? 'N/D';
        if (isset($channel_data[$provenance])) {
            $channel_data[$provenance]['leads']++;
            $prob = $predictive_results[$card['id']] ?? -1;
            if ($prob >= 0.7) $channel_data[$provenance]['quality_scores']['high']++;
            elseif ($prob >= 0.4) $channel_data[$provenance]['quality_scores']['medium']++;
            elseif ($prob >= 0) $channel_data[$provenance]['quality_scores']['low']++;
            else $channel_data[$provenance]['quality_scores']['unknown']++;
        }
    }
    $data_for_prompt = [];
    foreach ($channel_data as $name => $data) {
        if ($data['leads'] == 0 && $data['cost'] == 0) continue;
        $cpl = ($data['leads'] > 0) ? round($data['cost'] / $data['leads'], 2) : 0;
        $data_for_prompt[] = ['canale' => $name, 'costo_mensile' => $data['cost'], 'lead_generati_nel_mese' => $data['leads'], 'costo_per_lead' => $cpl, 'qualita_lead' => $data['quality_scores']];
    }
    if (empty($data_for_prompt)) return new WP_Error('no_marketing_data', 'Nessun dato di marketing o lead trovato per le ultime 4 settimane.');

    $prompt = stma_create_advisor_prompt($data_for_prompt);
    return stma_call_openai_api($prompt);
}

function stsg_format_marketing_advisor_data_as_html($data) {
    if (is_wp_error($data) || !isset($data['analysis']) || !isset($data['recommendations'])) return '<p>Errore nella generazione dell\'analisi del Marketing Advisor.</p>';
    $html = '<h2>Analisi</h2><div>' . wp_kses_post($data['analysis']) . '</div><h2>Raccomandazioni Budget</h2>';
    if (empty($data['recommendations'])) {
        $html .= '<p>Nessuna raccomandazione specifica sul budget fornita.</p>';
    } else {
        $html .= '<ul style="list-style-type: disc; padding-left: 20px;">';
        foreach ($data['recommendations'] as $rec) {
            $sign = ($rec['percentage_change'] ?? 0) > 0 ? '+' : '';
            $color = ($rec['percentage_change'] ?? 0) > 0 ? 'green' : ((($rec['percentage_change'] ?? 0) < 0) ? 'red' : 'black');
            $html .= sprintf('<li style="margin-bottom: 10px;"><strong>%s %s:</strong> <span style="color: %s;">%s%d%%</span>. <br><em>%s</em></li>', esc_html($rec['action'] ?? 'N/A'), esc_html($rec['channel'] ?? 'N/A'), esc_attr($color), esc_html($sign), (int)($rec['percentage_change'] ?? 0), esc_html($rec['justification'] ?? 'N/A'));
        }
        $html .= '</ul>';
    }
    return $html;
}

function stsg_get_data_for_email_report() {
    $today = new DateTime('now', new DateTimeZone('Europe/Rome'));
    $current_week_start = (clone $today)->modify('monday this week');
    $current_week_end = (clone $current_week_start)->modify('+6 days');
    $previous_week_start = (clone $current_week_start)->modify('-7 days');
    $previous_week_end = (clone $previous_week_start)->modify('+6 days');
    $current_week_data = stsg_get_aggregated_data_for_range($current_week_start, $current_week_end);
    if (is_wp_error($current_week_data)) return new WP_Error('data_fetch_error', 'Errore recupero dati settimana corrente: ' . $current_week_data->get_error_message());
    $previous_week_data = stsg_get_aggregated_data_for_range($previous_week_start, $previous_week_end);
    if (is_wp_error($previous_week_data)) return new WP_Error('data_fetch_error', 'Errore recupero dati settimana precedente: ' . $previous_week_data->get_error_message());
    return ['current_week' => ['start_date' => $current_week_start->format('d/m/Y'), 'end_date' => $current_week_end->format('d/m/Y'), 'data' => $current_week_data], 'previous_week' => ['start_date' => $previous_week_start->format('d/m/Y'), 'end_date' => $previous_week_end->format('d/m/Y'), 'data' => $previous_week_data]];
}

function stsg_get_aggregated_data_for_range(DateTime $start_date, DateTime $end_date) {
    $stsg_client = new STSG_Google_Sheets_Client();
    $sheets_service = $stsg_client->getService();
    if (is_wp_error($sheets_service)) return $sheets_service;
    $all_cards = stsg_get_all_cards_for_sheet();
    if (is_wp_error($all_cards)) return $all_cards;
    $requests_data = ['total_requests' => 0, 'provenances' => []];
    $start_date_inclusive = (clone $start_date)->setTime(0, 0, 0);
    $end_date_inclusive = (clone $end_date)->setTime(23, 59, 59);
    if (!empty($all_cards)) {
        foreach ($all_cards as $card) {
            if (isset($card['name']) && is_numeric(trim($card['name']))) {
                $card_date = stsg_get_card_creation_date($card['id']);
                if ($card_date && $card_date >= $start_date_inclusive && $card_date <= $end_date_inclusive) {
                    $requests_data['total_requests']++;
                    $provenance = wp_trello_normalize_provenance_value($card['provenance']);
                    if ($provenance) $requests_data['provenances'][$provenance] = ($requests_data['provenances'][$provenance] ?? 0) + 1;
                }
            }
        }
    }
    $total_quotes = stsg_get_total_quotes_for_date_range($sheets_service, $start_date_inclusive, $end_date_inclusive);
    return ['total_requests' => $requests_data['total_requests'], 'requests_by_source' => $requests_data['provenances'], 'total_quotes' => $total_quotes];
}

function stsg_get_openai_summary($report_data) {
    if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY)) return new WP_Error('openai_key_missing', 'La chiave API di OpenAI (OPENAI_API_KEY) non è definita.');
    $api_key = OPENAI_API_KEY;
    $api_url = 'https://api.openai.com/v1/chat/completions';
    $current_week = $report_data['current_week'];
    $previous_week = $report_data['previous_week'];
    $prompt_text = "Agisci come un analista di dati aziendali. Il tuo compito è scrivere un breve paragrafo (massimo 100 parole) in italiano per un report via email. Riassumi e confronta i seguenti dati di performance tra questa settimana e la settimana scorsa. Sii professionale, conciso e metti in evidenza i trend principali (crescita o calo).\n\nDati Settimana Corrente (" . $current_week['start_date'] . " - " . $current_week['end_date'] . "):\n- Richieste Totali: " . $current_week['data']['total_requests'] . "\n- Preventivi Fatti: " . $current_week['data']['total_quotes'] . "\n\nDati Settimana Precedente (" . $previous_week['start_date'] . " - " . $previous_week['end_date'] . "):\n- Richieste Totali: " . $previous_week['data']['total_requests'] . "\n- Preventivi Fatti: " . $previous_week['data']['total_quotes'] . "\n\nFornisci solo il paragrafo di riassunto, senza titoli o introduzioni.";
    $body = ['model' => 'gpt-4o-mini', 'messages' => [['role' => 'system', 'content' => 'Sei un analista di dati che scrive riassunti per report aziendali in italiano.'], ['role' => 'user', 'content' => $prompt_text]], 'temperature' => 0.5, 'max_tokens' => 150];
    $request_args = ['body' => json_encode($body), 'headers' => ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $api_key], 'timeout' => 60];
    $response = wp_remote_post($api_url, $request_args);
    if (is_wp_error($response)) return new WP_Error('openai_api_error', 'Errore durante la chiamata a OpenAI: ' . $response->get_error_message());
    $response_body = wp_remote_retrieve_body($response);
    $response_data = json_decode($response_body, true);
    if (isset($response_data['error'])) return new WP_Error('openai_response_error', 'OpenAI ha restituito un errore: ' . $response_data['error']['message']);
    if (isset($response_data['choices'][0]['message']['content'])) return trim($response_data['choices'][0]['message']['content']);
    return new WP_Error('openai_unexpected_response', 'Risposta inattesa da OpenAI.');
}
