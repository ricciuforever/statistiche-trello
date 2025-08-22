<?php
/**
 * Modulo per la generazione di infografiche tramite l'API di OpenAI (ChatGPT).
 * Versione 2.2 - Layout a griglia forzato con media query e dati sempre visibili
 */

if (!defined('ABSPATH')) exit;

/**
 * Funzione per chiamare l'API di OpenAI (Chat Completions).
 *
 * @param string $prompt Il prompt da inviare a OpenAI.
 * @return string|WP_Error Il contenuto HTML generato o un errore.
 */
function stma_call_openai_api_for_infographic($prompt) {
    if (!defined('OPENAI_API_KEY') || empty(OPENAI_API_KEY)) {
        return new WP_Error('openai_api_key_missing', 'La chiave API di OpenAI (OPENAI_API_KEY) non è definita in wp-config.php.');
    }

    $api_key = OPENAI_API_KEY;
    $api_url = 'https://api.openai.com/v1/chat/completions';

    $body = [
        'model'       => 'gpt-4o',
        'messages'    => [['role' => 'user', 'content' => $prompt]],
        'temperature' => 0.4, // Leggermente più deterministico
        'max_tokens'  => 4000
    ];

    $response = wp_remote_post($api_url, [
        'method'  => 'POST',
        'timeout' => 120,
        'headers' => [
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer ' . $api_key,
        ],
        'body'    => json_encode($body)
    ]);

    if (is_wp_error($response)) {
        return new WP_Error('openai_wp_error', 'Errore WP_Error durante la chiamata a OpenAI: ' . $response->get_error_message());
    }

    $http_code = wp_remote_retrieve_response_code($response);
    $response_body = wp_remote_retrieve_body($response);
    $data = json_decode($response_body, true);

    if ($http_code !== 200 || !isset($data['choices'][0]['message']['content'])) {
        $error_message = $data['error']['message'] ?? 'Risposta non valida dall\'API di OpenAI.';
        error_log('[Marketing Advisor Infographic] Errore API OpenAI (' . $http_code . '): ' . $response_body);
        return new WP_Error('openai_api_error', 'La chiamata API a OpenAI ha fallito con codice ' . $http_code . '. Risposta: ' . $error_message);
    }

    $raw_html = $data['choices'][0]['message']['content'];
    if (preg_match('/```html(.*?)```/s', $raw_html, $matches)) {
        return trim($matches[1]);
    }
    
    return $raw_html;
}

/**
 * Genera il prompt e chiama l'API di OpenAI per creare un'infografica.
 *
 * @param array $summary_data I dati aggregati dal Marketing Advisor.
 * @return string L'HTML dell'infografica.
 */
function stma_generate_infographic_with_openai($summary_data) {
    $json_summary = json_encode($summary_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    // --- PROMPT MODIFICATO E RINFORZATO ---
    $prompt = <<<PROMPT
**OBIETTIVO:** Genera un'infografica HTML/CSS basata sui dati JSON forniti. La risposta deve essere **solo ed esclusivamente** un blocco di codice che inizia con \`\`\`html e finisce con \`\`\`. Non aggiungere spiegazioni.

**REGOLE DI LAYOUT (OBBLIGATORIE):**
1.  **CSS Grid e Media Queries:** Devi usare CSS Grid per il contenitore `.infographic-grid`. Il layout DEVE essere responsivo usando le seguenti media query ESATTE:
    * **Desktop Largo (> 1600px):** 4 colonne (`grid-template-columns: repeat(4, 1fr);`).
    * **Desktop Standard (1200px - 1599px):** 3 colonne (`grid-template-columns: repeat(3, 1fr);`).
    * **Tablet (768px - 1199px):** 2 colonne (`grid-template-columns: repeat(2, 1fr);`).
    * **Mobile (< 767px):** 1 colonna (`grid-template-columns: 1fr;`).
2.  **Stile Card:** Le card devono essere pulite, con bordi arrotondati, ombra leggera e padding.

**REGOLE DI VISUALIZZAZIONE DATI (OBBLIGATORIE):**
1.  **Qualità Media Lead:** Questo è il dato più importante. Deve essere rappresentato da una barra di progresso. **È OBBLIGATORIO che il valore numerico della percentuale (es. "29.04%") sia SEMPRE visibile come testo sopra o dentro la barra per OGNI SINGOLA CARD.** Non omettere mai questo testo.
2.  **Colori Qualità:** La barra di progresso deve essere colorata in base al valore: verde (`#2ecc71`) per qualità > 40%, giallo (`#f1c40f`) per qualità > 25%, rosso (`#e74c3c`) per qualità <= 25%.
3.  **CPL (Costo per Lead):** Deve essere il numero più evidente nella card dopo il titolo.
4.  **Altri Dati:** Numero Lead e Costo Totale devono essere visibili ma meno prominenti, magari in un footer della card.

**DATI DA UTILIZZARE (JSON):**
{$json_summary}

**ESEMPIO DI CODICE DA PRODURRE (usa questa struttura e queste classi):**
\`\`\`html
<style>
    .infographic-container { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
    .infographic-container h2 { text-align: left; margin-bottom: 25px; font-size: 1.6em; color: #23282d; border-bottom: 1px solid #c3c4c7; padding-bottom: 10px; }
    .infographic-grid { display: grid; gap: 20px; grid-template-columns: 1fr; /* Default mobile */ }
    /* Media Queries Obbligatorie */
    @media (min-width: 768px) { .infographic-grid { grid-template-columns: repeat(2, 1fr); } }
    @media (min-width: 1200px) { .infographic-grid { grid-template-columns: repeat(3, 1fr); } }
    @media (min-width: 1600px) { .infographic-grid { grid-template-columns: repeat(4, 1fr); } }
    
    .info-card { background: #fff; border: 1px solid #e0e0e0; border-radius: 8px; padding: 20px; box-shadow: 0 2px 5px rgba(0,0,0,0.05); display: flex; flex-direction: column; height: 100%; }
    .info-card h3 { font-size: 1.2em; margin-top: 0; margin-bottom: 15px; color: #1d2327; }
    .metric.cpl .value { font-size: 2em; font-weight: 600; color: #3498db; }
    .metric .label { font-size: 0.8em; color: #646970; text-transform: uppercase; }
    .quality-metric { margin: 20px 0; }
    .progress-bar-container { background: #f0f0f1; border-radius: 5px; height: 20px; overflow: hidden; position: relative; }
    .progress-bar { height: 100%; display: flex; align-items: center; justify-content: center; color: white; font-weight: bold; font-size: 0.8em; }
    .card-footer { margin-top: auto; padding-top: 15px; border-top: 1px solid #eee; display: flex; justify-content: space-between; font-size: 0.9em; color: #50575e;}
</style>
<div class="infographic-container">
    <h2>Panoramica Performance Canali</h2>
    <div class="infographic-grid">
        </div>
</div>
\`\`\`
PROMPT;

    $infographic_html = stma_call_openai_api_for_infographic($prompt);

    if (is_wp_error($infographic_html)) {
        return '<div class="notice notice-error"><p><strong>Errore durante la generazione dell\'infografica:</strong> ' . esc_html($infographic_html->get_error_message()) . '</p></div>';
    }

    return $infographic_html;
}