<?php
/**
 * File per la gestione delle chiamate AJAX del Marketing Advisor.
 */

if (!defined('ABSPATH')) exit;

add_action('wp_ajax_stma_get_marketing_advice', 'stma_ajax_get_marketing_advice_handler');

/**
 * Gestisce la richiesta AJAX per ottenere l'analisi di marketing.
 */
function stma_ajax_get_marketing_advice_handler() {
    if (!check_ajax_referer('stma_nonce', 'nonce', false) || !current_user_can('manage_options')) {
        wp_send_json_error(['message' => 'Autorizzazione fallita.'], 403);
    }

    global $wpdb;

    // --- 1. Definizione Canali e Raccolta Dati di Costo ---
    $provenance_map = [
        'iol' => 'italiaonline', 'chiamate' => 'chiamate', 'Organic Search' => 'organico',
        'taormina' => 'taormina', 'fb' => 'facebook', 'Google Ads' => 'google ads',
        'Subito' => 'subito', 'poolindustriale.it' => 'pool industriale', 'archiexpo' => 'archiexpo',
        'Bakeka' => 'bakeka', 'Europage' => 'europage',
    ];

    $marketing_data = wp_trello_get_marketing_data_with_leads_costs_v19();

    $current_iso_week = (new DateTime())->format("o-\WW");
    $current_week_costs = [];
    if (isset($marketing_data[$current_iso_week]['costs'])) {
        $current_week_costs = $marketing_data[$current_iso_week]['costs'];
    }

    // --- 2. Raccolta Dati Lead Trello e Analisi Predittiva ---
    $seven_days_ago = new DateTime('-7 days');
    $trello_timestamp_7_days_ago = dechex($seven_days_ago->getTimestamp());

    $apiKey = defined('TRELLO_API_KEY') ? TRELLO_API_KEY : '';
    $apiToken = defined('TRELLO_API_TOKEN') ? TRELLO_API_TOKEN : '';
    $all_boards = get_trello_boards($apiKey, $apiToken);
    $board_ids = array_column($all_boards, 'id');
    $board_provenance_ids_map = wp_trello_get_provenance_field_ids_map($board_ids, $apiKey, $apiToken);

    $recent_cards = [];
    foreach ($board_ids as $board_id) {
        $cards = get_trello_cards($board_id, $apiKey, $apiToken);
        foreach ($cards as $card) {
            if (hexdec(substr($card['id'], 0, 8)) >= $trello_timestamp_7_days_ago) {
                $recent_cards[] = $card;
            }
        }
    }

    $card_ids = array_column($recent_cards, 'id');
    $predictive_results = [];
    if (!empty($card_ids)) {
        $ids_placeholder = implode(', ', array_fill(0, count($card_ids), '%s'));
        $results_table = $wpdb->prefix . 'stpa_predictive_results';
        $db_results = $wpdb->get_results($wpdb->prepare(
            "SELECT card_id, probability FROM $results_table WHERE card_id IN ($ids_placeholder) ORDER BY created_at DESC",
            $card_ids
        ), ARRAY_A);

        foreach ($db_results as $row) {
            if (!isset($predictive_results[$row['card_id']])) {
                $predictive_results[$row['card_id']] = (float)$row['probability'];
            }
        }
    }

    // --- 3. Aggregazione dei Dati per Canale ---
    $channel_data = [];
    foreach ($provenance_map as $trello_prov => $internal_name) {
        $channel_data[$trello_prov] = [
            'cost' => $current_week_costs[$internal_name] ?? 0,
            'leads' => 0,
            'quality_scores' => ['high' => 0, 'medium' => 0, 'low' => 0, 'unknown' => 0],
        ];
    }

    foreach ($recent_cards as $card) {
        $provenance = wp_trello_get_card_provenance($card, $board_provenance_ids_map) ?? 'N/D';
        if (isset($channel_data[$provenance])) {
            $channel_data[$provenance]['leads']++;
            $prob = $predictive_results[$card['id']] ?? -1;
            if ($prob >= 0.7) $channel_data[$provenance]['quality_scores']['high']++;
            elseif ($prob >= 0.4) $channel_data[$provenance]['quality_scores']['medium']++;
            elseif ($prob >= 0) $channel_data[$provenance]['quality_scores']['low']++;
            else $channel_data[$provenance]['quality_scores']['unknown']++;
        }
    }

    // --- 4. Preparazione Prompt per OpenAI ---
    $data_for_prompt = [];
    foreach ($channel_data as $name => $data) {
        if ($data['leads'] == 0 && $data['cost'] == 0) continue;
        $cpl = ($data['leads'] > 0) ? round($data['cost'] / $data['leads'], 2) : 0;
        $data_for_prompt[] = [
            'canale' => $name,
            'costo_settimanale' => $data['cost'],
            'lead_generati' => $data['leads'],
            'costo_per_lead' => $cpl,
            'qualita_lead' => $data['quality_scores'],
        ];
    }

    if (empty($data_for_prompt)) {
        wp_send_json_error(['message' => 'Nessun dato di marketing o lead trovato per l\'ultima settimana. Impossibile generare analisi.']);
        return;
    }

    $prompt = stma_create_advisor_prompt($data_for_prompt);

    // --- 5. Chiamata a OpenAI ---
    $ai_response = stma_call_openai_api($prompt);

    if (is_wp_error($ai_response) || !isset($ai_response['analysis']) || !isset($ai_response['recommendations'])) {
         wp_send_json_error(['message' => 'L\'IA non ha fornito una risposta valida o si è verificato un errore.']);
         return;
    }

    // --- 6. Formattazione Risposta ---
    $recommendations_html = '<ul>';
    foreach ($ai_response['recommendations'] as $rec) {
        $sign = $rec['percentage_change'] > 0 ? '+' : '';
        $color = $rec['percentage_change'] > 0 ? 'green' : ($rec['percentage_change'] < 0 ? 'red' : 'black');
        $recommendations_html .= sprintf(
            '<li><strong>%s %s:</strong> <span style="color: %s;">%s%d%%</span>. %s</li>',
            esc_html($rec['action']),
            esc_html($rec['channel']),
            esc_attr($color),
            esc_html($sign),
            esc_html($rec['percentage_change']),
            esc_html($rec['justification'])
        );
    }
    $recommendations_html .= '</ul>';

    wp_send_json_success([
        'analysis' => $ai_response['analysis'],
        'recommendations' => $recommendations_html,
    ]);
}

/**
 * Crea il prompt per l'analisi del budget marketing.
 */
function stma_create_advisor_prompt($data) {
    $json_data = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    return <<<PROMPT
Sei un consulente esperto di marketing digitale specializzato nell'ottimizzazione del budget. Il tuo compito è analizzare i dati di performance dei canali di marketing di un'azienda e fornire raccomandazioni chiare e attuabili.

**CONTESTO:**
L'azienda vende tendostrutture e coperture. I dati forniti rappresentano le performance degli ultimi 7 giorni. La "qualità dei lead" è basata su un'analisi predittiva della probabilità di chiusura: 'high' (alta probabilità), 'medium' (media), 'low' (bassa), 'unknown' (non analizzata).

**OBIETTIVO:**
1.  Fornisci un'analisi concisa (2-3 paragrafi) delle performance generali, evidenziando i canali più e meno performanti. Considera sia il costo per lead (CPL) sia la qualità dei lead generati (un canale con CPL basso ma lead di bassa qualità non è efficiente).
2.  Suggerisci degli spostamenti di budget in punti percentuali (es. +10, -15, 0) per ogni canale, con una breve giustificazione per ogni suggerimento.

**DATI DA ANALIZZARE:**
```json
$json_data
```

**FORMATO DI OUTPUT OBBLIGATORIO:**
La tua risposta deve essere **esclusivamente** un oggetto JSON valido. Non includere testo, spiegazioni o markdown prima o dopo il JSON. La struttura deve essere:
{
  "analysis": "La tua analisi testuale qui, formattata in HTML con paragrafi <p> e grassetto <strong> per i punti chiave.",
  "recommendations": [
    {
      "channel": "Nome Canale",
      "action": "Aumenta Budget",
      "percentage_change": 15,
      "justification": "Motivazione concisa."
    },
    {
      "channel": "Nome Canale 2",
      "action": "Riduci Budget",
      "percentage_change": -20,
      "justification": "Motivazione concisa."
    }
  ]
}
PROMPT;
}

/**
 * Funzione dedicata per chiamare l'API di OpenAI per il Marketing Advisor.
 * @param string $prompt_text Il prompt da inviare a OpenAI.
 * @param string $response_format Il formato di risposta desiderato ('json_object' o 'text').
 * @return array|string|WP_Error La risposta da OpenAI o un oggetto WP_Error.
 */
function stma_call_openai_api($prompt_text, $response_format = 'json_object') {
    if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY)) {
        return new WP_Error('api_key_missing', 'La chiave API di OpenAI non è definita.');
    }

    $api_key = OPENAI_API_KEY;
    $api_url = "https://api.openai.com/v1/chat/completions";

    $body = [
        'model' => ($response_format === 'json_object') ? 'gpt-4o-mini' : 'gpt-4o',
        'messages' => [['role' => 'user', 'content' => $prompt_text]],
        'temperature' => 0.5,
    ];

    if ($response_format === 'json_object') {
        $body['response_format'] = ['type' => 'json_object'];
    } else {
        $body['max_tokens'] = 3500;
    }

    $response = wp_remote_post($api_url, [
        'method'  => 'POST',
        'timeout' => 180,
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body'    => json_encode($body)
    ]);

    if (is_wp_error($response)) {
        return $response;
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);

    if ($http_code !== 200) {
        return new WP_Error('api_error', 'La chiamata API ha fallito con codice ' . $http_code, ['body' => $response_body]);
    }

    $data = json_decode($response_body, true);
    $content = $data['choices'][0]['message']['content'] ?? '';

    if (empty($content)) {
        return new WP_Error('empty_response', 'La risposta di OpenAI non contiene il campo "content".');
    }

    if ($response_format === 'json_object') {
        $decoded_json = json_decode($content, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('json_decode_error', 'Il contenuto JSON estratto non è valido.', ['content' => $content]);
        }
        return $decoded_json;
    }

    return $content;
}

function stma_get_predictions_for_period($start_date, $end_date) {
    global $wpdb;
    $results_table = 'wpmq_stpa_predictive_results';
    if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $results_table)) != $results_table) { return new WP_Error('db_table_not_found', "Tabella dei risultati '{$results_table}' non trovata."); }
    $query = $wpdb->prepare("SELECT card_id, probability, period FROM {$results_table} WHERE created_at >= %s AND created_at <= %s AND card_id REGEXP '^[a-f0-9]{24}$'", $start_date->format('Y-m-d H:i:s'), $end_date->format('Y-m-d H:i:s'));
    $results = $wpdb->get_results($query, ARRAY_A);
    if ($wpdb->last_error) { return new WP_Error('db_query_error', 'Query fallita: ' . $wpdb->last_error); }
    $latest_short_term_predictions = [];
    foreach ($results as $row) {
        $card_id = $row['card_id']; $period = (int)$row['period']; $probability = (float)$row['probability'];
        if (!isset($latest_short_term_predictions[$card_id]) || $period < $latest_short_term_predictions[$card_id]['period']) {
            $latest_short_term_predictions[$card_id] = ['probability' => $probability, 'period' => $period];
        }
    }
    return array_map(function($p) { return $p['probability']; }, $latest_short_term_predictions);
}

function stma_get_trello_details_for_cards($card_ids) {
    if (empty($card_ids)) return [];
    global $wpdb;
    $cache_table = defined('STPA_CARDS_CACHE_TABLE') ? STPA_CARDS_CACHE_TABLE : $wpdb->prefix . 'stpa_cards_cache';
    $trello_details = [];
    $placeholders = implode(', ', array_fill(0, count($card_ids), '%s'));
    $results = $wpdb->get_results($wpdb->prepare("SELECT card_id, provenance FROM {$cache_table} WHERE card_id IN ($placeholders)", $card_ids), OBJECT_K);
    if (!empty($results)) {
        foreach ($results as $card_id => $data) {
            $trello_details[$card_id] = ['provenance' => $data->provenance];
        }
    }
    return $trello_details;
}
