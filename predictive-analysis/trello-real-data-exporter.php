<?php
/**
 * Modulo per l'esportazione dei dati reali (commenti) dalle schede Trello
 * per l'analisi avanzata esterna. (Versione 4.2 - Ottimizzato con Cache)
 */

if (!defined('ABSPATH')) exit;

add_action('wp_ajax_stpa_export_real_data', 'stpa_ajax_export_real_data');

function stpa_ajax_export_real_data() {
    if (!check_ajax_referer('stpa_nonce', 'nonce', false) || !current_user_can('manage_options')) {
        wp_send_json_error('Autorizzazione fallita.', 403);
        return;
    }

    $card_ids_json = isset($_POST['card_ids_json']) ? wp_unslash($_POST['card_ids_json']) : '[]';
    $card_ids = json_decode($card_ids_json, true);

    if (!is_array($card_ids) || empty($card_ids)) {
        wp_send_json_error('Nessun ID di scheda fornito.');
        return;
    }

    $export_data = [];
    $errors = 0;

    foreach ($card_ids as $card_id) {
        $card_id = sanitize_text_field($card_id);
        $card_details = stpa_get_card_details($card_id); // Usa la funzione già esistente

        // --- MODIFICA CHIAVE ---
        // Utilizziamo la funzione di cache per recuperare i commenti in modo efficiente,
        // invece di fare una chiamata API diretta per ogni singola scheda.
        $comments_result = stpa_get_card_comments_cached($card_id);

        $comments = [];
        if ($comments_result['error']) {
            $errors++;
            error_log('[Trello Export] Errore nel recuperare i commenti (probabilmente dalla cache o API) per la scheda: ' . $card_id);
            // Lasciamo $comments come array vuoto in caso di errore.
        } else {
            // Se non ci sono errori, usiamo i commenti ottenuti (freschi o da cache).
            $comments = $comments_result['comments'];
        }
        // --- FINE MODIFICA ---

        $export_data[] = [
            'card_id'   => $card_id,
            'card_name' => $card_details['name'],
            'card_url'  => $card_details['url'],
            'comments'  => $comments
        ];
    }

    wp_send_json_success([
        'message' => 'Esportazione completata. ' . count($card_ids) . ' schede processate con ' . $errors . ' errori nel recupero dei commenti.',
        'data' => json_encode($export_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
    ]);
}