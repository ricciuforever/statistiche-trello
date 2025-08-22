<?php
/**
 * File delle funzioni helper per il plugin Statistiche Trello.
 */

if (!defined('ABSPATH')) exit;

// --- Funzioni Helper Globali di Normalizzazione e Logica Trello ---

if (!function_exists('wp_trello_normalize_platform_name')) {
    function wp_trello_normalize_platform_name($name) {
        if ($name === null || trim($name) === '') {
            return null;
        }
        $normalized_name = trim($name);

        // Regole specifiche per incongruenze note nel CSV
        if (strcasecmp($normalized_name, 'ItaliaOnline') === 0) {
            $normalized_name = 'italiaonline';
        } elseif (strcasecmp($normalized_name, 'Organico (Sito)') === 0) {
            $normalized_name = 'organico';
        } elseif (strcasecmp($normalized_name, 'Costo Organico') === 0) { // Questo viene passato dopo la rimozione di 'Costo '
            $normalized_name = 'organico';
        }

        // Regola generale: converti in minuscolo
        return strtolower($normalized_name);
    }
}

if (!function_exists('wp_trello_normalize_provenance_value')) {
    function wp_trello_normalize_provenance_value($provenance_value) {
        if ($provenance_value === null || trim($provenance_value) === '') { return null; }
        $trimmed_value = trim($provenance_value);
        $lower_trimmed_value = strtolower($trimmed_value);
        $map = [ 
            'organic search' => 'Organic Search', 'google ads' => 'Google Ads',
            'adwords' => 'Google Ads', 'google adwords' => 'Google Ads',
            'facebook' => 'fb', 'fb ads' => 'fb', 'facebook ads' => 'fb',
            'italiaonline' => 'iol', 'pool industriale' => 'poolindustriale.it'
        ];
        if (isset($map[$lower_trimmed_value])) return $map[$lower_trimmed_value];
        return $trimmed_value; 
    }
}

if (!function_exists('wp_trello_is_state_displayable')) {
    function wp_trello_is_state_displayable($state_name) {
        if (empty($state_name)) return false;
        $excluded_states = ['FREDDO', 'CALDO', 'FREDDISSIMO', 'CONGELATO', 'CALDISSIMO'];
        return !in_array(strtoupper(trim($state_name)), array_map('strtoupper', $excluded_states));
    }
}

if (!function_exists('wp_trello_is_temperature_displayable')) {
    function wp_trello_is_temperature_displayable($temperature_name) {
        if (empty($temperature_name)) return false;
        if (stripos(trim($temperature_name), 'Richiamo') !== false) return false;
        return true;
    }
}

if (!function_exists('wp_trello_normalize_temperature_value')) {
    function wp_trello_normalize_temperature_value($temperature_value) {
        if ($temperature_value === null || trim($temperature_value) === '') return null; 
        $trimmed_value = trim($temperature_value);
        if (strcasecmp($trimmed_value, 'Vorrei Ma Non Posso') === 0 || strcasecmp($trimmed_value, 'Vorrei ma non posso') === 0) {
            return 'Vorrei Ma Non Posso'; 
        }
        return $trimmed_value; 
    }
}

if (!function_exists('wp_trello_get_provenance_field_ids_map')) {
    function wp_trello_get_provenance_field_ids_map($board_ids, $apiKey, $apiToken) {
        $provenance_field_ids_map = [];
        if (empty($board_ids)) return $provenance_field_ids_map;
        foreach ($board_ids as $board_id) {
            if (empty($board_id)) continue;
            $cache_key = 'wp_trello_custom_fields_' . $board_id;
            $custom_fields_for_board = get_transient($cache_key);
            if (false === $custom_fields_for_board) {
                if (function_exists('get_custom_fields')) {
                    $custom_fields_for_board = get_custom_fields($board_id, $apiKey, $apiToken); 
                    set_transient($cache_key, is_array($custom_fields_for_board) ? $custom_fields_for_board : [], 1 * HOUR_IN_SECONDS); 
                } else {
                    error_log("Statistiche Trello Helpers: Funzione get_custom_fields() non trovata.");
                    $custom_fields_for_board = [];
                }
            }
            if (is_array($custom_fields_for_board)) {
                foreach ($custom_fields_for_board as $field) {
                    if (isset($field['name']) && $field['name'] === 'Provenienza' && isset($field['id'])) {
                        $provenance_field_ids_map[$board_id] = $field['id'];
                        break; 
                    }
                }
            }
        }
        return $provenance_field_ids_map;
    }
}

if (!function_exists('wp_trello_get_card_provenance')) {
    function wp_trello_get_card_provenance($card, $board_provenance_ids_map){ 
        $card_board_id = $card['idBoard'] ?? null;
        $provenance_field_id = ($card_board_id && isset($board_provenance_ids_map[$card_board_id])) ? $board_provenance_ids_map[$card_board_id] : null;
        $raw_provenance = null;
        if ($provenance_field_id && !empty($card['customFieldItems'])) {
            foreach ($card['customFieldItems'] as $cf) {
                if (isset($cf['idCustomField']) && $cf['idCustomField'] === $provenance_field_id && isset($cf['value']['text'])) {
                    $raw_provenance = trim($cf['value']['text']);
                    break;
                }
            }
        }
        return wp_trello_normalize_provenance_value($raw_provenance); 
    }
}

// --- Funzioni di Recupero Dati Trello ---

if (!function_exists('get_trello_boards')) { 
    function get_trello_boards($apiKey, $apiToken) {
        // ID del workspace target
        define('TARGET_TRELLO_WORKSPACE_ID', '5e3c64ff0e902238d5405477');

        $transient_key = 'wp_trello_boards_for_workspace_' . TARGET_TRELLO_WORKSPACE_ID;
        $cached_boards = get_transient($transient_key);
        if (false !== $cached_boards) {
            return $cached_boards;
        }

        // Modifica l'URL per includere l'id dell'organizzazione (workspace)
        $url = "https://api.trello.com/1/members/me/boards?fields=name,id,idOrganization&key={$apiKey}&token={$apiToken}";
        
        $response = wp_remote_get($url);
        if (is_wp_error($response)) return [];
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) return [];
        
        $boards = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($boards)) return [];

        // --- MODIFICA: Filtra le board per ID del workspace ---
        $filtered_boards = array_filter($boards, function($board) {
            return isset($board['idOrganization']) && $board['idOrganization'] === TARGET_TRELLO_WORKSPACE_ID;
        });

        // Salva in cache solo la lista filtrata
        set_transient($transient_key, $filtered_boards, 1 * HOUR_IN_SECONDS); 
        
        // Restituisci la lista filtrata re-indicizzata per evitare problemi con array sparsi
        return array_values($filtered_boards); 
    } 
}


if (!function_exists('get_trello_cards')) { 
    function get_trello_cards($boardId, $apiKey, $apiToken) {
        $transient_key = 'wp_trello_cards_all_v3_' . $boardId; // Versione transient incrementata
        $cached_cards = get_transient($transient_key);
        if (false !== $cached_cards) {
            return $cached_cards;
        }
        
        $all_cards = [];
        $before = null; // Per la paginazione, partiamo dalla fine
        $fields = "id,name,desc,closed,idBoard,idList,labels,customFieldItems,dateLastActivity";
        $limit = 1000; // Limite massimo per richiesta API Trello

        while (true) {
            $url = "https://api.trello.com/1/boards/{$boardId}/cards?customFieldItems=true&fields={$fields}&limit={$limit}&key={$apiKey}&token={$apiToken}";
            if ($before) {
                $url .= "&before=" . $before;
            }

            $response = wp_remote_get($url, ['timeout' => 30]); // Timeout aumentato per richieste pesanti

            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                error_log("Errore API Trello (get_trello_cards per board $boardId): " . print_r($response, true));
                // In caso di errore, restituiamo quello che abbiamo trovato finora per non perdere tutto
                break;
            }

            $cards_batch = json_decode(wp_remote_retrieve_body($response), true);

            if (!is_array($cards_batch) || empty($cards_batch)) {
                // Se non ci sono più schede, abbiamo finito
                break;
            }

            $all_cards = array_merge($all_cards, $cards_batch);
            
            // Imposta il marcatore 'before' all'ID della prima (più vecchia) scheda di questo batch
            // per la richiesta successiva.
            $before = end($cards_batch)['id'];

            // Se il batch restituito ha meno di 1000 schede, significa che abbiamo raggiunto l'inizio
            if (count($cards_batch) < $limit) {
                break;
            }
        }
        
        set_transient($transient_key, $all_cards, 15 * MINUTE_IN_SECONDS);
        return $all_cards;
    }
}

if (!function_exists('get_custom_fields')) { 
    function get_custom_fields($boardId,$apiKey,$apiToken){
        $u="https://api.trello.com/1/boards/{$boardId}/customFields?key={$apiKey}&token={$apiToken}";
        $r=wp_remote_get($u);
        if(is_wp_error($r)){error_log('Trello API Error (get_custom_fields): '.$r->get_error_message());return[];}
        $sc=wp_remote_retrieve_response_code($r);
        if($sc!==200){error_log('Trello API HTTP Error (get_custom_fields): '.$sc . ' Body: ' . wp_remote_retrieve_body($r));return[];}
        $cf=json_decode(wp_remote_retrieve_body($r),true);return is_array($cf)?$cf:[];
    } 
}

// --- Funzioni di Filtraggio Principali ---
if (!function_exists('wp_trello_filter_cards_by_numeric_name')) { 
    function wp_trello_filter_cards_by_numeric_name($cards_array){
        $n=[];if(!is_array($cards_array)||empty($cards_array))return $n;
        foreach($cards_array as $i){if(isset($i['name'])&&is_numeric($i['name'])){$n[]=$i;}}
        return $n;
    } 
}
if (!function_exists('wp_trello_apply_filters_to_cards')) { 
    function wp_trello_apply_filters_to_cards($cards_input, $board_provenance_ids_map) { 
        $cc=$cards_input;$p=isset($_GET['provenances'])?(array)$_GET['provenances']:[];
        if(!empty($p)&&function_exists('filter_cards_by_provenance')){$cc=filter_cards_by_provenance($cc,$p,$board_provenance_ids_map);}
        $s=isset($_GET['states'])?(array)$_GET['states']:[];
        if(!empty($s)&&function_exists('filter_cards_by_state_from_labels')){$cc=filter_cards_by_state_from_labels($cc,$s);}
        $t=isset($_GET['temperatures'])?(array)$_GET['temperatures']:[];
        if(!empty($t)&&function_exists('filter_cards_by_temperature')){$cc=filter_cards_by_temperature($cc,$t);}
        $df=isset($_GET['date_from'])?sanitize_text_field($_GET['date_from']):'';$dt=isset($_GET['date_to'])?sanitize_text_field($_GET['date_to']):'';
        if((!empty($df)||!empty($dt))&&function_exists('filter_cards_by_date_range')){$cc=filter_cards_by_date_range($cc,$df,$dt);}
        return $cc;
    } 
}

if (!function_exists('wp_trello_get_all_lists_for_board')) {
    function wp_trello_get_all_lists_for_board($boardId, $apiKey, $apiToken) {
        $transient_key = 'wp_trello_all_lists_v1_' . $boardId;
        $cached_lists = get_transient($transient_key);
        if (false !== $cached_lists) {
            return $cached_lists;
        }

        $url = "https://api.trello.com/1/boards/{$boardId}/lists?filter=all&fields=id,closed&key={$apiKey}&token={$apiToken}";
        $response = wp_remote_get($url, ['timeout' => 20]);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            error_log("Errore API Trello (wp_trello_get_all_lists_for_board per board $boardId): " . print_r($response, true));
            return [];
        }

        $lists = json_decode(wp_remote_retrieve_body($response), true);
        set_transient($transient_key, $lists, 15 * MINUTE_IN_SECONDS);
        return $lists;
    }
}