<?php
/**
 * Funzioni per la generazione del Google Sheet con le statistiche di Trello.
 *
 * @package         Trello_Stats_Sheet_Generator
 * @author          Emanuele Tolomei
 * @version         1.3.1
 */

if (!defined('ABSPATH')) exit;

// Includi le funzioni helper per la preparazione dei dati
include_once plugin_dir_path(__FILE__) . 'functions.php';
include_once plugin_dir_path(__FILE__) . 'class-stsg-google-sheets-client.php';

// --- CONFIGURAZIONE ---
// INCOLLA QUI LE INFORMAZIONI DEL TUO GOOGLE SHEET
define('STSG_SPREADSHEET_ID', '14tbboO3k_sjS4fLBR6isQZFxwM3wo21T79Oh-JC1QsE');
define('STSG_SHEET_NAME', 'Richieste'); // Es. 'Foglio1'

// NOME DELL'ETICHETTA DI TRELLO CHE INDICA UN PREVENTIVO FATTO
define('STSG_LABEL_PREVENTIVO', 'Preventivo Inviato');


/**
 * Restituisce l'elenco fisso delle intestazioni dello sheet.
 * @return array
 */
function stsg_get_fixed_headers() {
    return [
        'Settimana',
        'Settimana Chiave', // NUOVA INTESTAZIONE: Aggiunta per memorizzare la chiave completa della settimana
        'ItaliaOnline', 'Costo Italiaonline', 'Chiamate', 'Costo Chiamate',
        'Organico (Sito)', 'Costo Organico', 'Taormina', 'Costo Taormina', 'Facebook',
        'Costo Facebook', 'Google Ads', 'Costo Google Ads', 'Pool Industriale',
        'Costo Pool Industriale', 'Subito', 'Costo Subito', 'Bakeka', 'Costo Bakeka',
        'Archiexpo', 'Costo Archiexpo',
        'Europage', 'Costo Europage',
        'Totale Richieste', 'Preventivi fatti'
    ];
}

/**
 * Funzione helper per tradurre i nomi dei mesi in italiano.
 */
function stsg_translate_month_to_italian($date_string) {
    $english = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    $italian = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
    return str_replace($english, $italian, $date_string);
}

/**
 * Usa la lista di settimane fornita dall'utente.
 */
function stsg_get_week_ranges() {
    return [
        '2024-12-30_2025-01-05' => '30 dicembre - 05 gennaio',
        '2025-01-06_2025-01-12' => '06 gennaio - 12 gennaio',
        '2025-01-13_2025-01-19' => '13 gennaio - 19 gennaio',
        '2025-01-20_2025-01-26' => '20 gennaio - 26 gennaio',
        '2025-01-27_2025-02-02' => '27 gennaio - 02 febbraio',
        '2025-02-03_2025-02-09' => '03 febbraio - 09 febbraio',
        '2025-02-10_2025-02-16' => '10 febbraio - 16 febbraio',
        '2025-02-17_2025-02-23' => '17 febbraio - 23 febbraio',
        '2025-02-24_2025-03-02' => '24 febbraio - 02 marzo',
        '2025-03-03_2025-03-09' => '03 marzo - 09 marzo',
        '2025-03-10_2025-03-16' => '10 marzo - 16 marzo',
        '2025-03-17_2025-03-23' => '17 marzo - 23 marzo',
        '2025-03-24_2025-03-30' => '24 marzo - 30 marzo',
        '2025-03-31_2025-04-06' => '31 marzo - 06 aprile',
        '2025-04-07_2025-04-13' => '07 aprile - 13 aprile',
        '2025-04-14_2025-04-20' => '14 aprile - 20 aprile',
        '2025-04-21_2025-04-27' => '21 aprile - 27 aprile',
        '2025-04-28_2025-05-04' => '28 aprile - 04 maggio',
        '2025-05-05_2025-05-11' => '05 maggio - 11 maggio',
        '2025-05-12_2025-05-18' => '12 maggio - 18 maggio',
        '2025-05-19_2025-05-25' => '19 maggio - 25 maggio',
        '2025-05-26_2025-06-01' => '26 maggio - 01 giugno',
        '2025-06-02_2025-06-08' => '02 giugno - 08 giugno',
        '2025-06-09_2025-06-15' => '09 giugno - 15 giugno',
        '2025-06-16_2025-06-22' => '16 giugno - 22 giugno',
        '2025-06-23_2025-06-29' => '23 giugno - 29 giugno',
        '2025-06-30_2025-07-06' => '30 giugno - 06 luglio',
        '2025-07-07_2025-07-13' => '07 luglio - 13 luglio',
        '2025-07-14_2025-07-20' => '14 luglio - 20 luglio',
        '2025-07-21_2025-07-27' => '21 luglio - 27 luglio',
        '2025-07-28_2025-08-03' => '28 luglio - 03 agosto',
        '2025-08-04_2025-08-10' => '04 agosto - 10 agosto',
        '2025-08-11_2025-08-17' => '11 agosto - 17 agosto',
        '2025-08-18_2025-08-24' => '18 agosto - 24 agosto',
        '2025-08-25_2025-08-31' => '25 agosto - 31 agosto',
        '2025-09-01_2025-09-07' => '01 settembre - 07 settembre',
        '2025-09-08_2025-09-14' => '08 settembre - 14 settembre',
        '2025-09-15_2025-09-21' => '15 settembre - 21 settembre',
        '2025-09-22_2025-09-28' => '22 settembre - 28 settembre',
        '2025-09-29_2025-10-05' => '29 settembre - 05 ottobre',
        '2025-10-06_2025-10-12' => '06 ottobre - 12 ottobre',
        '2025-10-13_2025-10-19' => '13 ottobre - 19 ottobre',
        '2025-10-20_2025-10-26' => '20 ottobre - 26 ottobre',
        '2025-10-27_2025-11-02' => '27 ottobre - 02 novembre',
        '2025-11-03_2025-11-09' => '03 novembre - 09 novembre',
        '2025-11-10_2025-11-16' => '10 novembre - 16 novembre',
        '2025-11-17_2025-11-23' => '17 novembre - 23 novembre',
        '2025-11-24_2025-11-30' => '24 novembre - 30 novembre',
        '2025-12-01_2025-12-07' => '01 dicembre - 07 dicembre',
        '2025-12-08_2025-12-14' => '08 dicembre - 14 dicembre',
        '2025-12-15_2025-12-21' => '15 dicembre - 21 dicembre',
        '2025-12-22_2025-12-28' => '22 dicembre - 28 dicembre',
        '2025-12-29_2026-01-04' => '29 dicembre - 04 gennaio',
    ];
}

/**
 * RICOSTRUISCE lo sheet per l'anno 2025, conservando i costi esistenti.
 * @param Google_Service_Sheets $sheets_service L'istanza del servizio Google Sheets.
 * @return int|WP_Error Il numero di righe scritte o un WP_Error.
 */
function stsg_full_rebuild_sheet($sheets_service) {
    // 1. Leggi i dati esistenti dal foglio, inclusi i costi
    $existing_data_map = []; // Mappa: 'Settimana Label' => [dati della riga esistente]
    $existing_sheet_headers = []; // Le intestazioni attuali del foglio

    error_log("STSG Rebuild: Tentativo di leggere i dati esistenti da " . STSG_SHEET_NAME);

    try {
        $range = STSG_SHEET_NAME . '!A:Z'; // Leggi un intervallo sufficientemente grande
        $response = $sheets_service->spreadsheets_values->get(STSG_SPREADSHEET_ID, $range);
        $values = $response->getValues();
        
        if (!empty($values)) {
            $existing_sheet_headers = array_map('trim', array_shift($values)); // La prima riga sono le intestazioni
            error_log("STSG Rebuild: Intestazioni esistenti lette: " . print_r($existing_sheet_headers, true));

            foreach ($values as $row_data) {
                $week_label_from_sheet = trim($row_data[0] ?? '');
                if (!empty($week_label_from_sheet)) {
                    $existing_data_map[$week_label_from_sheet] = $row_data;
                }
            }
            error_log("STSG Rebuild: Mappa dati esistenti creata (prime 5 righe): " . print_r(array_slice($existing_data_map, 0, 5, true), true));

        } else {
            error_log("STSG Rebuild: Nessun dato esistente trovato nel foglio " . STSG_SHEET_NAME);
        }
    } catch (Exception $e) {
        error_log("STSG Rebuild Error: Impossibile leggere i dati esistenti per la ricostruzione: " . $e->getMessage());
    }

    $all_cards = stsg_get_all_cards_for_sheet();

    $all_weekly_stats = [];
    $week_ranges = stsg_get_week_ranges();

    foreach ($week_ranges as $range_key => $range_text) {
        $date_parts = explode('_', $range_key);
        $start_date = new DateTime($date_parts[0], new DateTimeZone('Europe/Rome'));
        $end_date   = (new DateTime($date_parts[1], new DateTimeZone('Europe/Rome')))->setTime(23, 59, 59);
        $current_week_stats = ['total_requests' => 0, 'preventivi_fatti' => 0, 'provenances' => []];

        if (!empty($all_cards)) {
            foreach ($all_cards as $card) {
                $card_date = stsg_get_card_creation_date($card['id']);
                if ($card_date && $card_date >= $start_date && $card_date <= $end_date) {
                    $current_week_stats['total_requests']++;
                    $provenance = wp_trello_normalize_provenance_value($card['provenance']);
                    if ($provenance) {
                        if (!isset($current_week_stats['provenances'][$provenance])) $current_week_stats['provenances'][$provenance] = 0;
                        $current_week_stats['provenances'][$provenance]++;
                    }
                    if (in_array(STSG_LABEL_PREVENTIVO, stsg_get_card_labels($card))) {
                        $current_week_stats['preventivi_fatti']++;
                    }
                }
            }
        }
        
        $current_week_stats['start_date'] = $start_date;
        $current_week_stats['end_date'] = $end_date;
        $all_weekly_stats[$range_text] = $current_week_stats;
    }

    $new_sheet_headers = stsg_get_fixed_headers();
    $data_rows_to_write = [];
    
    $trello_provenance_map = [
        'ItaliaOnline'     => 'iol',
        'Facebook'         => 'fb',
        'Google Ads'       => 'Google Ads',
        'Pool Industriale' => 'poolindustriale.it',
        'Organico (Sito)'  => 'Organic Search',
        'Chiamate'         => 'chiamate',
        'Taormina'         => 'taormina',
        'Subito'           => 'Subito',
        'Bakeka'           => 'Bakeka',
        'Archiexpo'        => 'archiexpo',
        'Europage'         => 'Europage',
    ];

    $cost_columns_map = wtcm_get_platform_cost_columns_map(); 

    foreach ($week_ranges as $range_key => $range_text) {
        $row_for_sheet = [];
        $stats_for_week = $all_weekly_stats[$range_text] ?? [];
        $existing_row_values = $existing_data_map[$range_text] ?? [];

        $calculated_preventivi_fatti = 0;
        try {
            $date_parts = explode('_', $range_key);
            $start_date_obj = new DateTime($date_parts[0], new DateTimeZone('Europe/Rome'));
            $end_date_obj = (new DateTime($date_parts[1], new DateTimeZone('Europe/Rome')))->setTime(23, 59, 59);
            $calculated_preventivi_fatti = stsg_get_total_quotes_for_date_range($sheets_service, $start_date_obj, $end_date_obj);
        } catch (Exception $e) {
            error_log("STSG Rebuild Error: Errore nel calcolo Preventivi Fatti per settimana {$range_text}: " . $e->getMessage());
        }


        foreach ($new_sheet_headers as $header_col_name) {
            switch ($header_col_name) {
                case 'Settimana':
                    $row_for_sheet[] = $range_text;
                    break;
                case 'Settimana Chiave':
                    $row_for_sheet[] = $range_key;
                    break;
                case 'Totale Richieste':
                    $row_for_sheet[] = $stats_for_week['total_requests'] ?? 0;
                    break;
                case 'Preventivi fatti':
                    $row_for_sheet[] = $calculated_preventivi_fatti;
                    break;
                default:
                    if (strpos($header_col_name, 'Costo') === 0) {
                        $cost_value_to_add = 0.0;
                        if (!empty($existing_sheet_headers) && !empty($existing_row_values)) {
                            $col_index_in_existing = array_search($header_col_name, $existing_sheet_headers);
                            if ($col_index_in_existing !== false && isset($existing_row_values[$col_index_in_existing])) {
                                $cost_value_raw = trim($existing_row_values[$col_index_in_existing]);
                                $cleaned_cost_value = str_replace(['€', '.'], '', $cost_value_raw);
                                $cleaned_cost_value = str_replace(',', '.', $cleaned_cost_value);
                                $cost_value_to_add = floatval($cleaned_cost_value);
                                error_log("STSG Rebuild: Costo esistente per '{$range_text}', colonna '{$header_col_name}': '{$cost_value_raw}' -> " . $cost_value_to_add);
                            }
                        }
                        $row_for_sheet[] = $cost_value_to_add;
                    } else {
                        $provenance_key = $trello_provenance_map[$header_col_name] ?? $header_col_name;
                        $row_for_sheet[] = ($stats_for_week && isset($stats_for_week['provenances'][$provenance_key])) ? $stats_for_week['provenances'][$provenance_key] : 0;
                    }
                    break;
            }
        }
        $data_rows_to_write[] = $row_for_sheet;
    }

    try {
        $clear_range = STSG_SHEET_NAME . '!A:Z';
        $clear_body = new Google_Service_Sheets_ClearValuesRequest();
        $sheets_service->spreadsheets_values->clear(STSG_SPREADSHEET_ID, $clear_range, $clear_body);
        error_log("STSG Rebuild: Foglio " . STSG_SHEET_NAME . " pulito.");

        $header_body = new Google_Service_Sheets_ValueRange(['values' => [$new_sheet_headers]]);
        $sheets_service->spreadsheets_values->update(STSG_SPREADSHEET_ID, STSG_SHEET_NAME . '!A1', $header_body, ['valueInputOption' => 'RAW']);
        error_log("STSG Rebuild: Intestazioni scritte.");

        if (!empty($data_rows_to_write)) {
            $data_body = new Google_Service_Sheets_ValueRange(['values' => $data_rows_to_write]);
            $sheets_service->spreadsheets_values->update(STSG_SPREADSHEET_ID, STSG_SHEET_NAME . '!A2', $data_body, ['valueInputOption' => 'USER_ENTERED']);
            error_log("STSG Rebuild: " . count($data_rows_to_write) . " righe di dati scritte.");
        } else {
            error_log("STSG Rebuild: Nessuna riga di dati da scrivere.");
        }
        return count($data_rows_to_write);
    } catch (Exception $e) {
        error_log("STSG Rebuild Error: Errore durante la scrittura finale sul foglio: " . $e->getMessage());
        return new WP_Error('google_api_error', $e->getMessage());
    }
}

function stsg_update_single_week($sheets_service, $week_range_key) {
    $all_cards = stsg_get_all_cards_for_sheet();

    $date_parts = explode('_', $week_range_key);
    $start_date = new DateTime($date_parts[0], new DateTimeZone('Europe/Rome'));
    $end_date = (new DateTime($date_parts[1], new DateTimeZone('Europe/Rome')))->setTime(23, 59, 59);

    $week_ranges = stsg_get_week_ranges();
    $week_identifier_text = $week_ranges[$week_range_key] ?? null;

    if (!$week_identifier_text) {
        return new WP_Error('invalid_week_key', 'Chiave settimana non valida.');
    }

    $stats = ['total_requests' => 0, 'preventivi_fatti' => 0, 'provenances' => []];
    if (!empty($all_cards)) {
        foreach ($all_cards as $card) {
            $card_date = stsg_get_card_creation_date($card['id']);
            if ($card_date && $card_date >= $start_date && $card_date <= $end_date) {
                $stats['total_requests']++;
                $provenance = wp_trello_normalize_provenance_value($card['provenance']);
                if ($provenance) {
                    if (!isset($stats['provenances'][$provenance])) $stats['provenances'][$provenance] = 0;
                    $stats['provenances'][$provenance]++;
                }
                if (in_array(STSG_LABEL_PREVENTIVO, stsg_get_card_labels($card))) {
                    $stats['preventivi_fatti']++;
                }
            }
        }
    }
    
    $headers = stsg_get_fixed_headers();
    $row_to_write = [];

    $trello_provenance_map = [
        'ItaliaOnline'     => 'iol',
        'Facebook'         => 'fb',
        'Google Ads'       => 'Google Ads',
        'Pool Industriale' => 'poolindustriale.it',
        'Organico (Sito)'  => 'Organic Search',
        'Chiamate'         => 'chiamate',
        'Taormina'         => 'taormina',
        'Subito'           => 'Subito',
        'Bakeka'           => 'Bakeka',
        'Archiexpo'        => 'archiexpo',
        'Europage'         => 'Europage',
    ];

    $temp_stsg_client = new STSG_Google_Sheets_Client();
    $temp_sheets_service = $temp_stsg_client->getService();

    $existing_costs_for_week = [];
    if (!is_wp_error($temp_sheets_service)) {
        $existing_costs_for_week = wtcm_get_costs_from_sheet($temp_sheets_service, $week_range_key);
        if (is_wp_error($existing_costs_for_week)) {
            error_log("STSG Update Single Week: Impossibile recuperare i costi esistenti per la settimana {$week_identifier_text}: " . $existing_costs_for_week->get_error_message());
            $existing_costs_for_week = [];
        }
    } else {
        error_log("STSG Update Single Week: Impossibile inizializzare il client per recuperare i costi esistenti.");
    }

    $cost_columns_map = wtcm_get_platform_cost_columns_map();

    $calculated_preventivi_fatti = 0;
    try {
        $calculated_preventivi_fatti = stsg_get_total_quotes_for_date_range($sheets_service, $start_date, $end_date);
    } catch (Exception $e) {
        error_log("STSG Update Single Week Error: Errore nel calcolo Preventivi Fatti per settimana {$week_identifier_text}: " . $e->getMessage());
    }


    foreach ($headers as $header_col_name) {
        switch ($header_col_name) {
            case 'Settimana':
                $row_to_write[] = $week_identifier_text;
                break;
            case 'Settimana Chiave':
                $row_to_write[] = $week_range_key;
                break;
            case 'Totale Richieste':
                $row_to_write[] = $stats['total_requests'] ?? 0;
                break;
            case 'Preventivi fatti':
                $row_to_write[] = $calculated_preventivi_fatti;
                break;
            default:
                if (strpos($header_col_name, 'Costo') === 0) {
                    $prov_key_for_cost = array_search($header_col_name, $cost_columns_map);
                    if ($prov_key_for_cost && isset($existing_costs_for_week[$prov_key_for_cost])) {
                        $row_to_write[] = $existing_costs_for_week[$prov_key_for_cost];
                    } else {
                        $row_to_write[] = 0.0;
                    }
                } else {
                    $provenance_key = $trello_provenance_map[$header_col_name] ?? $header_col_name;
                    $row_to_write[] = ($stats && isset($stats['provenances'][$provenance_key])) ? $stats['provenances'][$provenance_key] : 0;
                }
                break;
        }
    }

    try {
        // CORREZIONE: Leggi le colonne A e B per trovare la riga corretta usando la chiave.
        $range = STSG_SHEET_NAME . '!A:B';
        $response = $sheets_service->spreadsheets_values->get(STSG_SPREADSHEET_ID, $range);
        $column_data = $response->getValues();
        $row_number = false;
        if ($column_data) {
            foreach ($column_data as $idx => $row) {
                if ($idx === 0) continue; // Salta la riga delle intestazioni
                // Cerca la corrispondenza nella seconda colonna (indice 1), che è 'Settimana Chiave'
                if (isset($row[1]) && trim($row[1]) == $week_range_key) {
                    $row_number = $idx + 1; // Il numero di riga nel foglio è l'indice + 1
                    break;
                }
            }
        }
        
        $body = new Google_Service_Sheets_ValueRange(['values' => [$row_to_write]]);
        $params = ['valueInputOption' => 'USER_ENTERED'];

        if ($row_number) {
            $update_range = STSG_SHEET_NAME . '!A' . $row_number;
            $sheets_service->spreadsheets_values->update(STSG_SPREADSHEET_ID, $update_range, $body, $params);
            $action_taken = 'aggiornata';
        } else {
            // Questo caso non dovrebbe verificarsi se si usa prima la ricostruzione,
            // ma per sicurezza aggiungiamo la riga se non trovata.
            $sheets_service->spreadsheets_values->append(STSG_SPREADSHEET_ID, STSG_SHEET_NAME, $body, $params);
            $action_taken = 'aggiunta (riga non trovata)';
        }

        return ['action' => $action_taken, 'data_row' => $row_to_write];
    } catch (Exception $e) {
        return new WP_Error('google_api_error', $e->getMessage());
    }
}


/**
 * Orchestratore per le diverse azioni (ricostruzione totale o aggiornamento singolo).
 */
function stsg_orchestrate_sheet_generation() {
    echo '<h1>Risultato del processo</h1>';

    if (!isset($_POST['stsg_action']) || empty($_POST['stsg_action'])) {
        echo '<p style="color: red;"><strong>ERRORE:</strong> Azione non specificata.</p>';
        return;
    }
    $action = sanitize_text_field($_POST['stsg_action']);

    if (!isset($_POST['stsg_nonce']) || !wp_verify_nonce($_POST['stsg_nonce'], 'stsg_generate_sheet_nonce')) {
        echo '<p style="color: red;"><strong>ERRORE:</strong> Errore di sicurezza (nonce non valido). Riprova.</p>';
        return;
    }

    $stsg_client = new STSG_Google_Sheets_Client();
    $sheets_service = $stsg_client->getService();

    if (is_wp_error($sheets_service)) {
        echo '<p style="color: red;"><strong>Errore:</strong> ' . $sheets_service->get_error_message() . '</p>';
        return;
    }
    echo '<p style="color: green;">✅ Connessione a Google Sheets riuscita!</p>';

    if ($action === 'full_rebuild') {
        echo '<p>Avvio della ricostruzione completa dello sheet...</p>';
        $result = stsg_full_rebuild_sheet($sheets_service);
        if (is_wp_error($result)) {
            echo '<p style="color: red;"><strong>Errore:</strong> ' . $result->get_error_message() . '</p>';
            if ($result->get_error_code() == 'google_api_error' && strpos($result->get_error_message(), 'Permesso negato') !== false) {
                echo '<p style="color: red;">Assicurati di aver condiviso il Google Sheet con l\'indirizzo email dell\'account di servizio: <strong>' . esc_html($stsg_client->getServiceAccountEmail()) . '</strong></p>';
            }
        } else {
            echo '<p style="color: green;">✅ Sheet ricostruito con successo con ' . $result . ' righe di dati. I costi esistenti sono stati mantenuti. La colonna "Preventivi fatti" è stata aggiornata con i dati dei fogli preventivi mensili.</p>';
        }
    } elseif ($action === 'update_week') {
        if (!isset($_POST['stsg_selected_week']) || empty($_POST['stsg_selected_week'])) {
            echo '<p style="color: red;"><strong>ERRORE:</strong> Devi selezionare una settimana per l\'aggiornamento.</p>';
            return;
        }
        $selected_week = sanitize_text_field($_POST['stsg_selected_week']);
        echo "<p>Avvio aggiornamento per la settimana <strong>{$selected_week}</strong>...</p>";
        $result = stsg_update_single_week($sheets_service, $selected_week);
        if (is_wp_error($result)) {
            echo '<p style="color: red;"><strong>Errore:</strong> ' . $result->get_error_message() . '</p>';
            if ($result->get_error_code() == 'google_api_error' && strpos($result->get_error_message(), 'Permesso negato') !== false) {
                echo '<p style="color: red;">Assicurati di aver condiviso il Google Sheet con l\'indirizzo email dell\'account di servizio: <strong>' . esc_html($stsg_client->getServiceAccountEmail()) . '</strong></p>';
            }
        } else {
            echo '<p style="color: green;">✅ Settimana ' . $result['action'] . ' con successo! Richieste, preventivi e "Preventivi fatti" aggiornati, costi esistenti mantenuti.</p>';
            echo '<p><strong>Dati:</strong> ' . esc_html(implode(', ', $result['data_row'])) . '</p>';
        }
    }
}


/**
 * Mostra una UI con due sezioni per le due diverse azioni.
 */
function stsg_render_admin_page() {
    ?>
    <div class="wrap">
        <h2>Genera Google Sheet con Dati Trello</h2>
        <p>Usa gli strumenti qui sotto per popolare il tuo Google Sheet.</p>
        
        <hr>

        <div id="stsg-update-week">
            <h3>Aggiorna una singola settimana</h3>
            <p>Scegli una settimana per aggiungere i suoi dati allo sheet o per aggiornarli se già presenti.</p>
            <form method="post" action="">
                <input type="hidden" name="stsg_action" value="update_week">
                <?php wp_nonce_field('stsg_generate_sheet_nonce', 'stsg_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="stsg_selected_week">Seleziona settimana</label></th>
                        <td>
                            <select name="stsg_selected_week" id="stsg_selected_week" required>
                                <option value="">-- Cerca un periodo --</option>
                                <?php foreach (stsg_get_week_ranges() as $key => $text) : ?>
                                    <option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($text); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Aggiungi / Aggiorna Settimana'); ?>
            </form>
        </div>
        
        <hr>

        <div id="stsg-full-rebuild">
            <h3>Ricostruisci l'intero Sheet</h3>
            <p style="border-left: 4px solid #d63638; padding-left: 10px;">
                <strong>Attenzione:</strong> Questa azione **aggiornerà** i dati di richieste e preventivi dalle schede Trello per tutte le settimane. **I costi che hai inseriti manualmente sul foglio Google Richieste verranno preservati.** La colonna "Preventivi fatti" verrà ricalcolata basandosi sui fogli "Preventivi-[Mese]-[Anno]".
            </p>
            <form method="post" action="">
                <input type="hidden" name="stsg_action" value="full_rebuild">
                <?php wp_nonce_field('stsg_generate_sheet_nonce', 'stsg_nonce'); ?>
                <?php submit_button('Ricostruisci Intero Sheet', 'primary large'); ?>
            </form>
        </div>

        <hr>

        <?php
        if (isset($_POST['stsg_action']) && isset($_POST['stsg_nonce']) && wp_verify_nonce($_POST['stsg_nonce'], 'stsg_generate_sheet_nonce')) {
            stsg_orchestrate_sheet_generation();
        }
        ?>
    </div>
    <?php
}
