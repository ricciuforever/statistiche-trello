<?php
/**
 * File per la gestione dei grafici e tabelle marketing combinati.
 */

if (!defined('ABSPATH')) exit;

/**
 * Recupera e processa i dati dei COSTI e dei LEAD marketing dal Google Sheet CSV.
 */
if (!function_exists('wp_trello_get_marketing_data_with_leads_costs_v19')) {
    function wp_trello_get_marketing_data_with_leads_costs_v19($start_date_threshold_str = '2024-12-30') { 
        $transient_key = 'wp_trello_mkt_data_lc_v8_all_data';
        $cached_data = get_transient($transient_key);
        if (false !== $cached_data) return $cached_data;

        $sheet_id = '14tbboO3k_sjS4fLBR6isQZFxwM3wo21T79Oh-JC1QsE'; $gid = '365998424';
        $csv_url = "https://docs.google.com/spreadsheets/d/{$sheet_id}/export?format=csv&gid={$gid}";
        $response = wp_remote_get($csv_url, array('timeout' => 20));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) return [];
        $csv_data = wp_remote_retrieve_body($response);
        $lines = explode("\n", trim($csv_data));
        if (count($lines) < 1) return [];
        $header_line = array_shift($lines); $headers = str_getcsv(trim($header_line));
        if (empty($headers) || !in_array('Settimana', $headers)) return [];

        $platform_data_structure = [];
        
        $all_csv_headers = array_map('trim', $headers);
        $first_col_name = $all_csv_headers[0]; 
        $last_col_name = end($all_csv_headers);   

        foreach($all_csv_headers as $header_name_csv) {
            if ($header_name_csv === $first_col_name || $header_name_csv === 'Settimana Chiave' || $header_name_csv === $last_col_name) continue;
            
            $base_platform_name_for_key = ''; 
            $is_cost_column = false;

            if (stripos($header_name_csv, 'Costo ') === 0) {
                $base_platform_name_for_key = trim(str_ireplace('Costo ', '', $header_name_csv));
                $is_cost_column = true;
            } else {
                $base_platform_name_for_key = $header_name_csv; 
            }

            $internal_platform_key = wp_trello_normalize_platform_name($base_platform_name_for_key);

            if (!empty($internal_platform_key)) {
                if (!isset($platform_data_structure[$internal_platform_key])) {
                    $platform_data_structure[$internal_platform_key] = ['lead_col' => null, 'cost_col' => null];
                }
                if ($is_cost_column) {
                    $platform_data_structure[$internal_platform_key]['cost_col'] = $header_name_csv;
                } else {
                    $platform_data_structure[$internal_platform_key]['lead_col'] = $header_name_csv;
                }
            }
        }
        
        $data_by_iso_week = [];
        $csv_current_year_tracker = intval(date('Y', strtotime($start_date_threshold_str))); 
        $last_parsed_csv_month_num = 0;

        foreach ($lines as $line) {
            $trimmed_line = trim($line); if (empty($trimmed_line)) continue;
            $csv_vals = str_getcsv($trimmed_line); if (count($headers) !== count($csv_vals)) continue;
            $row = array_combine($headers, $csv_vals);
            $week_name_csv = trim($row['Settimana'] ?? ''); if (empty($week_name_csv) || strlen($week_name_csv) < 5) continue;

            $parsed_week = wp_trello_csv_parse_week_string_to_iso($week_name_csv, $csv_current_year_tracker, $last_parsed_csv_month_num);
            if (!$parsed_week) continue;
            $iso_key = $parsed_week['iso_week'];

            $week_payload = ['label' => $parsed_week['label'], 'costs' => [], 'leads_csv' => [], 'start_date_obj' => $parsed_week['start_date_obj']];
            
            foreach($platform_data_structure as $internal_platform_name => $cols) {
                $cost_val = 0.0;
                if ($cols['cost_col'] && isset($row[$cols['cost_col']])) {
                    $cost_val = wp_trello_csv_clean_value($row[$cols['cost_col']], true);
                }
                $week_payload['costs'][$internal_platform_name] = $cost_val;

                $lead_val = 0;
                if ($cols['lead_col'] && isset($row[$cols['lead_col']])) {
                     $lead_val = wp_trello_csv_clean_value($row[$cols['lead_col']], false);
                }
                $week_payload['leads_csv'][$internal_platform_name] = $lead_val;
            }
            $data_by_iso_week[$iso_key] = $week_payload;
        }
        set_transient($transient_key, $data_by_iso_week, 1 * HOUR_IN_SECONDS);
        return $data_by_iso_week;
    }
}


if (!function_exists('wp_trello_display_summary_performance_table')) {
    function wp_trello_display_summary_performance_table($filtered_trello_cards, $marketing_data_from_csv, $board_provenance_ids_map, $provenance_to_internal_platform_map) {
        $summary_data = []; $nd_provenance_trello = 'N/D (Non specificata)';
        $date_from_ui_str=isset($_GET['date_from'])?sanitize_text_field($_GET['date_from']):null;
        $date_to_ui_str=isset($_GET['date_to'])?sanitize_text_field($_GET['date_to']):null;
        $filter_start_date_obj=$date_from_ui_str?(new DateTime($date_from_ui_str))->setTime(0,0,0):null;
        $filter_end_date_obj=$date_to_ui_str?(new DateTime($date_to_ui_str))->setTime(23,59,59):null;

        foreach ($provenance_to_internal_platform_map as $trello_prov => $internal_platform) {
            $summary_data[$trello_prov] = ['leads' => 0, 'cost' => 0, 'cpl' => 0];
        }
        if(!isset($summary_data[$nd_provenance_trello])) $summary_data[$nd_provenance_trello] = ['leads' => 0, 'cost' => 0, 'cpl' => 0];

        foreach ($filtered_trello_cards as $card) {
            $norm_prov = wp_trello_get_card_provenance($card, $board_provenance_ids_map);
            $prov_key = ($norm_prov !== null) ? $norm_prov : $nd_provenance_trello;
            if (!isset($summary_data[$prov_key])) $summary_data[$prov_key] = ['leads' => 0, 'cost' => 0, 'cpl' => 0];
            $summary_data[$prov_key]['leads']++;
        }

        if (!empty($marketing_data_from_csv)) {
            foreach ($marketing_data_from_csv as $iso_week_key => $week_data) {
                $week_range = wp_trello_csv_get_iso_week_range($iso_week_key); if (!$week_range) continue;
                $week_start_obj = $week_range['start']; $week_end_obj = $week_range['end'];
                $include_week = true;
                if ($filter_start_date_obj && $week_end_obj < $filter_start_date_obj) $include_week = false;
                if ($filter_end_date_obj && $week_start_obj > $filter_end_date_obj) $include_week = false;
                if ($include_week) {
                    foreach ($provenance_to_internal_platform_map as $trello_prov => $internal_platform_name) {
                        if (!isset($summary_data[$trello_prov])) $summary_data[$trello_prov] = ['leads' => 0, 'cost' => 0, 'cpl' => 0];
                        if (isset($week_data['costs'][$internal_platform_name])) { 
                            $summary_data[$trello_prov]['cost'] += $week_data['costs'][$internal_platform_name];
                        }
                    }
                }
            }
        }
        
        $table_rows = []; $grand_total_leads = 0; $grand_total_cost = 0.0;
        $all_display_provenances = array_keys($provenance_to_internal_platform_map);
        if(isset($summary_data[$nd_provenance_trello]) && $summary_data[$nd_provenance_trello]['leads'] > 0 ) {
            if(!in_array($nd_provenance_trello, $all_display_provenances)) $all_display_provenances[] = $nd_provenance_trello;
        }

        foreach ($all_display_provenances as $prov_key) {
            $leads = $summary_data[$prov_key]['leads'] ?? 0; 
            $cost = $summary_data[$prov_key]['cost'] ?? 0.0;
            if ($leads > 0 || $cost > 0 || ($prov_key === $nd_provenance_trello && ($summary_data[$prov_key]['leads'] ?? 0) > 0) ) {
                $table_rows[$prov_key] = ['provenance' => $prov_key, 'leads' => $leads, 'cost' => $cost, 'cpl' => ($leads > 0 ? ($cost / $leads) : 0.0)];
                $grand_total_leads += $leads; 
                $grand_total_cost += $cost;
            }
        }
        ksort($table_rows);
        if (empty($table_rows) && $grand_total_leads == 0 && $grand_total_cost == 0) { 
            echo '<p>Nessun dato riepilogativo da mostrare per i filtri selezionati.</p>'; return; 
        }

        echo '<div class="uk-card uk-card-default uk-card-body uk-margin-bottom"><h3 class="uk-card-title">Riepilogo Performance Piattaforme</h3><div class="uk-overflow-auto">';
        echo '<table class="uk-table uk-table-small uk-table-striped uk-table-hover"><thead><tr><th>Piattaforma</th><th class="uk-text-right">Lead</th><th class="uk-text-right">Spesa (€)</th><th class="uk-text-right">Costo Lead (€)</th></tr></thead><tbody>';
        foreach ($table_rows as $row) {
            echo '<tr><td>'.esc_html($row['provenance']).'</td><td class="uk-text-right">'.esc_html($row['leads']).'</td><td class="uk-text-right">'.esc_html(number_format($row['cost'],2,',','.')).'</td><td class="uk-text-right">'.esc_html(number_format($row['cpl'],2,',','.')).'</td></tr>';
        }
        echo '</tbody><tfoot><tr class="uk-text-bold"><td>TOTALI</td><td class="uk-text-right">'.esc_html($grand_total_leads).'</td><td class="uk-text-right">'.esc_html(number_format($grand_total_cost,2,',','.')).'</td><td class="uk-text-right">'.esc_html(number_format(($grand_total_leads>0?($grand_total_cost/$grand_total_leads):0.0),2,',','.')).'</td></tr></tfoot></table></div></div>';
    }
}


function wp_trello_display_combined_performance_charts($filtered_trello_cards, $marketing_data_from_csv, $board_provenance_ids_map) {
    $provenance_to_internal_platform_map = [
        'iol'                => 'italiaonline',
        'chiamate'           => 'chiamate',
        'Organic Search'     => 'organico',
        'taormina'           => 'taormina',
        'fb'                 => 'facebook',
        'Google Ads'         => 'google ads',
        'Subito'             => 'subito',
        'poolindustriale.it' => 'pool industriale',
        'archiexpo'          => 'archiexpo',
        'Bakeka'             => 'bakeka',
        'Europage'           => 'europage',
    ];

    wp_trello_display_summary_performance_table($filtered_trello_cards, $marketing_data_from_csv, $board_provenance_ids_map, $provenance_to_internal_platform_map);
    echo '<h4 class="uk-heading-line uk-text-center uk-margin-medium-top"><span>Andamento Settimanale per Piattaforma</span></h4>';

    $start_date_obj_fixed = new DateTime('2024-12-30'); $start_date_obj_fixed->modify('monday this week');
    $end_date_obj_system = new DateTime('now'); $end_date_obj_system->modify('sunday this week');
    $date_from_ui_str=isset($_GET['date_from'])?sanitize_text_field($_GET['date_from']):null;
    $date_to_ui_str=isset($_GET['date_to'])?sanitize_text_field($_GET['date_to']):null;
    $effective_start_date_obj=$start_date_obj_fixed;
    if($date_from_ui_str){$ui_start=(new DateTime($date_from_ui_str))->setTime(0,0,0);if($ui_start>$effective_start_date_obj)$effective_start_date_obj=$ui_start;}
    $effective_end_date_obj=$end_date_obj_system;
    if($date_to_ui_str){$ui_end=(new DateTime($date_to_ui_str))->setTime(23,59,59);if($ui_end<$effective_end_date_obj)$effective_end_date_obj=$ui_end;}
    if($effective_start_date_obj>$effective_end_date_obj)$effective_start_date_obj=clone $effective_end_date_obj;

    $chart_x_axis_iso_weeks=[]; $chart_x_axis_labels=[];    
    $current_loop_date=clone $effective_start_date_obj;
    while($current_loop_date<=$effective_end_date_obj){
        $iso_week=$current_loop_date->format("o-\WW");
        if(!in_array($iso_week,$chart_x_axis_iso_weeks)){
            $chart_x_axis_iso_weeks[]=$iso_week;
            $chart_x_axis_labels[]=isset($marketing_data_from_csv[$iso_week]['label'])?$marketing_data_from_csv[$iso_week]['label']:$current_loop_date->format("d M (\WW)");
        }
        $current_loop_date->modify('+1 week');if(count($chart_x_axis_iso_weeks)>104)break;
    }
    if(empty($chart_x_axis_iso_weeks)){echo "<p>Impossibile determinare l'intervallo di settimane per i grafici con i filtri attuali.</p>";return;}

    $leads_data_for_charts = [];
    foreach ($filtered_trello_cards as $card) {
        if(empty($card['id'])) continue; 
        $card_date_obj = stsg_get_card_creation_date($card['id']);
        if(!$card_date_obj) continue;
        
        $iso_week_key = $card_date_obj->format("o-\WW"); 
        $norm_prov=wp_trello_get_card_provenance($card,$board_provenance_ids_map);
        if($norm_prov===null)$norm_prov='N/D (Non specificata)';
        if(!isset($leads_data_for_charts[$iso_week_key])) $leads_data_for_charts[$iso_week_key] = [];
        if(!isset($leads_data_for_charts[$iso_week_key][$norm_prov])) $leads_data_for_charts[$iso_week_key][$norm_prov] = 0;
        $leads_data_for_charts[$iso_week_key][$norm_prov]++;
    }
    
    $chart_id_counter=0; echo '<div class="row row-cols-1 g-4">'; 
    $active_provenance_filters = isset($_GET['provenances'])?(array)$_GET['provenances']:[]; $charts_generated=0;

    echo '<div class="uk-grid uk-child-width-1-1" uk-grid>'; 

    foreach ($provenance_to_internal_platform_map as $trello_prov_normalizzata => $internal_platform_name) {
        if (!empty($active_provenance_filters) && !in_array($trello_prov_normalizzata, $active_provenance_filters)) continue;
        
        $leads_dataset=[]; $csv_costs_dataset=[]; $has_data_for_this_chart=false;
        foreach ($chart_x_axis_iso_weeks as $iso_week_key) {
            $leads_count = $leads_data_for_charts[$iso_week_key][$trello_prov_normalizzata] ?? 0;
            $leads_dataset[] = $leads_count;
            
            $cost_value = isset($marketing_data_from_csv[$iso_week_key]['costs'][$internal_platform_name]) ? $marketing_data_from_csv[$iso_week_key]['costs'][$internal_platform_name] : 0;
            $csv_costs_dataset[]=$cost_value;
            
            if($leads_count>0||$cost_value>0) $has_data_for_this_chart=true;
        }

        // --- BLOCCO LOGICO CORRETTO ---
        if(!$has_data_for_this_chart && !empty($active_provenance_filters) && in_array($trello_prov_normalizzata, $active_provenance_filters)){
            echo '<div class="col"><div class="card h-100"><div class="card-body"><h5 class="card-title">Performance: '.esc_html($trello_prov_normalizzata).'</h5><p>Nessun dato per questa provenienza con i filtri attuali.</p></div></div></div>';
            continue;
        }
        
        if(!$has_data_for_this_chart) {
            continue;
        }
        // --- FINE BLOCCO LOGICO CORRETTO ---

        $charts_generated++; $canvas_id='combinedChart_'.sanitize_key($trello_prov_normalizzata).'_'.(++$chart_id_counter);
        $lead_dataset_label = 'Lead (Trello)';
        ?>
        <div><div class="uk-card uk-card-default uk-card-body uk-margin-small-bottom">
        <h4 class="uk-card-title">Performance: <?php echo esc_html($trello_prov_normalizzata); ?></h4>
        <div class="chart-container" style="height: 300px;"><canvas id="<?php echo esc_attr($canvas_id); ?>"></canvas></div>
        </div></div>
        <script type="text/javascript">
            document.addEventListener('DOMContentLoaded', function () {
                const ctx_<?php echo esc_js($canvas_id); ?>=document.getElementById('<?php echo esc_js($canvas_id); ?>');
                if(ctx_<?php echo esc_js($canvas_id); ?>){
                    new Chart(ctx_<?php echo esc_js($canvas_id); ?>,{type:'line',data:{
                    labels:<?php echo json_encode($chart_x_axis_labels); ?>,
                    datasets:[
                        /* --- MODIFICA COLORI GRAFICO --- */
                        {label:'<?php echo esc_js($lead_dataset_label); ?>',data:<?php echo json_encode($leads_dataset); ?>,borderColor:'#9C2020',backgroundColor:'rgba(156, 32, 32, 0.2)',yAxisID:'yRequests',tension:0.1,fill:true},
                        {label:'Costo (€ - CSV)',data:<?php echo json_encode($csv_costs_dataset); ?>,borderColor:'#222222',backgroundColor:'rgba(34, 34, 34, 0.2)',yAxisID:'yCosts',tension:0.1,fill:true}
                        /* --- FINE MODIFICA --- */
                    ]},
                    options:{responsive:true,maintainAspectRatio:false,scales:{yRequests:{type:'linear',display:true,position:'left',beginAtZero:true,title:{display:true,text:'N. Lead'}},yCosts:{type:'linear',display:true,position:'right',beginAtZero:true,title:{display:true,text:'Costo (€)'},grid:{drawOnChartArea:false}}},plugins:{legend:{display:true,position:'top'}}}});
                }
            });
        </script>
        <?php
    } 
    echo '</div>'; 
    if ($charts_generated === 0) {
        echo "<p>Nessun dato di performance combinata da visualizzare per le provenienze e i filtri selezionati.</p>";
    }
}