<?php
/**
 * File: trello-quotes-sheet-generator.php
 * Descrizione: Genera e popola un foglio Google "Preventivi" basato sui dati di Fatture in Cloud.
 * Author: Emanuele Tolomei
 */

if (!defined('ABSPATH')) exit;

// Includi il client Google Sheets (riutilizziamo quello del modulo Sheet Generator)
include_once plugin_dir_path(__FILE__) . 'class-stsg-google-sheets-client.php';
// Includi le funzioni helper del generatore di sheet, per stsg_translate_month_to_italian e stsg_get_card_creation_date
include_once plugin_dir_path(__FILE__) . 'functions.php';

// Include trello-helpers.php per le funzioni Trello API (get_trello_cards)
include_once ABSPATH . 'wp-content/plugins/statistiche-trello/trello-helpers.php';

// Includi il file di configurazione che contiene le costanti
include_once plugin_dir_path(__FILE__) . 'trello-quotes-sheet-config.php';

/**
 * Mappatura lettera nel preventivo -> nome utente sul foglio
 */
function wtqsg_get_user_map() {
    return [
        'J' => 'Jalall',
        'N' => 'Noemi',
        'S' => 'Sonia',
        'M' => 'Martina',
        'I' => 'Isabella',
        'V' => 'Valentina',
        'L' => 'Sandro',
        'A' => 'Antonio',
        'F' => 'Fabrizio',
        'AU' => 'Automatico',
    ];
}

/**
 * Restituisce le intestazioni fisse per il foglio "Preventivi".
 */
function wtqsg_get_quotes_sheet_headers() {
    $headers = ['Giorno'];
    foreach (wtqsg_get_user_map() as $code => $name) {
        $headers[] = $name;
    }
    $headers[] = 'Totale Preventivi';
    $headers[] = 'Richieste ricevute';
    $headers[] = 'Richieste da Chiarire';
    $headers[] = 'Schede incagliate';
    $headers[] = 'Preventivi effettivi da fare';
    return $headers;
}

/**
 * Funzione per effettuare chiamate all'API di Fatture in Cloud.
 */
function wtqsg_call_fic_api($endpoint, $params = []) {
    $url = "https://api-v2.fattureincloud.it/c/{companyId}{$endpoint}";
    $url = str_replace('{companyId}', FIC_COMPANY_ID, $url);

    $headers = [
        'Authorization' => 'Bearer ' . FIC_PERSONAL_TOKEN,
        'Content-Type'  => 'application/json',
    ];

    if (!empty($params)) {
        $url .= '?' . http_build_query($params);
    }

    error_log("WTQSG FIC API Debug: Richiesta URL: " . $url);

    $response = wp_remote_get($url, [
        'headers' => $headers,
        'timeout' => 30,
    ]);

    if (is_wp_error($response)) {
        error_log('WTQSG FIC API Error: ' . $response->get_error_message());
        return new WP_Error('fic_api_error', 'Errore API Fatture in Cloud: ' . $response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (wp_remote_retrieve_response_code($response) !== 200) {
        error_log('WTQSG FIC API HTTP Error (' . wp_remote_retrieve_response_code($response) . '): ' . $body);
        $api_error_message = 'Errore sconosciuto';
        if (is_array($data) && isset($data['error']) && isset($data['error']['message'])) {
            $api_error_message = $data['error']['message'];
        } elseif (is_string($body)) {
            $api_error_message = $body;
        }

        return new WP_Error('fic_api_http_error', 'Errore HTTP Fatture in Cloud (' . wp_remote_retrieve_response_code($response) . '): ' . $api_error_message);
    }

    return $data;
}

/**
 * Recupera i preventivi da Fatture in Cloud per un dato mese e anno, con opzione per un giorno specifico.
 */
function wtqsg_get_quotes_from_fic($year, $month, $day = null) {
    set_time_limit(600);
    ini_set('memory_limit', '512M');
    $quotes_data = [];
    $page = 1;
    $per_page = 100;
    $last_page = 1;
    $total_quotes_found = 0;

    $query_filter = '';
    if ($day) {
        $date_str = sprintf('%04d-%02d-%02d', $year, $month, $day);
        $query_filter = "date = '{$date_str}'";
    } else {
        $start_date_str = "{$year}-{$month}-01";
        $end_date_str = "{$year}-{$month}-" . date('t', strtotime($start_date_str));
        $query_filter = "date >= '{$start_date_str}' and date <= '{$end_date_str}'";
    }

    while ($page <= $last_page) {
        $params = [
            'type'      => 'quote',
            'per_page'  => $per_page,
            'page'      => $page,
            'q'         => $query_filter,
        ];
        $response = wtqsg_call_fic_api('/issued_documents', $params);
        if (is_wp_error($response)) {
            error_log("WTQSG FIC API: Errore nel recupero pagina {$page}: " . $response->get_error_message());
            return $response;
        }
        $current_page_from_api = $response['current_page'] ?? 'N/A';
        $last_page_from_api = $response['last_page'] ?? 1;
        $total_from_api = $response['total'] ?? 0;
        error_log("WTQSG FIC API Debug: Paginazione - Page: {$page}, Current API Page: {$current_page_from_api}, Last API Page: {$last_page_from_api}, Total: {$total_from_api}, Per Page: " . ($response['per_page'] ?? 'N/A'));
        if ($page === 1) {
            $last_page = $last_page_from_api;
            $total_quotes_found = $total_from_api;
            error_log("WTQSG FIC API Debug: Inizializzata paginazione: Totale preventivi previsti: {$total_quotes_found}, Pagine totali previste: {$last_page}.");
            if ((int)$total_quotes_found === 0) {
                error_log("WTQSG FIC API Debug: Totale preventivi dichiarato 0. Nessun dato da recuperare.");
                break;
            }
        }
        if (empty($response['data'])) {
            error_log("WTQSG FIC API Debug: Pagina {$page} restituita vuota. Fine del recupero.");
            break;
        } else {
            foreach ($response['data'] as $doc) {
                $quotes_data[] = $doc;
            }
            $page++;
            sleep(1);
        }
    }
    error_log("WTQSG FIC API Debug: Recuperati " . count($quotes_data) . " preventivi totali per il filtro '{$query_filter}'.");
    if (count($quotes_data) !== (int)$total_quotes_found) {
        error_log("WTQSG FIC API Warning: Il numero di preventivi recuperati (" . count($quotes_data) . ") non corrisponde al totale API dichiarato ({$total_quotes_found}).");
    }
    return $quotes_data;
}

/**
 * Recupera le schede Trello da liste specifiche in una board, filtrate per lo stato attuale 'open'.
 */
function wtqsg_get_current_open_cards_in_lists($board_id, $list_ids, $api_key, $api_token) {
    $current_open_cards = [];
    $all_cards_from_board = get_trello_cards($board_id, $api_key, $api_token);
    if (is_wp_error($all_cards_from_board)) {
        error_log("WTQSG Trello Error: Impossibile recuperare schede dalla board {$board_id}: " . $all_cards_from_board->get_error_message());
        return $all_cards_from_board;
    }
    if (!empty($all_cards_from_board)) {
        foreach ($all_cards_from_board as $card) {
            if (!$card['closed'] && isset($card['idList']) && in_array($card['idList'], $list_ids)) {
                $current_open_cards[] = $card;
            }
        }
    }
    return $current_open_cards;
}

/**
 * Recupera le schede Trello da una board specifica, filtrate per lo stato attuale 'open' e un nome di lista.
 */
function wtqsg_get_current_open_cards_in_list($board_id, $list_id, $api_key, $api_token) {
    $cards = [];
    $all_cards_from_board = get_trello_cards($board_id, $api_key, $api_token);
    if (is_wp_error($all_cards_from_board)) {
        return $all_cards_from_board;
    }
    if (!empty($all_cards_from_board)) {
        foreach ($all_cards_from_board as $card) {
            if (!$card['closed'] && isset($card['idList']) && $card['idList'] === $list_id) {
                $cards[] = $card;
            }
        }
    }
    return $cards;
}

/**
 * Recupera le schede Trello da più bacheche e liste per un conteggio combinato.
 */
function wtqsg_get_total_open_cards_from_multiple_lists($board_list_pairs, $api_key, $api_token) {
    $total_count = 0;
    foreach ($board_list_pairs as $pair) {
        $board_id = $pair[0];
        $list_id = $pair[1];
        $cards = wtqsg_get_current_open_cards_in_list($board_id, $list_id, $api_key, $api_token);
        if (!is_wp_error($cards)) {
            $total_count += count($cards);
        } else {
            error_log("WTQSG Trello Error: Impossibile recuperare schede dalla lista {$list_id} nella bacheca {$board_id}: " . $cards->get_error_message());
        }
    }
    return $total_count;
}

/**
 * Recupera i commenti per una specifica scheda Trello.
 */
function wtqsg_get_card_comments($card_id, $api_key, $api_token) {
    $url = "https://api.trello.com/1/cards/{$card_id}/actions?filter=commentCard&key={$api_key}&token={$api_token}";
    
    $response = wp_remote_get($url, ['timeout' => 30]);

    if (is_wp_error($response)) {
        error_log("WTQSG Trello Comments Error: " . $response->get_error_message());
        return new WP_Error('trello_api_error', 'Errore API Trello nel recupero commenti: ' . $response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);

    if (wp_remote_retrieve_response_code($response) !== 200) {
        error_log('WTQSG Trello Comments HTTP Error (' . wp_remote_retrieve_response_code($response) . '): ' . $body);
        return new WP_Error('trello_api_http_error', 'Errore HTTP Trello nel recupero commenti (' . wp_remote_retrieve_response_code($response) . ')');
    }

    return $data;
}


/**
 * Calcola i "Preventivi effettivi da fare" secondo la nuova logica richiesta.
 * Ricalcola la somma delle schede della board ufficio preventivi
 * e delle liste "intervento agente" senza link a FIC.
 * @param string $trello_api_key La chiave API di Trello.
 * @param string $trello_api_token Il token API di Trello.
 * @return int|WP_Error Il numero totale di schede o un oggetto WP_Error.
 */
function wtqsg_calculate_effective_quotes($trello_api_key, $trello_api_token) {
    $total_effective_quotes = 0;
    
    if (empty($trello_api_key) || empty($trello_api_token)) {
        return new WP_Error('trello_api_error', 'API key o token di Trello mancanti.');
    }

    // 1. Somma tutte le schede della board "02_UFFICIO PREVENTIVI"
    $ufficio_preventivi_cards = get_trello_cards(TRELLO_BOARD_ID_UFFICIO_PREVENTIVI, $trello_api_key, $trello_api_token);
    if (!is_wp_error($ufficio_preventivi_cards)) {
        foreach ($ufficio_preventivi_cards as $card) {
            if (!$card['closed']) { // Conta solo le schede aperte
                $total_effective_quotes++;
            }
        }
    } else {
        return $ufficio_preventivi_cards; // Restituisci l'errore se la chiamata fallisce
    }

    // 2. Aggiungi le schede delle liste "INTERVENTO AGENTE" (escludendo quelle con link FIC)
    $intervento_lists_and_boards = [
        [TRELLO_BOARD_ID_AGENTE_ANTONIO, TRELLO_LIST_ID_AGENTE_ANTONIO_INTERVENTO],
        [TRELLO_BOARD_ID_AGENTE_GALLI, TRELLO_LIST_ID_AGENTE_GALLI_INTERVENTO],
        [TRELLO_BOARD_ID_AGENTE_ISABELLA, TRELLO_LIST_ID_AGENTE_ISABELLA_INTERVENTO],
        [TRELLO_BOARD_ID_AGENTE_JALALL, TRELLO_LIST_ID_AGENTE_JALALL_INTERVENTO],
        [TRELLO_BOARD_ID_AGENTE_MARCO, TRELLO_LIST_ID_AGENTE_MARCO_INTERVENTO],
        [TRELLO_BOARD_ID_AGENTE_NOEMI, TRELLO_LIST_ID_AGENTE_NOEMI_INTERVENTO],
        [TRELLO_BOARD_ID_AGENTE_TONACCI, TRELLO_LIST_ID_AGENTE_TONACCI_INTERVENTO],
    ];
    
    foreach ($intervento_lists_and_boards as $pair) {
        $board_id = $pair[0];
        $list_id = $pair[1];
        
        $cards_in_list = wtqsg_get_current_open_cards_in_list($board_id, $list_id, $trello_api_key, $trello_api_token);
        
        if (!is_wp_error($cards_in_list)) {
            foreach ($cards_in_list as $card) {
                $has_fic_link = false;
                $comments = wtqsg_get_card_comments($card['id'], $trello_api_key, $trello_api_token);
                usleep(250000); // Pausa per API
                if (!is_wp_error($comments) && is_array($comments)) {
                    foreach ($comments as $comment) {
                        if (isset($comment['data']['text']) && strpos($comment['data']['text'], 'Preventivo: https://compute.fattureincloud.it/') !== false) {
                            $has_fic_link = true;
                            break;
                        }
                    }
                }
                if (!$has_fic_link) {
                    $total_effective_quotes++;
                }
            }
        } else {
            error_log("WTQSG Trello Error: Impossibile recuperare schede dalla lista {$list_id} nella bacheca {$board_id}: " . $cards_in_list->get_error_message());
        }
        usleep(500000); // Pausa tra le liste/bacheche
    }

    return $total_effective_quotes;
}


/**
 * Funzione che esegue l'aggiornamento completo del foglio, rispettando la logica di sovrascrittura selettiva.
 * @param Google_Service_Sheets $sheets_service
 * @param bool $is_monthly_job Indica se la chiamata proviene dal cron mensile o da un'azione manuale.
 * @return string|WP_Error
 */
function wtqsg_rebuild_and_update_all_data($sheets_service, $is_monthly_job = false) {
    $year = (int)date('Y');
    $month = (int)date('m');
    $current_day_of_month = (int)date('d');
    $month_name_it = stsg_translate_month_to_italian(date('F'));
    $sheet_name_with_month = WTQSG_BASE_SHEET_NAME . '-' . ucfirst($month_name_it) . '-' . $year;
    
    // --- 1. Recupera i dati esistenti dal foglio per preservare le colonne non-sovrascritte ---
    $existing_data_map = [];
    $existing_sheet_headers = [];
    try {
        $range_to_read = $sheet_name_with_month . '!A:Z';
        $response = $sheets_service->spreadsheets_values->get(WTQSG_SPREADSHEET_ID, $range_to_read);
        $values = $response->getValues();
        
        if (!empty($values)) {
            $existing_sheet_headers = array_map('trim', array_shift($values));
            foreach ($values as $row_data) {
                $day_label_from_sheet = trim($row_data[0] ?? '');
                if (!empty($day_label_from_sheet)) {
                    $existing_data_map[$day_label_from_sheet] = $row_data;
                }
            }
        }
    } catch (Exception $e) {
        error_log("WTQSG Rebuild Error: Impossibile leggere i dati esistenti: " . $e->getMessage());
    }

    // --- 2. Recupera tutti i dati necessari per il ricalcolo ---
    $quotes = wtqsg_get_quotes_from_fic($year, $month);
    if (is_wp_error($quotes)) {
        return new WP_Error('fic_api_error', 'Fallito recupero preventivi da FIC: ' . $quotes->get_error_message());
    }
    $trello_api_key = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : null;
    $trello_api_token = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : null;

    // Con questo ricalcolo avremo i dati per ogni giorno del mese
    $trello_counts_by_day = [];
    global $wpdb;
    $cards_cache_table = STPA_CARDS_CACHE_TABLE;
    $all_numeric_cards = $wpdb->get_results(
        $wpdb->prepare("SELECT card_id FROM {$cards_cache_table} WHERE is_numeric_name = %d", 1), 
        ARRAY_A
    );
    if (!empty($all_numeric_cards)) {
        foreach($all_numeric_cards as $card) {
            $creation_date = stsg_get_card_creation_date($card['card_id']);
            if ($creation_date && (int)$creation_date->format('Y') === $year && (int)$creation_date->format('m') === $month) {
                $day = (int)$creation_date->format('d');
                $trello_counts_by_day[$day] = ($trello_counts_by_day[$day] ?? 0) + 1;
            }
        }
    }

    // --- 3. Prepara i dati per la scrittura nel foglio, mescolando i vecchi e i nuovi valori ---
    $headers = wtqsg_get_quotes_sheet_headers();
    $user_map = wtqsg_get_user_map();
    $user_names = array_values($user_map);
    $final_data_for_sheet = [$headers];
    $days_in_month = date('t', strtotime("{$year}-{$month}-01"));

    for ($i = 1; $i <= $days_in_month; $i++) {
        $date_obj_for_format = new DateTime("{$year}-{$month}-{$i}");
        $day_label = stsg_translate_month_to_italian($date_obj_for_format->format('d F'));
        $row_data = [];
        $header_to_index_map = array_flip($headers);

        // Calcola i conteggi per gli utenti e il totale dei preventivi per questo giorno
        $fic_quotes_by_user = array_fill_keys($user_names, 0);
        $total_fic_quotes = 0;
        foreach ($quotes as $quote) {
            if (!isset($quote['date']) || empty($quote['date'])) continue;
            $quote_date_obj = new DateTime($quote['date']);
            if ((int)$quote_date_obj->format('Y') === $year && (int)$quote_date_obj->format('m') === $month && (int)$quote_date_obj->format('d') === $i) {
                $identifier_string = trim($quote['numeration'] ?? $quote['number'] ?? '');
                if (empty($identifier_string)) continue;
                $assigned_user_name = 'Automatico';
                if (strtoupper(substr($identifier_string, 0, 2)) === 'AU') {
                    $assigned_user_name = 'Automatico';
                } elseif (preg_match('/^\d*([A-Z])/', $identifier_string, $matches_letter) && isset($matches_letter[1])) {
                    $extracted_code = strtoupper($matches_letter[1]);
                    $assigned_user_name = $user_map[$extracted_code] ?? 'Automatico';
                }
                $fic_quotes_by_user[$assigned_user_name]++;
                $total_fic_quotes++;
            }
        }
        
        // Calcola i dati "snapshot" solo per oggi. Per i giorni passati li manteniamo.
        $requests_to_clarify_current = 0;
        $stalled_cards_current = 0;
        $effective_quotes_to_do_current = 0;
        if ($i === $current_day_of_month || $is_monthly_job) {
             $requests_to_clarify_current = wtqsg_get_total_open_cards_from_multiple_lists([ [TRELLO_BOARD_ID_GESTIONALE, TRELLO_LIST_ID_RICHIESTE_CHIARIRE_2] ], $trello_api_key, $trello_api_token) + wtqsg_get_total_open_cards_from_multiple_lists([ [TRELLO_BOARD_ID_AGENTE_ANTONIO, TRELLO_LIST_ID_AGENTE_ANTONIO_CHIARIRE], [TRELLO_BOARD_ID_AGENTE_GALLI, TRELLO_LIST_ID_AGENTE_GALLI_CHIARIRE], [TRELLO_BOARD_ID_AGENTE_ISABELLA, TRELLO_LIST_ID_AGENTE_ISABELLA_CHIARIRE], [TRELLO_BOARD_ID_AGENTE_JALALL, TRELLO_LIST_ID_AGENTE_JALALL_CHIARIRE], [TRELLO_BOARD_ID_AGENTE_MARCO, TRELLO_LIST_ID_AGENTE_MARCO_CHIARIRE], [TRELLO_BOARD_ID_AGENTE_NOEMI, TRELLO_LIST_ID_AGENTE_NOEMI_CHIARIRE], [TRELLO_BOARD_ID_AGENTE_TONACCI, TRELLO_LIST_ID_AGENTE_TONACCI_CHIARIRE], ], $trello_api_key, $trello_api_token);
             $stalled_cards_current = wtqsg_get_total_open_cards_from_multiple_lists([ [TRELLO_BOARD_ID_GESTIONALE, TRELLO_LIST_ID_RICHIESTE_INCAGLIATE_1], [TRELLO_BOARD_ID_GESTIONALE, TRELLO_LIST_ID_RICHIESTE_INCAGLIATE_2] ], $trello_api_key, $trello_api_token);
             $effective_quotes_to_do_current = wtqsg_calculate_effective_quotes($trello_api_key, $trello_api_token);
        } else {
            // Per i giorni passati, recupera i dati esistenti
            $existing_row_data = $existing_data_map[$day_label] ?? [];
            $requests_to_clarify_current = $existing_row_data[$header_to_index_map['Richieste da Chiarire']] ?? 0;
            $stalled_cards_current = $existing_row_data[$header_to_index_map['Schede incagliate']] ?? 0;
            $effective_quotes_to_do_current = $existing_row_data[$header_to_index_map['Preventivi effettivi da fare']] ?? 0;
        }

        foreach ($headers as $col_idx => $header_name) {
            switch ($header_name) {
                case 'Giorno': $row_data[] = $day_label; break;
                case 'Totale Preventivi': $row_data[] = $total_fic_quotes; break;
                case 'Richieste ricevute': $row_data[] = $trello_counts_by_day[$i] ?? 0; break;
                case 'Richieste da Chiarire': $row_data[] = $requests_to_clarify_current; break;
                case 'Schede incagliate': $row_data[] = $stalled_cards_current; break;
                case 'Preventivi effettivi da fare': $row_data[] = $effective_quotes_to_do_current; break;
                default:
                    if (in_array($header_name, $user_names)) {
                        $row_data[] = $fic_quotes_by_user[$header_name];
                    } else {
                        // Per le colonne non definite, manteniamo il dato esistente se presente
                        $row_data[] = $existing_data_map[$day_label][$col_idx] ?? '';
                    }
                    break;
            }
        }
        $final_data_for_sheet[] = $row_data;
    }

    // --- 4. Scrivi i dati aggiornati sul foglio Google ---
    try {
        $spreadsheet_properties = $sheets_service->spreadsheets->get(WTQSG_SPREADSHEET_ID, ['fields' => 'sheets.properties']);
        $existing_sheet_id = null;
        foreach ($spreadsheet_properties->getSheets() as $sheet) {
            if ($sheet->getProperties()->getTitle() === $sheet_name_with_month) {
                $existing_sheet_id = $sheet->getProperties()->getSheetId();
                break;
            }
        }

        if ($existing_sheet_id === null) {
            $add_sheet_request = new Google_Service_Sheets_AddSheetRequest(['properties' => ['title' => $sheet_name_with_month]]);
            $batch_update_request_create = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => ['addSheet' => $add_sheet_request]]);
            $response_batch_update = $sheets_service->spreadsheets->batchUpdate(WTQSG_SPREADSHEET_ID, $batch_update_request_create);
            $final_sheet_id = $response_batch_update->getReplies()[0]->getAddSheet()->getProperties()->getSheetId();
            sleep(5);
        } else {
            $final_sheet_id = $existing_sheet_id;
        }

        $data_body = new Google_Service_Sheets_ValueRange(['values' => $final_data_for_sheet]);
        $sheets_service->spreadsheets_values->update(WTQSG_SPREADSHEET_ID, $sheet_name_with_month . '!A1', $data_body, ['valueInputOption' => 'USER_ENTERED']);

        if ($final_sheet_id !== null) {
            $requests = [
                new Google_Service_Sheets_Request(['repeatCell' => [ 'range' => ['sheetId' => $final_sheet_id, 'endRowIndex' => 1, 'endColumnIndex' => count($headers)], 'cell' => ['userEnteredFormat' => ['backgroundColor' => ['red' => 0.85, 'green' => 0.95, 'blue' => 0.85], 'textFormat' => ['bold' => true], 'horizontalAlignment' => 'CENTER']], 'fields' => 'userEnteredFormat(backgroundColor,textFormat,horizontalAlignment)']]),
                new Google_Service_Sheets_Request(['autoResizeDimensions' => ['dimensions' => ['sheetId' => $final_sheet_id, 'dimension' => 'COLUMNS', 'endIndex' => count($headers)]]])
            ];
            $batch_update_request_format = new Google_Service_Sheets_BatchUpdateSpreadsheetRequest(['requests' => $requests]);
            $sheets_service->spreadsheets->batchUpdate(WTQSG_SPREADSHEET_ID, $batch_update_request_format);
        }

        return "Foglio preventivi per {$month}/{$year} aggiornato con successo. Righe processate: " . (count($final_data_for_sheet) - 1);

    } catch (Exception $e) {
        error_log("WTQSG Rebuild Error: " . $e->getMessage());
        return new WP_Error('google_sheet_quotes_error', $e->getMessage());
    }
}


/**
 * Funzione che verrà eseguita dal cron job per la generazione INIZIALE MENSILE del foglio preventivi.
 */
function wtqsg_monthly_quotes_sheet_generation_cron_job() {
    error_log('WTQSG Cron (Monthly): Avvio generazione mensile foglio preventivi.');

    $stsg_client = new STSG_Google_Sheets_Client();
    $sheets_service = $stsg_client->getService();

    if (is_wp_error($sheets_service)) {
        error_log('WTQSG Cron Error (Monthly): Connessione a Google Sheets fallita: ' . $sheets_service->get_error_message());
        return;
    }

    try {
        $result = wtqsg_rebuild_and_update_all_data($sheets_service, true);
        if (is_wp_error($result)) {
            error_log('WTQSG Cron Error (Monthly): Errore nella generazione mensile del foglio: ' . $result->get_error_message());
        } else {
            error_log("WTQSG Cron (Monthly): {$result}.");
        }
    } catch (Exception $e) {
        error_log('WTQSG Cron Error (Monthly): Eccezione durante la generazione mensile: ' . $e->getMessage());
    }
    error_log('WTQSG Cron (Monthly): Generazione mensile foglio preventivi terminata.');
}


/**
 * Esegue l'aggiornamento orario delle colonne "snapshot" nel foglio preventivi del mese corrente.
 */
function wtqsg_daily_quotes_sheet_snapshot_update_cron_job() {
    error_log('WTQSG Cron (Hourly Snapshot): Avvio aggiornamento orario snapshot.');

    $stsg_client = new STSG_Google_Sheets_Client();
    $sheets_service = $stsg_client->getService();

    if (is_wp_error($sheets_service)) {
        error_log('WTQSG Cron Error (Hourly Snapshot): Connessione a Google Sheets fallita: ' . $sheets_service->get_error_message());
        return;
    }

    try {
        $date_to_update = new DateTime();
        $year = (int)$date_to_update->format('Y');
        $month = (int)$date_to_update->format('m');
        $day = (int)$date_to_update->format('d');
        $month_name_it = stsg_translate_month_to_italian($date_to_update->format('F'));
        $sheet_name_with_month = WTQSG_BASE_SHEET_NAME . '-' . ucfirst($month_name_it) . '-' . $year;
        $day_label_for_sheet = stsg_translate_month_to_italian($date_to_update->format('d F'));
        
        // Calcola i dati "snapshot" aggiornati per oggi
        $trello_api_key = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : null;
        $trello_api_token = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : null;
        $requests_to_clarify_current = wtqsg_get_total_open_cards_from_multiple_lists([ [TRELLO_BOARD_ID_GESTIONALE, TRELLO_LIST_ID_RICHIESTE_CHIARIRE_2] ], $trello_api_key, $trello_api_token) + wtqsg_get_total_open_cards_from_multiple_lists([ [TRELLO_BOARD_ID_AGENTE_ANTONIO, TRELLO_LIST_ID_AGENTE_ANTONIO_CHIARIRE], [TRELLO_BOARD_ID_AGENTE_GALLI, TRELLO_LIST_ID_AGENTE_GALLI_CHIARIRE], [TRELLO_BOARD_ID_AGENTE_ISABELLA, TRELLO_LIST_ID_AGENTE_ISABELLA_CHIARIRE], [TRELLO_BOARD_ID_AGENTE_JALALL, TRELLO_LIST_ID_AGENTE_JALALL_CHIARIRE], [TRELLO_BOARD_ID_AGENTE_MARCO, TRELLO_LIST_ID_AGENTE_MARCO_CHIARIRE], [TRELLO_BOARD_ID_AGENTE_NOEMI, TRELLO_LIST_ID_AGENTE_NOEMI_CHIARIRE], [TRELLO_BOARD_ID_AGENTE_TONACCI, TRELLO_LIST_ID_AGENTE_TONACCI_CHIARIRE], ], $trello_api_key, $trello_api_token);
        $stalled_cards_current = wtqsg_get_total_open_cards_from_multiple_lists([ [TRELLO_BOARD_ID_GESTIONALE, TRELLO_LIST_ID_RICHIESTE_INCAGLIATE_1], [TRELLO_BOARD_ID_GESTIONALE, TRELLO_LIST_ID_RICHIESTE_INCAGLIATE_2] ], $trello_api_key, $trello_api_token);
        $effective_quotes_to_do_current = wtqsg_calculate_effective_quotes($trello_api_key, $trello_api_token);
        
        $headers = wtqsg_get_quotes_sheet_headers();
        $header_to_index_map = array_flip($headers);
        
        // Trova la riga di oggi
        $day_column_range = $sheet_name_with_month . '!A:A';
        $day_column_response = $sheets_service->spreadsheets_values->get(WTQSG_SPREADSHEET_ID, $day_column_range);
        $day_column_values = $day_column_response->getValues() ?? [];
        $row_index_to_update = -1;
        foreach ($day_column_values as $idx => $cell_value) {
            if (isset($cell_value[0]) && trim($cell_value[0]) === $day_label_for_sheet) {
                $row_index_to_update = $idx;
                break;
            }
        }
        
        if ($row_index_to_update !== -1) {
             $updates = [];
             if (isset($header_to_index_map['Richieste da Chiarire'])) {
                $col_letter = chr(ord('A') + $header_to_index_map['Richieste da Chiarire']);
                $range = $sheet_name_with_month . '!' . $col_letter . ($row_index_to_update + 1);
                $updates[] = ['range' => $range, 'values' => [[$requests_to_clarify_current]]];
            }
            if (isset($header_to_index_map['Schede incagliate'])) {
                $col_letter = chr(ord('A') + $header_to_index_map['Schede incagliate']);
                $range = $sheet_name_with_month . '!' . $col_letter . ($row_index_to_update + 1);
                $updates[] = ['range' => $range, 'values' => [[$stalled_cards_current]]];
            }
            if (isset($header_to_index_map['Preventivi effettivi da fare'])) {
                $col_letter = chr(ord('A') + $header_to_index_map['Preventivi effettivi da fare']);
                $range = $sheet_name_with_month . '!' . $col_letter . ($row_index_to_update + 1);
                $updates[] = ['range' => $range, 'values' => [[$effective_quotes_to_do_current]]];
            }

            if (!empty($updates)) {
                $batch_update_request = new Google_Service_Sheets_BatchUpdateValuesRequest([
                    'valueInputOption' => 'USER_ENTERED',
                    'data' => $updates,
                ]);
                $sheets_service->spreadsheets_values->batchUpdate(WTQSG_SPREADSHEET_ID, $batch_update_request);
            }
        }

    } catch (Exception $e) {
        error_log('WTQSG Cron Error (Hourly Snapshot): Errore durante l\'aggiornamento orario dello snapshot: ' . $e->getMessage());
    }
    error_log('WTQSG Cron (Hourly Snapshot): Aggiornamento orario snapshot terminato.');
}


// Programma gli eventi cron al caricamento del plugin/tema
add_action('wp_loaded', 'wtqsg_schedule_cron_events');
function wtqsg_schedule_cron_events() {
    add_filter('cron_schedules', 'wtqsg_add_monthly_cron_interval');
    add_filter('cron_schedules', 'wtqsg_add_hourly_cron_interval');

    if (!wp_next_scheduled(WTQSG_CRON_EVENT_MONTHLY)) {
        wp_schedule_event(strtotime('first day of next month midnight'), 'monthly', WTQSG_CRON_EVENT_MONTHLY);
    }
    if (!wp_next_scheduled(WTQSG_CRON_EVENT_DAILY_SNAPSHOT)) {
        wp_schedule_event(time(), 'hourly', WTQSG_CRON_EVENT_DAILY_SNAPSHOT);
    }
}

function wtqsg_add_monthly_cron_interval($schedules) {
    if (!isset($schedules['monthly'])) {
        $schedules['monthly'] = ['interval' => DAY_IN_SECONDS * 30, 'display' => esc_html__('Once Monthly')];
    }
    return $schedules;
}

function wtqsg_add_hourly_cron_interval($schedules) {
    $schedules['hourly'] = ['interval' => HOUR_IN_SECONDS, 'display' => esc_html__('Once Hourly')];
    return $schedules;
}

add_action(WTQSG_CRON_EVENT_MONTHLY, 'wtqsg_monthly_quotes_sheet_generation_cron_job');
add_action(WTQSG_CRON_EVENT_DAILY_SNAPSHOT, 'wtqsg_daily_quotes_sheet_snapshot_update_cron_job');


/**
 * Renderizza la pagina amministrativa per il Foglio Preventivi.
 */
function wtqsg_render_quotes_sheet_page() {
    ?>
    <div class="wrap">
        <h1>📝 Genera Foglio Preventivi da Fatture in Cloud</h1>
        <p>Usa il pulsante qui sotto per ricostruire l'intero foglio.</p>
        <hr/>
        
        <?php
        $feedback_message = '';
        $message_class = '';

        $stsg_client = new STSG_Google_Sheets_Client();
        $sheets_service = $stsg_client->getService();

        if (is_wp_error($sheets_service)) {
            $message_class = 'notice-error';
            $feedback_message = 'Errore di connessione a Google Sheets: ' . $sheets_service->get_error_message();
            echo '<div class="notice ' . esc_attr($message_class) . ' is-dismissible"><p>' . wp_kses_post($feedback_message) . '</p></div>';
        } else {
            // Gestisce l'aggiornamento completo
            if (isset($_POST['wtqsg_update_all_data_nonce']) && wp_verify_nonce($_POST['wtqsg_update_all_data_nonce'], 'wtqsg_update_all_data_action')) {
                $result = wtqsg_rebuild_and_update_all_data($sheets_service);
                if (is_wp_error($result)) {
                    $message_class = 'notice-error';
                    $feedback_message = 'Errore durante l\'aggiornamento: ' . $result->get_error_message();
                } else {
                    $feedback_message = $result; 
                    $message_class = 'notice-success';
                }
                echo '<div class="notice ' . esc_attr($message_class) . ' is-dismissible"><p>' . wp_kses_post($feedback_message) . '</p></div>';
            }
        }
        ?>

        <div class="uk-card uk-card-default uk-card-body">
            <h3>Ricostruisci e Aggiorna l'intero Foglio</h3>
            <p style="border-left: 4px solid #f0b849; padding-left: 10px;">
                <strong>Nota:</strong> Questa operazione ricostruirà l'intero foglio del mese corrente. I dati dei giorni passati per "Richieste da Chiarire", "Schede incagliate" e "Preventivi effettivi da fare" non verranno toccati. Tutti gli altri dati verranno ricalcolati.
            </p>
            <form method="post" action="">
                <?php wp_nonce_field('wtqsg_update_all_data_action', 'wtqsg_update_all_data_nonce'); ?>
                <div class="uk-margin-top uk-text-center">
                    <button class="uk-button uk-button-primary" type="submit">Ricostruisci e Aggiorna l'intero Foglio</button>
                </div>
            </form>
        </div>
        
    </div>
    <?php
}
