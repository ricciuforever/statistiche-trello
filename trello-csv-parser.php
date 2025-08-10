<?php
/**
 * File delle funzioni helper per il parsing del CSV marketing.
 */

if (!defined('ABSPATH')) exit;

if (!function_exists('wp_trello_csv_clean_value')) {
    function wp_trello_csv_clean_value($value_string, $is_cost = false) {
        if ($value_string === null || trim($value_string) === '' || strtolower(trim($value_string)) === 'n/a' || trim($value_string) === '-') {
            return $is_cost ? 0.0 : 0;
        }
        $cleaned = str_replace('€', '', $value_string);
        $cleaned = trim($cleaned);
        if (strpos($cleaned, ',') !== false && strpos($cleaned, '.') !== false) { 
            $cleaned = str_replace('.', '', $cleaned); 
            $cleaned = str_replace(',', '.', $cleaned); 
        } elseif (strpos($cleaned, ',') !== false) { 
             $cleaned = str_replace(',', '.', $cleaned);
        }
        return $is_cost ? floatval($cleaned) : intval($cleaned);
    }
}

if (!function_exists('wp_trello_csv_get_month_number_from_italian_name')) {
    function wp_trello_csv_get_month_number_from_italian_name($month_name_it) {
        $months = ['gennaio'=>'01','febbraio'=>'02','marzo'=>'03','aprile'=>'04','maggio'=>'05','giugno'=>'06','luglio'=>'07','agosto'=>'08','settembre'=>'09','ottobre'=>'10','novembre'=>'11','dicembre'=>'12'];
        return $months[strtolower(trim($month_name_it))] ?? null;
    }
}

if (!function_exists('wp_trello_csv_parse_week_string_to_iso')) {
    function wp_trello_csv_parse_week_string_to_iso($week_string, &$csv_year_tracker, &$last_parsed_csv_month_num) {
        if (empty($week_string) || strpos($week_string, ' - ') === false) return null;
        list($start_part_str, ) = explode(' - ', $week_string, 2);
        if (!preg_match('/^(\d{1,2})\s+([a-zA-Zàèéìòù]+)/', trim($start_part_str), $matches_start)) return null;
        
        $day_start = intval($matches_start[1]);
        $month_name_it_start = $matches_start[2];
        $month_num_start = wp_trello_csv_get_month_number_from_italian_name($month_name_it_start);

        if (!$month_num_start) return null;

        if ($month_num_start && $last_parsed_csv_month_num == 12 && $month_num_start == 1) {
            $csv_year_tracker++;
        }
        if ($month_num_start) $last_parsed_csv_month_num = $month_num_start;

        $date_str_start = sprintf('%04d-%02d-%02d', $csv_year_tracker, $month_num_start, $day_start);
        try {
            $date_obj_start = new DateTime($date_str_start);
            $iso_week_key = $date_obj_start->format("o-\WW"); 
            return ['iso_week' => $iso_week_key, 'label' => $week_string, 'start_date_obj' => $date_obj_start];
        } catch (Exception $e) { 
            error_log("CSV Week Parse Error (Helper): {$date_str_start} from '{$week_string}'. Error: " . $e->getMessage());
            return null; 
        }
    }
}

if (!function_exists('wp_trello_csv_get_iso_week_range')) {
    function wp_trello_csv_get_iso_week_range($iso_week_key) {
        try {
            list($year, $week_num_str) = explode('-W', $iso_week_key);
            $week_num = intval(str_replace('W','',$week_num_str));
            $start_date = new DateTime(); $start_date->setISODate((int)$year, $week_num, 1)->setTime(0,0,0);
            $end_date = clone $start_date; $end_date->modify('+6 days')->setTime(23,59,59);
            return ['start' => $start_date, 'end' => $end_date];
        } catch (Exception $e) { 
            error_log("Errore nel calcolare range per ISO week {$iso_week_key} (Helper): " . $e->getMessage());
            return null; 
        }
    }
}