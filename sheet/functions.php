<?php
/**
 * Funzioni helper dedicate esclusivamente al Trello Sheet Generator.
 */

if (!defined('ABSPATH')) exit;

/**
 * Funzione isolata che recupera e processa tutte le card necessarie per lo sheet.
 * Usa le funzioni globali del plugin (`get_trello_boards`, `get_trello_cards`, etc.)
 * ma rimane un'entità separata.
 *
 * @return array
 */
function stsg_get_all_cards_for_sheet() {
    // Definire TRELLO_API_KEY e TRELLO_API_TOKEN in wp-config.php
    // o tramite le impostazioni del plugin.
    if (!defined('TRELLO_API_KEY') || !defined('TRELLO_API_TOKEN')) {
        error_log("STSG Error: TRELLO_API_KEY or TRELLO_API_TOKEN not defined.");
        return [];
    }
    
    // 1. Recupera tutte le board
    $all_user_boards = get_trello_boards(TRELLO_API_KEY, TRELLO_API_TOKEN);
    $targetBoardIds = array_filter(array_map(function($board) { return $board['id']; }, is_array($all_user_boards) ? $all_user_boards : []));
    if (empty($targetBoardIds)) {
        error_log("STSG Debug: Nessuna board Trello trovata.");
        return [];
    }
    
    // 2. Ottieni la mappa delle provenienze
    $board_provenance_ids_map = wp_trello_get_provenance_field_ids_map($targetBoardIds, TRELLO_API_KEY, TRELLO_API_TOKEN);

    // 3. Recupera le card grezze
    $raw_cards = [];
    foreach ($targetBoardIds as $boardId) {
        $cards_from_single_board = get_trello_cards($boardId, TRELLO_API_KEY, TRELLO_API_TOKEN);
        if (is_array($cards_from_single_board)) {
            $raw_cards = array_merge($raw_cards, $cards_from_single_board);
        }
    }
    
    // 4. Processa le card per aggiungere la provenienza
    $processed_cards = [];
    foreach ($raw_cards as $card) {
        $card['provenance'] = wp_trello_get_card_provenance($card, $board_provenance_ids_map);
        $processed_cards[] = $card;
    }
    
    // 5. Restituisce TUTTE le schede processate, senza filtri sul nome
    return $processed_cards;
}


/**
 * Estrae la data di creazione di una card Trello dal suo ID.
 *
 * @param string $card_id
 * @return DateTime|null
 */
function stsg_get_card_creation_date($card_id) {
    if (empty($card_id) || strlen($card_id) < 8) return null;
    
    $timestamp = hexdec(substr($card_id, 0, 8));
    if (!$timestamp) return null;

    // Crea l'oggetto DateTime in UTC (che è il default di '@')
    $date_utc = new DateTime('@' . $timestamp);
    
    // Imposta il fuso orario corretto (quello italiano)
    $date_utc->setTimezone(new DateTimeZone('Europe/Rome'));
    
    return $date_utc;
}

/**
 * Estrae i nomi delle etichette (labels) da un oggetto card di Trello.
 *
 * @param array $card
 * @return array
 */
function stsg_get_card_labels($card) {
    if (!isset($card['labels']) || !is_array($card['labels'])) return [];
    
    $label_names = [];
    foreach ($card['labels'] as $label_object) {
        if (isset($label_object['name']) && trim($label_object['name']) !== '') {
            $label_names[] = $label_object['name'];
        }
    }
    return $label_names;
}

// --- Funzioni per la gestione dei costi, spostate da trello-costs-manager.php ---

/**
 * Funzione helper per ottenere l'ID dello spreadsheet principale.
 * Assicura che STSG_SPREADSHEET_ID sia definito.
 */
function wtcm_get_main_spreadsheet_id() {
    if (!defined('STSG_SPREADSHEET_ID')) {
        error_log('Error: STSG_SPREADSHEET_ID is not defined when wtcm_get_main_spreadsheet_id() is called.');
        return null;
    }
    return STSG_SPREADSHEET_ID;
}

/**
 * Funzione helper per ottenere il nome del foglio principale (Richieste).
 * Assicura che STSG_SHEET_NAME sia definito.
 */
function wtcm_get_main_sheet_name() {
    if (!defined('STSG_SHEET_NAME')) {
        error_log('Error: STSG_SHEET_NAME is not defined when wtcm_get_main_sheet_name() is called.');
        return null;
    }
    return STSG_SHEET_NAME;
}

/**
 * Mappa i nomi delle piattaforme ai nomi esatti delle colonne di costo nel foglio.
 * Questi nomi devono corrispondere alle intestazioni del foglio "Richieste".
 * La chiave è il nome normalizzato della provenienza (come usato in trello-helpers.php),
 * il valore è l'intestazione esatta della colonna del costo.
 */
function wtcm_get_platform_cost_columns_map() {
    return [
        'iol'                => 'Costo Italiaonline',
        'chiamate'           => 'Costo Chiamate',
        'Organic Search'     => 'Costo Organico',
        'taormina'           => 'Costo Taormina',
        'fb'                 => 'Costo Facebook',
        'Google Ads'         => 'Costo Google Ads',
        'Subito'             => 'Costo Subito',
        'poolindustriale.it' => 'Costo Pool Industriale',
        'Bakeka'             => 'Costo Bakeka',
        'archiexpo'          => 'Costo Archiexpo',
        'Europage'           => 'Costo Europage',
    ];
}

/**
 * Mappa le intestazioni del foglio originale (per importazione costi) alle chiavi di provenienza normalizzate.
 */
function wtcm_get_original_sheet_header_to_provenance_map() {
    return [
        'Costo Italiaonline'      => 'iol',
        'Costo Chiamate'          => 'chiamate',
        'Costo Organico'          => 'Organic Search',
        'Costo Taormina'          => 'taormina',
        'Costo Facebook'          => 'fb',
        'Costo Google Ads'        => 'Google Ads',
        'Costo Subito'            => 'Subito',
        'Costo Pool Industriale'  => 'poolindustriale.it',
        'Costo Bakeka'            => 'Bakeka',
        'Costo Archiexpo'         => 'archiexpo',
        'Costo Europage'          => 'Europage',
    ];
}

/**
 * Recupera i costi per una settimana specifica dal Google Sheet.
 *
 * @param Google_Service_Sheets $sheets_service L'istanza del servizio Google Sheets.
 * @param string $week_range_key La chiave della settimana (es. '2025-01-06_2025-01-12').
 * @return array|WP_Error Un array associativo dei costi per provenienza o WP_Error in caso di fallimento.
 */
function wtcm_get_costs_from_sheet($sheets_service, $week_range_key) {
    // Dipende da stsg_get_week_ranges() in sheet-generator.php, che è incluso da statistiche-trello.php
    $week_ranges = stsg_get_week_ranges();
    $week_label = $week_ranges[$week_range_key] ?? null;

    if (!$week_label) {
        return new WP_Error('invalid_week', 'Settimana selezionata non valida.');
    }

    try {
        // Recupera tutte le intestazioni per mappare i nomi delle colonne
        $header_range = wtcm_get_main_sheet_name() . '!1:1';
        $header_response = $sheets_service->spreadsheets_values->get(wtcm_get_main_spreadsheet_id(), $header_range);
        $headers = $header_response->getValues()[0] ?? [];
        if (empty($headers)) {
            return new WP_Error('headers_not_found', 'Intestazioni del foglio non trovate.');
        }

        // Trova la riga della settimana
        $all_data_range = wtcm_get_main_sheet_name() . '!A:Z';
        $all_data_response = $sheets_service->spreadsheets_values->get(wtcm_get_main_spreadsheet_id(), $all_data_range);
        $all_rows = $all_data_response->getValues() ?? [];

        $row_to_find = -1;
        // Inizia la ricerca dalla riga 1 (indice 0 per l'array PHP) perché la riga 0 sono le intestazioni
        foreach ($all_rows as $idx => $row) {
            // Salta la riga delle intestazioni (indice 0)
            if ($idx === 0) continue; 
            if (isset($row[0]) && trim($row[0]) === $week_label) { // La prima colonna è la 'Settimana'
                $row_to_find = $idx; // Indice dell'array (0-based)
                break;
            }
        }

        if ($row_to_find === -1) {
            return new WP_Error('week_not_found', 'La settimana "' . esc_html($week_label) . '" non è stata trovata nel foglio.');
        }

        $costs = [];
        $cost_columns_map = wtcm_get_platform_cost_columns_map();

        // Per ogni provenienza, trova l'indice della colonna del costo e recupera il valore
        foreach ($cost_columns_map as $prov_key => $col_name) {
            $col_index = array_search($col_name, $headers);
            if ($col_index !== false && isset($all_rows[$row_to_find][$col_index])) {
                $value = $all_rows[$row_to_find][$col_index];
                $costs[$prov_key] = floatval(str_replace(',', '.', str_replace(['€', '.'], '', $value))); // Pulizia più robusta
            } else {
                $costs[$prov_key] = 0.0; // Se la colonna non esiste o è vuota
            }
        }
        return $costs;

    } catch (Exception $e) {
        return new WP_Error('google_api_read_error', 'Errore durante la lettura dei costi dal Google Sheet: ' . $e->getMessage());
    }
}

/**
 * Aggiorna i costi per una settimana specifica nel Google Sheet.
 *
 * @param Google_Service_Sheets $sheets_service L'istanza del servizio Google Sheets.
 * @param string $week_range_key La chiave della settimana (es. '2025-01-06_2025-01-12').
 * @param array $costs_data Un array associativo con 'provenienza_normalizzata' => 'costo'.
 * @return true|WP_Error True se l'aggiornamento ha successo, WP_Error altrimenti.
 */
function wtcm_update_costs_in_sheet($sheets_service, $week_range_key, $costs_data) {
    $week_ranges = stsg_get_week_ranges();
    $week_label = $week_ranges[$week_range_key] ?? null;

    if (!$week_label) {
        return new WP_Error('invalid_week', 'Settimana selezionata non valida per l\'aggiornamento.');
    }

    try {
        // 1. Recupera tutte le intestazioni per mappare i nomi delle colonne ai loro indici
        $header_range = wtcm_get_main_sheet_name() . '!1:1';
        $header_response = $sheets_service->spreadsheets_values->get(wtcm_get_main_spreadsheet_id(), $header_range);
        $headers = $header_response->getValues()[0] ?? [];
        if (empty($headers)) {
            return new WP_Error('headers_not_found', 'Intestazioni del foglio costi non trovate.');
        }

        // 2. Trova la riga della settimana da aggiornare
        $all_week_labels_column_range = wtcm_get_main_sheet_name() . '!A:A';
        $all_week_labels_response = $sheets_service->spreadsheets_values->get(wtcm_get_main_spreadsheet_id(), $all_week_labels_column_range);
        $all_week_labels_column = $all_week_labels_response->getValues() ?? [];

        $row_number_to_update = -1;
        foreach ($all_week_labels_column as $idx => $row_array) {
            if ($idx === 0) continue; 
            if (isset($row_array[0]) && trim($row_array[0]) === $week_label) {
                $row_number_to_update = $idx + 1; 
                break;
            }
        }

        if ($row_number_to_update === -1) {
            return new WP_Error('week_row_not_found', 'La riga per la settimana "' . esc_html($week_label) . '" non è stata trovata nel foglio dei costi. Assicurati che esista (puoi usare il "Generatore Sheet" per crearla).');
        }

        // 3. Prepara gli aggiornamenti per le colonne dei costi
        $cost_columns_map = wtcm_get_platform_cost_columns_map();
        $updates = [];

        foreach ($costs_data as $prov_key => $cost_value) {
            $col_name = $cost_columns_map[$prov_key] ?? null;
            if ($col_name) {
                $col_index = array_search($col_name, $headers);
                if ($col_index !== false) {
                    $col_letter = chr(ord('A') + $col_index);
                    $range_to_update = wtcm_get_main_sheet_name() . '!' . $col_letter . $row_number_to_update;
                    
                    $data_item = new Google_Service_Sheets_ValueRange();
                    $data_item->setRange($range_to_update);
                    $data_item->setValues([[floatval($cost_value)]]);

                    $updates[] = $data_item;

                } else {
                    error_log('WTCM Error: Colonna "' . $col_name . '" non trovata nel foglio. Controlla wtcm_get_platform_cost_columns_map().');
                }
            }
        }

        if (empty($updates)) {
            return new WP_Error('no_cost_data_to_update', 'Nessun costo valido da aggiornare.');
        }

        // 4. Esegui l'aggiornamento batch per tutte le celle in una singola richiesta
        $batch_update_request = new Google_Service_Sheets_BatchUpdateValuesRequest([
            'valueInputOption' => 'USER_ENTERED',
            'data' => $updates,
        ]);
        
        $response = $sheets_service->spreadsheets_values->batchUpdate(wtcm_get_main_spreadsheet_id(), $batch_update_request);

        return true;

    } catch (Exception $e) {
        return new WP_Error('google_api_update_error', 'Errore durante l\'aggiornamento dei costi sul Google Sheet: ' . $e->getMessage());
    }
}

/**
 * NUOVA FUNZIONE HELPER: Converte un indice di colonna (0-based) in una lettera di colonna (A-Z, AA-AZ, etc.).
 *
 * @param int $col_index L'indice della colonna (0-based).
 * @return string La lettera della colonna.
 */
function wtqsg_column_index_to_letter($col_index) {
    $letter = '';
    while ($col_index >= 0) {
        $letter = chr($col_index % 26 + 65) . $letter;
        $col_index = floor($col_index / 26) - 1;
    }
    return $letter;
}

/**
 * NUOVA FUNZIONE: Recupera la somma dei "Totale Preventivi" da specifici fogli mensili
 * per un dato intervallo di date.
 *
 * @param Google_Service_Sheets $sheets_service L'istanza del servizio Google Sheets.
 * @param DateTime $start_date Data di inizio dell'intervallo.
 * @param DateTime $end_date Data di fine dell'intervallo.
 * @return int La somma dei preventivi fatti, o 0 in caso di errore/nessun dato.
 */
function stsg_get_total_quotes_for_date_range($sheets_service, DateTime $start_date, DateTime $end_date) {
    $total_quotes_sum = 0;
    $current_date = clone $start_date;

    // Assumiamo che la colonna 'Giorno' sia A (indice 0) e 'Totale Preventivi' sia L (indice 11)
    // Questo deve corrispondere all'array restituito da wtqsg_get_quotes_sheet_headers() nel file trello-quotes-sheet-generator.php
    $preventivi_headers = wtqsg_get_quotes_sheet_headers();
    $total_preventivi_col_index = array_search('Totale Preventivi', $preventivi_headers);

    if ($total_preventivi_col_index === false) {
        error_log("STSG Error: 'Totale Preventivi' column not found in preventivi sheet headers. Cannot sum.");
        return 0;
    }
    
    // Converti l'indice 0-based alla lettera di colonna per la query
    $total_preventivi_col_letter = wtqsg_column_index_to_letter($total_preventivi_col_index);


    // Loop attraverso i mesi compresi nell'intervallo di date
    while ($current_date <= $end_date) {
        $year = (int)$current_date->format('Y');
        $month = (int)$current_date->format('m');
        $month_name_it = stsg_translate_month_to_italian($current_date->format('F'));
        $sheet_name = WTQSG_BASE_SHEET_NAME . '-' . ucfirst($month_name_it) . '-' . $year;

        try {
            // Leggi solo le colonne 'Giorno' (A) e 'Totale Preventivi' (L)
            $range = $sheet_name . '!A:' . $total_preventivi_col_letter; 
            $response = $sheets_service->spreadsheets_values->get(WTQSG_SPREADSHEET_ID, $range);
            $values = $response->getValues();

            if (!empty($values)) {
                // Salta l'intestazione
                array_shift($values); 
                foreach ($values as $row) {
                    // La colonna 'Giorno' è la prima (indice 0)
                    $day_label = $row[0] ?? ''; 
                    // Il valore di 'Totale Preventivi' è all'indice della colonna calcolato
                    $daily_total_value = $row[$total_preventivi_col_index] ?? 0; 
                    
                    // Estrai la data dal label del giorno (es. "01 gennaio")
                    // E usa l'anno del foglio corrente ($year) per ricostruire la data completa
                    preg_match('/(\d+)\s(\w+)/u', $day_label, $matches); // Regex per giorno e mese
                    if (isset($matches[1]) && isset($matches[2])) {
                        $day_num = $matches[1];
                        $month_name_en = strtolower(stsg_translate_month_to_english($matches[2])); // Converti il mese in inglese per strtotime
                        $date_string = "{$day_num} {$month_name_en} {$year}";
                        $date_from_sheet = new DateTime($date_string, new DateTimeZone('Europe/Rome'));

                        // Controlla se questa data rientra nel range della settimana
                        if ($date_from_sheet >= $start_date && $date_from_sheet <= $end_date) {
                            $total_quotes_sum += (int)$daily_total_value;
                        }
                    } else {
                        error_log("STSG Warning: Cannot parse date from sheet label: '{$day_label}' in sheet '{$sheet_name}'.");
                    }
                }
            } else {
                error_log("STSG Debug: Foglio '{$sheet_name}' per preventivi non contiene dati.");
            }
        } catch (Exception $e) {
            error_log("STSG Error: Impossibile leggere il foglio preventivi '{$sheet_name}': " . $e->getMessage());
            // Continua al prossimo mese anche in caso di errore su un foglio
        }

        // Passa al primo giorno del mese successivo
        $current_date->modify('+1 month');
        $current_date->setDate($current_date->format('Y'), $current_date->format('m'), 1);
        if ($current_date > $end_date && $current_date->format('m') != $end_date->format('m')) {
            // Se siamo passati al mese successivo al mese di fine della settimana, interrompi
            break; 
        }
    }
    return $total_quotes_sum;
}

/**
 * NUOVA FUNZIONE HELPER: Converte il nome di un mese italiano nel suo equivalente inglese.
 * Usato per creare oggetti DateTime robusti.
 *
 * @param string $italian_month Il nome del mese in italiano.
 * @return string L'equivalente in inglese.
 */
function stsg_translate_month_to_english($italian_month) {
    $italian = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
    $english = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
    return str_replace($italian, $english, strtolower($italian_month));
}

// Fine del file functions.php