<?php
if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

if (!class_exists('WP_Trello_Cards_Table')) { 
    class WP_Trello_Cards_Table extends WP_List_Table { 
        private $all_cards_data; 

        public function __construct($cards_data) {
            parent::__construct(['singular' => 'scheda Trello', 'plural'   => 'schede Trello', 'ajax' => false]);
            $this->all_cards_data = $cards_data;
        }

        public function get_columns() {
            return [
                'name' => 'Nome Scheda (Numero)', 'provenance' => 'Provenienza',
                'state' => 'Stato', 'temperature' => 'Temperatura',
                'date_created' => 'Data Creazione', 'date_last_active'=> 'Ultima Attività Trello', 
            ];
        }

        protected function column_default($item, $column_name) {
            return esc_html($item[$column_name] ?? '');
        }
        
        private function usort_reorder($a, $b) {
            $orderby = (!empty($_REQUEST['orderby'])) ? $_REQUEST['orderby'] : 'date_created';
            $order = (!empty($_REQUEST['order'])) ? $_REQUEST['order'] : 'desc';
            $result = 0; $valueA = $a[$orderby] ?? ''; $valueB = $b[$orderby] ?? '';
            if ($orderby === 'name') $result = intval($valueA) - intval($valueB);
            else $result = strcmp((string)$valueA, (string)$valueB);
            return ($order === 'asc') ? $result : -$result;
        }

        public function prepare_items() {
            $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
            if (!empty($this->all_cards_data)) {
                 usort($this->all_cards_data, array($this, 'usort_reorder'));
            }
            $per_page = 20; $current_page = $this->get_pagenum(); $total_items = count($this->all_cards_data);
            $this->items = array_slice($this->all_cards_data, (($current_page - 1) * $per_page), $per_page);
            $this->set_pagination_args(['total_items' => $total_items, 'per_page' => $per_page, 'total_pages' => ceil($total_items / $per_page)]);
        }
        
        public function get_sortable_columns(){
            return [ 
                'name' => ['name', false], 'provenance' => ['provenance', false],
                'state' => ['state', false], 'temperature' => ['temperature', false],
                'date_created' => ['date_created', true], 'date_last_active' => ['date_last_active', false],
            ];
        }
    }
}

if (!function_exists('generate_filtered_table')) {
    function generate_filtered_table($filteredCardsForTableDisplay, $board_provenance_ids_map, $stats_data_for_display, $date_for_stats_label) {
        $cards_for_list_table = [];
        $nd_provenance_label = 'N/D (Non specificata)';
        $nd_general_label = 'N/D';

        if (is_array($filteredCardsForTableDisplay)) {
            foreach ($filteredCardsForTableDisplay as $card) {
                $name = isset($card['name']) ? $card['name'] : $nd_general_label;
                $labels = isset($card['labels']) ? $card['labels'] : [];
                $dateLastActivity = !empty($card['dateLastActivity']) ? date('Y-m-d H:i', strtotime($card['dateLastActivity'])) : $nd_general_label;
                $dateCreated = $nd_general_label;
                if (!empty($card['id'])) {
                    $timestamp = hexdec(substr($card['id'], 0, 8));
                    $dateCreated = date('Y-m-d H:i', $timestamp);
                }

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
                $normalized_provenance = wp_trello_normalize_provenance_value($raw_provenance_value);
                $provenance_for_table = ($normalized_provenance !== null) ? $normalized_provenance : $nd_provenance_label;

                $displayable_state_labels = [];
                if (!empty($labels)) {
                    foreach ($labels as $label) {
                        if (isset($label['color']) && ($label['color'] === 'orange_dark' || $label['color'] === 'orange') && !empty($label['name'])) {
                            if (wp_trello_is_state_displayable($label['name'])) $displayable_state_labels[] = $label['name'];
                        }
                    }
                }
                $state_for_table = !empty($displayable_state_labels) ? implode(', ', $displayable_state_labels) : $nd_general_label;
                
                $temperature_for_table = $nd_general_label;
                if (!empty($labels)) {
                    foreach ($labels as $label) {
                         if (isset($label['color']) && ($label['color'] === 'orange_light' || $label['color'] === 'orange') && !empty($label['name'])) {
                            if (wp_trello_is_temperature_displayable($label['name'])) {
                                $temperature_for_table = wp_trello_normalize_temperature_value($label['name']); // NORMALIZZA QUI
                                if($temperature_for_table === null) $temperature_for_table = $nd_general_label; // Se la normalizzazione restituisce null
                                break;
                            }
                        }
                    }
                }

                $cards_for_list_table[] = [
                    'name' => $name, 'provenance' => $provenance_for_table, 
                    'state' => $state_for_table, 'temperature' => $temperature_for_table, 
                    'date_created' => $dateCreated, 'date_last_active'=> $dateLastActivity,
                ];
            }
        }
        
        $table = new WP_Trello_Cards_Table($cards_for_list_table); 
        $table->prepare_items();
        echo '<div class="uk-overflow-auto">'; $table->display(); echo '</div>';

        if (function_exists('wp_trello_display_trello_statistics')) {
            wp_trello_display_trello_statistics($stats_data_for_display, $date_for_stats_label);
        }
    }
}