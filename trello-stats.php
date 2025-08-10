<?php

if (!function_exists('wp_trello_calculate_daily_statistics')) {
    function wp_trello_calculate_daily_statistics($cards_to_process, $board_provenance_ids_map, $selectedDate = null) {
        $dateToCheck = $selectedDate ? $selectedDate : date('Y-m-d');
        $nd_provenance_label = 'N/D (Non specificata)';

        $stats = [
            'total_today' => 0,
            'by_provenance' => []
        ];

        if (empty($cards_to_process) || !is_array($cards_to_process)) {
            return $stats;
        }

        foreach ($cards_to_process as $card) {
            // --- MODIFICA CHIAVE: Utilizziamo la funzione di data più affidabile ---
            // Invece di calcolare la data dall'ID, usiamo la funzione che controlla anche le entry di Gravity Forms.
            $card_creation_date_obj = stsg_get_card_creation_date($card['id']);
            
            if ($card_creation_date_obj) {
                $dateCreated = $card_creation_date_obj->format('Y-m-d');

                if ($dateCreated === $dateToCheck) {
                    $stats['total_today']++;
                    
                    // La logica per la provenienza rimane invariata
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
                    $provenance_key_for_stats = ($normalized_provenance !== null) ? $normalized_provenance : $nd_provenance_label;

                    if (!isset($stats['by_provenance'][$provenance_key_for_stats])) {
                        $stats['by_provenance'][$provenance_key_for_stats] = 0;
                    }
                    $stats['by_provenance'][$provenance_key_for_stats]++;
                }
            }
        }
        
        if (isset($stats['by_provenance'][$nd_provenance_label])) {
            $nd_count_stats = $stats['by_provenance'][$nd_provenance_label];
            unset($stats['by_provenance'][$nd_provenance_label]);
            ksort($stats['by_provenance']);
            $stats['by_provenance'][$nd_provenance_label] = $nd_count_stats;
        } else {
            ksort($stats['by_provenance']);
        }
        return $stats;
    }
}

if (!function_exists('wp_trello_display_trello_statistics')) {
    function wp_trello_display_trello_statistics($stats, $selectedDateForDisplay) {
        $today = date('Y-m-d');
        $dateLabel = ($selectedDateForDisplay === $today) ? 'oggi' : date('d/m/Y', strtotime($selectedDateForDisplay));
        if (empty($selectedDateForDisplay)) { 
            $dateLabel = 'oggi (' . date('d/m/Y') . ')';
        }

        echo '<div class="uk-card uk-card-default uk-card-body uk-margin-top">';
        echo '<h3 class="uk-card-title">Statistiche del Giorno: ' . esc_html($dateLabel) . '</h3>';
        
        if (isset($stats['total_today'])) {
            echo '<p><strong>Totale schede create (' . esc_html($dateLabel) . '):</strong> ' . esc_html($stats['total_today']) . '</p>';

            if (!empty($stats['by_provenance'])) {
                echo '<h4>Suddivisione per Provenienza:</h4>';
                echo '<ul class="uk-list uk-list-bullet">';
                foreach ($stats['by_provenance'] as $provenance => $count) {
                    echo '<li>' . esc_html($provenance) . ': ' . esc_html($count) . '</li>';
                }
                echo '</ul>';
            } elseif ($stats['total_today'] > 0) {
                 echo '<p>Nessuna suddivisione per provenienza disponibile per le schede create.</p>';
            }
        } else {
             echo '<p>Dati statistici non disponibili.</p>';
        }
        
        if (isset($stats['total_today']) && $stats['total_today'] == 0 && !empty($selectedDateForDisplay)) {
             echo '<p>Nessuna scheda creata nel giorno selezionato (' . esc_html($dateLabel) . ') che corrisponda ai criteri.</p>';
        }

        echo '</div>';
    }
}
