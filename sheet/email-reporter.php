<?php
/**
 * File: email-reporter.php
 * Descrizione: Gestisce la pagina delle impostazioni, la creazione del cron e l'invio dei report via email.
 * Author: Jules
 */

if (!defined('ABSPATH')) exit;

// Hook per registrare le impostazioni
add_action('admin_init', 'stsg_register_email_report_settings');

/**
 * Registra le impostazioni nella whitelist di WordPress.
 */
function stsg_register_email_report_settings() {
    register_setting(
        'stsg_email_report_options_group', // Option group
        'stsg_report_settings',            // Option name
        'stsg_sanitize_report_settings'    // Sanitize callback
    );
}

/**
 * Sanifica i dati del form prima di salvarli nel database.
 */
function stsg_sanitize_report_settings($input) {
    $new_input = [];

    if (isset($input['recipient_emails'])) {
        // Rimuove spazi e sanifica ogni email
        $emails = explode(',', $input['recipient_emails']);
        $sanitized_emails = [];
        foreach ($emails as $email) {
            $trimmed_email = trim($email);
            if (is_email($trimmed_email)) {
                $sanitized_emails[] = sanitize_email($trimmed_email);
            }
        }
        $new_input['recipient_emails'] = implode(',', $sanitized_emails);
    }

    if (isset($input['frequency'])) {
        $new_input['frequency'] = sanitize_text_field($input['frequency']);
    }

    return $new_input;
}


/**
 * Renderizza il contenuto della pagina delle impostazioni per i report via email.
 */
function stsg_render_email_report_settings_page() {
    ?>
    <div class="wrap">
        <h1>📧 Impostazioni Report via Email</h1>
        <p>Configura l'invio automatico dei report con il riassunto generato da OpenAI.</p>

        <form method="post" action="options.php">
            <?php
                settings_fields('stsg_email_report_options_group');
                $options = get_option('stsg_report_settings');
                $recipient_emails = isset($options['recipient_emails']) ? $options['recipient_emails'] : '';
                $frequency = isset($options['frequency']) ? $options['frequency'] : 'daily';
            ?>

            <table class="form-table">
                <tr valign="top">
                    <th scope="row">Indirizzi Email dei Destinatari</th>
                    <td>
                        <input type="text" name="stsg_report_settings[recipient_emails]" value="<?php echo esc_attr($recipient_emails); ?>" class="regular-text" />
                        <p class="description">Inserisci uno o più indirizzi email separati da una virgola (,).</p>
                    </td>
                </tr>

                <tr valign="top">
                    <th scope="row">Frequenza di Invio</th>
                    <td>
                        <select name="stsg_report_settings[frequency]">
                            <option value="daily" <?php selected($frequency, 'daily'); ?>>Giornaliera</option>
                            <option value="weekly" <?php selected($frequency, 'weekly'); ?>>Settimanale (ogni Lunedì alle 8:00)</option>
                            <option value="disabled" <?php selected($frequency, 'disabled'); ?>>Disabilitato</option>
                        </select>
                        <p class="description">Scegli quanto spesso inviare il report.</p>
                    </td>
                </tr>
            </table>

            <?php submit_button('Salva Impostazioni'); ?>
        </form>

        <hr/>

        <h2>Test di Invio</h2>
        <p>Usa questo pulsante per inviare immediatamente un'email di prova agli indirizzi salvati.</p>
        <form method="post" action="">
            <input type="hidden" name="stsg_action" value="send_test_email">
            <?php wp_nonce_field('stsg_send_test_email_nonce', 'stsg_test_nonce'); ?>
            <?php submit_button('Invia Email di Prova', 'secondary'); ?>
        </form>
    </div>
    <?php
}

// --- LOGICA DI RACCOLTA DATI ---

/**
 * Raccoglie i dati aggregati per il report email, confrontando la settimana corrente con quella precedente.
 *
 * @return array Un array contenente i dati per 'current_week' e 'previous_week', oppure un WP_Error.
 */
function stsg_get_data_for_email_report() {
    // 1. Determinare le date per la settimana corrente e quella precedente (da Lunedì a Domenica)
    $today = new DateTime('now', new DateTimeZone('Europe/Rome'));

    $current_week_start = (clone $today)->modify('monday this week');
    $current_week_end = (clone $current_week_start)->modify('+6 days');

    $previous_week_start = (clone $current_week_start)->modify('-7 days');
    $previous_week_end = (clone $previous_week_start)->modify('+6 days');

    // 2. Raccogliere i dati per entrambe le settimane
    $current_week_data = stsg_get_aggregated_data_for_range($current_week_start, $current_week_end);
    if (is_wp_error($current_week_data)) {
        return new WP_Error('data_fetch_error', 'Errore nel recupero dati per la settimana corrente: ' . $current_week_data->get_error_message());
    }

    $previous_week_data = stsg_get_aggregated_data_for_range($previous_week_start, $previous_week_end);
     if (is_wp_error($previous_week_data)) {
        return new WP_Error('data_fetch_error', 'Errore nel recupero dati per la settimana precedente: ' . $previous_week_data->get_error_message());
    }

    $report_data = [
        'current_week' => [
            'start_date' => $current_week_start->format('d/m/Y'),
            'end_date' => $current_week_end->format('d/m/Y'),
            'data' => $current_week_data
        ],
        'previous_week' => [
            'start_date' => $previous_week_start->format('d/m/Y'),
            'end_date' => $previous_week_end->format('d/m/Y'),
            'data' => $previous_week_data
        ]
    ];

    return $report_data;
}


/**
 * Funzione helper che aggrega tutti i dati necessari da Trello e Google Sheets per un dato intervallo di date.
 *
 * @param DateTime $start_date Data di inizio.
 * @param DateTime $end_date Data di fine.
 * @return array|WP_Error Un array con i dati aggregati o un WP_Error.
 */
function stsg_get_aggregated_data_for_range(DateTime $start_date, DateTime $end_date) {
    // Inizializza il client di Google Sheets
    $stsg_client = new STSG_Google_Sheets_Client();
    $sheets_service = $stsg_client->getService();
    if (is_wp_error($sheets_service)) {
        return $sheets_service;
    }

    // 1. Calcola le richieste totali e per provenienza da Trello
    $all_cards = stsg_get_all_cards_for_sheet(); // Funzione esistente da functions.php
    if (is_wp_error($all_cards)) {
        return $all_cards;
    }

    $requests_data = [
        'total_requests' => 0,
        'provenances' => []
    ];

    // Assicura che l'intervallo di date includa l'intera giornata finale
    $start_date_inclusive = (clone $start_date)->setTime(0, 0, 0);
    $end_date_inclusive = (clone $end_date)->setTime(23, 59, 59);

    if (!empty($all_cards)) {
        foreach ($all_cards as $card) {
            // Considera solo le card con nome numerico, come definito dalla logica esistente
            if (isset($card['name']) && is_numeric(trim($card['name']))) {
                $card_date = stsg_get_card_creation_date($card['id']);
                if ($card_date && $card_date >= $start_date_inclusive && $card_date <= $end_date_inclusive) {
                    $requests_data['total_requests']++;
                    $provenance = wp_trello_normalize_provenance_value($card['provenance']);
                    if ($provenance) {
                        if (!isset($requests_data['provenances'][$provenance])) {
                            $requests_data['provenances'][$provenance] = 0;
                        }
                        $requests_data['provenances'][$provenance]++;
                    }
                }
            }
        }
    }

    // 2. Calcola i preventivi fatti leggendo dai fogli 'Preventivi-Mese-Anno'
    // La funzione stsg_get_total_quotes_for_date_range esiste già in functions.php
    $total_quotes = stsg_get_total_quotes_for_date_range($sheets_service, $start_date_inclusive, $end_date_inclusive);

    // 3. Combina i risultati
    $aggregated_data = [
        'total_requests'   => $requests_data['total_requests'],
        'requests_by_source' => $requests_data['provenances'],
        'total_quotes'     => $total_quotes,
    ];

    return $aggregated_data;
}


// --- INTEGRAZIONE OPENAI ---

/**
 * Invia i dati a OpenAI per ottenere un riassunto analitico.
 *
 * @param array $report_data I dati raccolti da stsg_get_data_for_email_report().
 * @return string|WP_Error Il riassunto testuale o un WP_Error.
 */
function stsg_get_openai_summary($report_data) {
    if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY)) {
        return new WP_Error('openai_key_missing', 'La chiave API di OpenAI (OPENAI_API_KEY) non è definita.');
    }

    $api_key = OPENAI_API_KEY;
    $api_url = 'https://api.openai.com/v1/chat/completions';

    // Prepara una rappresentazione testuale dei dati per il prompt
    $current_week = $report_data['current_week'];
    $previous_week = $report_data['previous_week'];

    $prompt_text = "Agisci come un analista di dati aziendali. Il tuo compito è scrivere un breve paragrafo (massimo 100 parole) in italiano per un report via email. Riassumi e confronta i seguenti dati di performance tra questa settimana e la settimana scorsa. Sii professionale, conciso e metti in evidenza i trend principali (crescita o calo).\n\n";
    $prompt_text .= "Dati Settimana Corrente (" . $current_week['start_date'] . " - " . $current_week['end_date'] . "):\n";
    $prompt_text .= "- Richieste Totali: " . $current_week['data']['total_requests'] . "\n";
    $prompt_text .= "- Preventivi Fatti: " . $current_week['data']['total_quotes'] . "\n\n";

    $prompt_text .= "Dati Settimana Precedente (" . $previous_week['start_date'] . " - " . $previous_week['end_date'] . "):\n";
    $prompt_text .= "- Richieste Totali: " . $previous_week['data']['total_requests'] . "\n";
    $prompt_text .= "- Preventivi Fatti: " . $previous_week['data']['total_quotes'] . "\n\n";
    $prompt_text .= "Fornisci solo il paragrafo di riassunto, senza titoli o introduzioni.";

    $body = [
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => 'Sei un analista di dati che scrive riassunti per report aziendali in italiano.'],
            ['role' => 'user', 'content' => $prompt_text]
        ],
        'temperature' => 0.5,
        'max_tokens' => 150,
    ];

    $request_args = [
        'body'    => json_encode($body),
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'timeout' => 60,
    ];

    $response = wp_remote_post($api_url, $request_args);

    if (is_wp_error($response)) {
        return new WP_Error('openai_api_error', 'Errore durante la chiamata a OpenAI: ' . $response->get_error_message());
    }

    $response_body = wp_remote_retrieve_body($response);
    $response_data = json_decode($response_body, true);

    if (isset($response_data['error'])) {
        return new WP_Error('openai_response_error', 'OpenAI ha restituito un errore: ' . $response_data['error']['message']);
    }

    if (isset($response_data['choices'][0]['message']['content'])) {
        return trim($response_data['choices'][0]['message']['content']);
    }

    return new WP_Error('openai_unexpected_response', 'Risposta inattesa da OpenAI.');
}


// --- LOGICA INVIO EMAIL E GESTIONE CRON ---

// Hook per gestire l'invio del test
add_action('admin_init', 'stsg_handle_test_email_sending');

/**
 * Controlla se è stato premuto il pulsante di test e avvia l'invio.
 */
function stsg_handle_test_email_sending() {
    if (isset($_POST['stsg_action']) && $_POST['stsg_action'] === 'send_test_email' && isset($_POST['stsg_test_nonce']) && wp_verify_nonce($_POST['stsg_test_nonce'], 'stsg_send_test_email_nonce')) {

        $result = stsg_send_report_email();

        if (is_wp_error($result)) {
            add_action('admin_notices', function() use ($result) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Errore durante l\'invio dell\'email di prova:</strong> ' . esc_html($result->get_error_message()) . '</p></div>';
            });
        } else {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>Email di prova inviata con successo!</p></div>';
            });
        }
    }
}

/**
 * Funzione principale che orchestra la creazione e l'invio del report via email.
 */
function stsg_send_report_email() {
    $options = get_option('stsg_report_settings');
    $recipient_emails_str = !empty($options['recipient_emails']) ? $options['recipient_emails'] : '';

    if (empty($recipient_emails_str)) {
        return new WP_Error('no_recipients', 'Nessun indirizzo email destinatario impostato.');
    }

    // Converte la stringa in un array di email valide
    $recipients = array_filter(array_map('trim', explode(',', $recipient_emails_str)), 'is_email');
    if (empty($recipients)) {
         return new WP_Error('no_valid_recipients', 'Nessun indirizzo email valido trovato nelle impostazioni.');
    }


    // 1. Raccogli i dati
    $report_data = stsg_get_data_for_email_report();
    if (is_wp_error($report_data)) {
        error_log('STSG Email Report Error: ' . $report_data->get_error_message());
        return $report_data; // Propaga l'errore
    }

    // 2. Genera il riassunto con OpenAI
    $summary = stsg_get_openai_summary($report_data);
    if (is_wp_error($summary)) {
        error_log('STSG Email Report Error: ' . $summary->get_error_message());
        return $summary; // Propaga l'errore
    }

    // 3. Costruisci l'email
    $current_week = $report_data['current_week'];
    $subject = "Report Settimanale Performance: " . $current_week['start_date'] . " - " . $current_week['end_date'];

    $message = "<html><body style='font-family: sans-serif;'>";
    $message .= "<h2>Report Performance</h2>";
    $message .= "<p><strong>Periodo:</strong> " . $current_week['start_date'] . " - " . $current_week['end_date'] . "</p>";
    $message .= "<h3 style='color: #2c3e50;'>Riassunto Analitico</h3>";
    $message .= "<p style='border-left: 3px solid #3498db; padding-left: 15px; font-style: italic;'>" . nl2br(esc_html($summary)) . "</p>";
    $message .= "<h3 style='color: #2c3e50;'>Dati della Settimana</h3>";
    $message .= "<ul style='list-style-type: square; padding-left: 20px;'>";
    $message .= "<li><strong>Richieste Totali:</strong> " . (int)$current_week['data']['total_requests'] . "</li>";
    $message .= "<li><strong>Preventivi Fatti:</strong> " . (int)$current_week['data']['total_quotes'] . "</li>";
    $message .= "</ul>";
    $message .= '<p>Per un\'analisi dettagliata, puoi consultare il foglio di calcolo completo al seguente link:</p>';
    $message .= '<p style="margin-top: 15px;"><a href="https://docs.google.com/spreadsheets/d/' . STSG_SPREADSHEET_ID . '/" style="background-color: #3498db; color: white; padding: 10px 15px; text-decoration: none; border-radius: 5px;">Apri Google Sheet</a></p>';
    $message .= "<p style='margin-top: 20px; font-size: 0.8em; color: #7f8c8d;'>Questo è un report automatico.</p>";
    $message .= "</body></html>";

    $headers = ['Content-Type: text/html; charset=UTF-8'];

    // 4. Invia l'email
    $sent = wp_mail($recipients, $subject, $message, $headers);

    if (!$sent) {
        return new WP_Error('wp_mail_failed', 'La funzione wp_mail() non è riuscita a inviare l\'email. Controlla la configurazione del server di posta.');
    }

    return true;
}

// --- GESTIONE CRON ---

// Hook eseguito quando le opzioni vengono aggiornate
add_action('update_option_stsg_report_settings', 'stsg_schedule_email_cron', 10, 2);
add_action('add_option_stsg_report_settings', 'stsg_schedule_email_cron_on_add', 10, 2);

function stsg_schedule_email_cron_on_add($option, $value) {
    stsg_schedule_email_cron(null, $value);
}


/**
 * Schedula o cancella il cron job in base alle impostazioni salvate.
 */
function stsg_schedule_email_cron($old_value, $new_value) {
    $frequency = isset($new_value['frequency']) ? $new_value['frequency'] : 'disabled';
    $cron_hook = 'stsg_run_email_report_cron_hook';

    // Prima cancella qualsiasi evento esistente per evitare duplicati
    $timestamp = wp_next_scheduled($cron_hook);
    if ($timestamp) {
        wp_unschedule_event($timestamp, $cron_hook);
    }

    if ($frequency === 'disabled') {
        error_log('STSG Email Report Cron: Cron job disabilitato e rimosso.');
        return;
    }

    $next_run = false;
    // Calcola il prossimo timestamp
    if ($frequency === 'daily') {
        // Ogni giorno alle 8:00
        $next_run = strtotime('tomorrow 8:00:00');
    } elseif ($frequency === 'weekly') {
        // Il prossimo lunedì alle 8:00
        $next_run = strtotime('next monday 8:00:00');
    }

    if($next_run) {
        wp_schedule_event($next_run, $frequency, $cron_hook);
        error_log('STSG Email Report Cron: Cron job schedulato con frequenza ' . $frequency . '. Prossima esecuzione: ' . date('Y-m-d H:i:s', $next_run));
    }
}

// Aggiungi l'hook per la funzione del cron
add_action('stsg_run_email_report_cron_hook', 'stsg_run_email_report_cron_callback');

/**
 * La funzione che viene effettivamente eseguita dal cron job di WordPress.
 */
function stsg_run_email_report_cron_callback() {
    error_log('STSG Email Report Cron: Avvio invio report programmato.');
    stsg_send_report_email();
}
