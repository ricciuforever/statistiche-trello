<?php

// Funzione per generare il filtro delle provenienze (checkbox)
function generate_provenance_filter($cards_for_this_filter_counts, $board_provenance_ids_map) {
    // ... la logica interna di questa funzione non cambia ...
    $provenances = [];
    $nd_provenance_label = 'N/D (Non specificata)'; 

    if (is_array($cards_for_this_filter_counts)) {
        foreach ($cards_for_this_filter_counts as $card) {
            $raw_provenance_value = null; 
            $card_board_id = $card['idBoard'] ?? null;
            $provenance_field_id_for_this_board = ($card_board_id && isset($board_provenance_ids_map[$card_board_id])) ? $board_provenance_ids_map[$card_board_id] : null;

            if ($provenance_field_id_for_this_board && !empty($card['customFieldItems'])) {
                foreach ($card['customFieldItems'] as $customField) {
                    if (isset($customField['idCustomField']) && $customField['idCustomField'] === $provenance_field_id_for_this_board) {
                        if (isset($customField['value']['text']) && !empty(trim($customField['value']['text']))) {
                            $raw_provenance_value = trim($customField['value']['text']);
                        }
                        break; 
                    }
                }
            }
            $normalized_provenance = wp_trello_normalize_provenance_value($raw_provenance_value);
            $provenance_key_for_counting = ($normalized_provenance !== null) ? $normalized_provenance : $nd_provenance_label;
            
            if (!isset($provenances[$provenance_key_for_counting])) $provenances[$provenance_key_for_counting] = 0;
            $provenances[$provenance_key_for_counting]++;
        }
    }
    
    if (isset($provenances[$nd_provenance_label])) {
        $nd_count = $provenances[$nd_provenance_label];
        unset($provenances[$nd_provenance_label]);
        ksort($provenances); 
        $provenances[$nd_provenance_label] = $nd_count; 
    } else {
        ksort($provenances); 
    }

    $selectedProvenances_from_GET = isset($_GET['provenances']) ? (array) $_GET['provenances'] : [];

    echo '<fieldset class="uk-fieldset uk-margin-bottom">';
    echo '<legend class="uk-legend">Filtra per Provenienza:</legend>';
    if (empty($provenances)) {
        echo '<p>Nessuna provenienza corrispondente.</p>';
    } else {
        foreach ($provenances as $provenance_item => $count) {
            $checked = in_array($provenance_item, $selectedProvenances_from_GET) ? 'checked' : '';
            echo '<div class="uk-margin-small"><label class="uk-form-label"><input class="uk-checkbox" type="checkbox" name="provenances[]" value="' . esc_attr($provenance_item) . '" ' . $checked . '> ' . esc_html($provenance_item) . ' (' . $count . ')</label></div>';
        }
    }
    echo '</fieldset>';
}

// *** MODIFICATA SIGNIFICATIVAMENTE PER USARE UN SELECT MULTIPLO ***
function generate_state_filter_from_labels($cards_for_this_filter_counts) {
    $states = [];
     if (is_array($cards_for_this_filter_counts)) {
        foreach ($cards_for_this_filter_counts as $card) {
            if (!empty($card['labels'])) {
                foreach ($card['labels'] as $label) {
                    if (isset($label['color']) && ($label['color'] === 'orange_dark' || $label['color'] === 'orange') && !empty($label['name'])) {
                        if (wp_trello_is_state_displayable($label['name'])) {
                            $state_value = $label['name'];
                            if (!isset($states[$state_value])) $states[$state_value] = 0;
                            $states[$state_value]++;
                        }
                    }
                }
            }
        }
    }
    ksort($states);
    $selectedStates_from_GET = isset($_GET['states']) ? (array) $_GET['states'] : [];
    
    echo '<fieldset class="uk-fieldset uk-margin-bottom">';
    echo '<legend class="uk-legend">Filtra per Stato:</legend>';
    if (empty($states)) {
        echo '<p>Nessuno stato corrispondente.</p>';
    } else {
        // Usa un select multiplo con classe uk-select
        echo '<select class="uk-select" name="states[]" multiple size="8">';
        foreach ($states as $state_item => $count) {
            $selected = in_array($state_item, $selectedStates_from_GET) ? 'selected' : '';
            echo '<option value="' . esc_attr($state_item) . '" ' . $selected . '>' . esc_html($state_item) . ' (' . $count . ')</option>';
        }
        echo '</select>';
        echo '<div class="uk-text-meta uk-margin-small-top">Tieni premuto Ctrl (o Cmd su Mac) per selezionare più opzioni.</div>';
    }
    echo '</fieldset>';
}

function generate_temperature_filter($cards_for_this_filter_counts) {
    // ... la logica interna di questa funzione non cambia ...
    $temperatures = [];
    if (is_array($cards_for_this_filter_counts)) {
        foreach ($cards_for_this_filter_counts as $card) {
            if (!empty($card['labels'])) {
                foreach ($card['labels'] as $label) {
                    if (isset($label['color']) && ($label['color'] === 'orange_light' || $label['color'] === 'orange') && !empty($label['name'])) {
                        if (wp_trello_is_temperature_displayable($label['name'])) { 
                            $raw_temp_value = $label['name'];
                            $normalized_temp = wp_trello_normalize_temperature_value($raw_temp_value); 
                            if ($normalized_temp !== null) { 
                                if (!isset($temperatures[$normalized_temp])) $temperatures[$normalized_temp] = 0;
                                $temperatures[$normalized_temp]++;
                            }
                        }
                    }
                }
            }
        }
    }
    ksort($temperatures);
    $selectedTemperatures_from_GET = isset($_GET['temperatures']) ? (array) $_GET['temperatures'] : [];

    echo '<fieldset class="uk-fieldset uk-margin-bottom">';
    echo '<legend class="uk-legend">Filtra per Temperatura:</legend>';
     if (empty($temperatures)) {
        echo '<p>Nessuna temperatura corrispondente.</p>';
    } else {
        foreach ($temperatures as $temperature_item => $count) {
            $checked = in_array($temperature_item, $selectedTemperatures_from_GET) ? 'checked' : '';
            echo '<div class="uk-margin-small"><label class="uk-form-label"><input class="uk-checkbox" type="checkbox" name="temperatures[]" value="' . esc_attr($temperature_item) . '" ' . $checked . '> ' . esc_html($temperature_item) . ' (' . $count . ')</label></div>';
        }
    }
    echo '</fieldset>';
}

// *** MODIFICATO per rimuovere lo switcher "Fonte Lead" e usare classi Bootstrap ***
function generate_combined_filters($base_cards_for_all_filtering, $board_provenance_ids_map) {
    echo '<form method="get" class="uk-form-stacked">'; 
    echo '<input type="hidden" name="page" value="wp-trello-plugin">';

    // --- Switcher Fonte Lead RIMOSSO ---
    
    $active_provenances = isset($_GET['provenances']) ? (array) $_GET['provenances'] : [];
    $active_states = isset($_GET['states']) ? (array) $_GET['states'] : [];
    $active_temperatures = isset($_GET['temperatures']) ? (array) $_GET['temperatures'] : [];
    
    $cards_for_provenance_counts = $base_cards_for_all_filtering;
    if (!empty($active_states)) $cards_for_provenance_counts = filter_cards_by_state_from_labels($cards_for_provenance_counts, $active_states);
    if (!empty($active_temperatures)) $cards_for_provenance_counts = filter_cards_by_temperature($cards_for_provenance_counts, $active_temperatures);
    generate_provenance_filter($cards_for_provenance_counts, $board_provenance_ids_map);
    
    $cards_for_state_counts = $base_cards_for_all_filtering;
    if (!empty($active_provenances)) $cards_for_state_counts = filter_cards_by_provenance($cards_for_state_counts, $active_provenances, $board_provenance_ids_map);
    if (!empty($active_temperatures)) $cards_for_state_counts = filter_cards_by_temperature($cards_for_state_counts, $active_temperatures);
    generate_state_filter_from_labels($cards_for_state_counts);

    $cards_for_temperature_counts = $base_cards_for_all_filtering;
    if (!empty($active_provenances)) $cards_for_temperature_counts = filter_cards_by_provenance($cards_for_temperature_counts, $active_provenances, $board_provenance_ids_map);
    if (!empty($active_states)) $cards_for_temperature_counts = filter_cards_by_state_from_labels($cards_for_temperature_counts, $active_states);
    generate_temperature_filter($cards_for_temperature_counts);
    
    echo '<hr>';

    $dateFrom_from_GET = isset($_GET['date_from']) ? esc_attr($_GET['date_from']) : '';
    $dateTo_from_GET = isset($_GET['date_to']) ? esc_attr($_GET['date_to']) : '';

    echo '<div class="uk-margin-top">'; 
    $dateFrom_from_GET = isset($_GET['date_from']) ? esc_attr($_GET['date_from']) : '';
    $dateTo_from_GET = isset($_GET['date_to']) ? esc_attr($_GET['date_to']) : '';
    echo '<div class="uk-margin-small-bottom"><label for="date_from" class="uk-form-label">Da Data Creazione:</label><div class="uk-form-controls"><input type="date" id="date_from" name="date_from" value="' . $dateFrom_from_GET . '" class="uk-input uk-width-1-1"></div></div>';
    echo '<div class="uk-margin-small-bottom"><label for="date_to" class="uk-form-label">A Data Creazione:</label><div class="uk-form-controls"><input type="date" id="date_to" name="date_to" value="' . $dateTo_from_GET . '" class="uk-input uk-width-1-1"></div></div>';
    echo '<div class="uk-margin-top"><button type="submit" class="uk-button uk-button-primary uk-width-1-1">Filtra</button></div>';
    echo '</div>'; 
    echo '</form>'; 
}


function filter_cards_by_provenance($cards_to_filter, $selectedProvenances, $board_provenance_ids_map) {
    if (empty($selectedProvenances) || !is_array($cards_to_filter)) return $cards_to_filter;
    $nd_provenance_label = 'N/D (Non specificata)'; $filteredCards = [];
    foreach ($cards_to_filter as $card) {
        $raw_provenance_value = null; 
        $card_board_id = $card['idBoard'] ?? null;
        $provenance_field_id_for_this_board = ($card_board_id && isset($board_provenance_ids_map[$card_board_id])) ? $board_provenance_ids_map[$card_board_id] : null;
        if ($provenance_field_id_for_this_board && !empty($card['customFieldItems'])) {
            foreach ($card['customFieldItems'] as $customField) {
                if (isset($customField['idCustomField']) && $customField['idCustomField'] === $provenance_field_id_for_this_board) {
                    if (isset($customField['value']['text']) && !empty(trim($customField['value']['text']))) $raw_provenance_value = trim($customField['value']['text']);
                    break; 
                }
            }
        }
        $normalized_card_provenance = wp_trello_normalize_provenance_value($raw_provenance_value);
        $actual_provenance_for_filtering = ($normalized_card_provenance !== null) ? $normalized_card_provenance : $nd_provenance_label; 
        if (in_array($actual_provenance_for_filtering, $selectedProvenances)) $filteredCards[] = $card;
    }
    return $filteredCards;
}

function filter_cards_by_temperature($cards_to_filter, $selectedTemperatures) {
    if (empty($selectedTemperatures) || !is_array($cards_to_filter)) return $cards_to_filter;
    $filteredCards = [];
    foreach ($cards_to_filter as $card) {
        $temperature_to_check_normalized = null; 
        if (!empty($card['labels'])) {
            foreach ($card['labels'] as $label) {
                if (isset($label['color']) && ($label['color'] === 'orange_light' || $label['color'] === 'orange') && !empty($label['name'])) {
                    if (wp_trello_is_temperature_displayable($label['name'])) {
                        $temperature_to_check_normalized = wp_trello_normalize_temperature_value($label['name']);
                        break; 
                    }
                }
            }
        }
        if ($temperature_to_check_normalized !== null && in_array($temperature_to_check_normalized, $selectedTemperatures)) {
            $filteredCards[] = $card;
        }
    }
    return $filteredCards;
}

function filter_cards_by_state_from_labels($cards_to_filter, $selectedStates) {
    if (empty($selectedStates) || !is_array($cards_to_filter)) return $cards_to_filter;
    $filteredCards = [];
    foreach ($cards_to_filter as $card) {
        $displayable_card_states = [];
        if (!empty($card['labels'])) {
            foreach ($card['labels'] as $label) {
                if (isset($label['color']) && ($label['color'] === 'orange_dark' || $label['color'] === 'orange') && !empty($label['name'])) {
                    if (wp_trello_is_state_displayable($label['name'])) {
                        $displayable_card_states[] = $label['name'];
                    }
                }
            }
        }
        if (!empty($displayable_card_states) && array_intersect($displayable_card_states, $selectedStates)) {
            $filteredCards[] = $card;
        }
    }
    return $filteredCards;
}

function filter_cards_by_date_range($cards_to_filter, $dateFrom, $dateTo) {
    if ((empty($dateFrom) && empty($dateTo)) || !is_array($cards_to_filter)) return $cards_to_filter; 
    $filteredCards = [];
    foreach ($cards_to_filter as $card) {
        $dateCreated = null; 
        if (!empty($card['id'])) {
            $timestamp = hexdec(substr($card['id'], 0, 8));
            $dateCreated = date('Y-m-d', $timestamp); 
        }
        if (!$dateCreated && (!empty($dateFrom) || !empty($dateTo))) continue;
        $includeCard = true;
        if (!empty($dateFrom) && $dateCreated < $dateFrom) $includeCard = false;
        if (!empty($dateTo) && $dateCreated > $dateTo) $includeCard = false;
        if ($includeCard) $filteredCards[] = $card;
    }
    return $filteredCards;
}