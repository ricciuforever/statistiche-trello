<?php
/**
 * Modulo Marketing Advisor AI
 * Gestisce la pagina di amministrazione e la logica AJAX per l'analisi strategica.
 * Versione 3.1 - Correzione errore AJAX fatale (include mancante) e aggiunta controlli di sicurezza.
 */

if (!defined('ABSPATH')) exit;

// =========================================================================
// ==> CORREZIONE 1: Includi il file con la funzione per generare l'infografica.
// Questo risolve l'errore fatale che causava il fallimento della chiamata AJAX.
require_once(plugin_dir_path(__FILE__) . 'marketing-advisor-infographic.php');
require_once(plugin_dir_path(__FILE__) . 'marketing-advisor-ajax.php');
// =========================================================================

// --- Costanti e Setup Tabella ---
define('STMA_VERSION', '1.0.0');
define('STMA_DB_VERSION_OPTION', 'stma_db_version');
global $wpdb;
define('STMA_RESULTS_TABLE', $wpdb->prefix . 'stma_analysis_results');

add_action('admin_init', 'stma_check_and_upgrade_db');

function stma_check_and_upgrade_db() {
    if (get_option(STMA_DB_VERSION_OPTION) != STMA_VERSION) {
        stma_create_results_table();
        update_option(STMA_DB_VERSION_OPTION, STMA_VERSION);
    }
}

function stma_create_results_table() {
    global $wpdb;
    $table_name = STMA_RESULTS_TABLE;
    $charset_collate = $wpdb->get_charset_collate();
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');

    $sql = "CREATE TABLE $table_name (
        id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        results_json LONGTEXT NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    dbDelta($sql);
}


/**
 * Funzione per renderizzare la pagina di amministrazione del Marketing Advisor.
 */
function stma_render_advisor_page() {
    // Assicurati che la funzione per ottenere gli intervalli delle settimane sia disponibile
    if (!function_exists('stsg_get_week_ranges')) {
        echo '<div class="notice notice-error"><p>Errore: la funzione `stsg_get_week_ranges` non è disponibile. Assicurati che il file `sheet/sheet-generator.php` sia incluso correttamente.</p></div>';
        return;
    }
    $week_ranges = stsg_get_week_ranges();
    ?>
    <style>
        .wrap.stma-wrap { max-width: none !important; }
        .stma-wrap { margin-top: 20px; width: 100%; padding-left: 20px; padding-right: 20px; box-sizing: border-box; }
        .stma-wrap h1 .dashicons-before { font-size: 32px; height: 32px; width: 32px; margin-right: 8px; }
        .stma-controls { margin-top: 20px; margin-bottom: 30px; }
        .stma-date-range-selector { display: flex; flex-wrap: wrap; gap: 15px; align-items: center; margin-bottom: 20px; background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 4px; }
        .stma-date-range-selector label { font-weight: bold; }
        #stma-results-container { display: none; margin-top: 20px; }
        #stma-loader { text-align: center; padding: 40px 0; }
        #stma-loader .spinner { float: none; margin: 0 auto 10px auto; vertical-align: middle; }
        #stma-loader p { font-size: 1.1em; }
        .stma-results-content h3, .stma-infographic-content h2 { font-size: 1.4em; font-weight: 600; margin-top: 1.5em; margin-bottom: 1em; border-bottom: 2px solid #eee; padding-bottom: 10px; }
        #stma-infographic-content { margin-bottom: 30px; }
        /*
 * ===================================================================
 * FIX PER SOVRAPPOSIZIONE CARD SU MOBILE v1.0
 * ===================================================================
 * Aggiungi questo blocco per correggere il layout dell'infografica
 * su schermi di larghezza inferiore a 767px.
*/
    /*
     * Selezioniamo le card solo all'interno dell'infografica per non
     * interferire con altri elementi della pagina.
     */
    #stma-infographic-content .info-card {
        /*
         * La regola !important è necessaria per sovrascrivere gli stili
         * generati dinamicamente dall'AI, che sono "inline" o più specifici.
         */
        height: auto !important;         /* Rimuove altezze fisse problematiche */
        margin-bottom: 20px !important; /* Garantisce spazio verticale tra le card */
        position: static !important;      /* Resetta il posizionamento per evitare conflitti */
        float: none !important;           /* Impedisce ai float di creare problemi */
        clear: both;                      /* Pulisce eventuali float precedenti */
    }

    /* Assicura che l'ultima card non abbia un margine extra */
    #stma-infographic-content .info-card:last-child {
        margin-bottom: 0 !important;
    }
    </style>

    <div class="wrap stma-wrap">
        <h1><span class="dashicons-before dashicons-robot"></span> Marketing Advisor AI</h1>
        <p>Questo strumento analizza i dati di marketing del periodo selezionato, li combina con le previsioni di chiusura dei lead e fornisce suggerimenti strategici per ottimizzare il budget.</p>
        <div class="stma-controls">
            <div class="stma-date-range-selector">
                <label for="stma_start_week">Da:</label>
                <select id="stma_start_week" name="stma_start_week"><?php foreach ($week_ranges as $key => $text) : ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($text); ?></option><?php endforeach; ?></select>
                <label for="stma_end_week">A:</label>
                <select id="stma_end_week" name="stma_end_week"><?php foreach ($week_ranges as $key => $text) : ?><option value="<?php echo esc_attr($key); ?>"><?php echo esc_html($text); ?></option><?php endforeach; ?></select>
            </div>
            <button id="stma-run-analysis" class="button button-primary button-hero"><span class="dashicons dashicons-analytics"></span> Avvia Analisi Strategica</button>
        </div>
        <div id="stma-results-container">
            <div id="stma-infographic-content" class="stma-infographic-content"></div>
            <h2><span class="dashicons dashicons-lightbulb"></span> Risultati dell'Analisi</h2>
            <div id="stma-loader" style="display:none;"><span class="spinner is-active"></span><p>Analisi in corso... L'intelligenza artificiale sta elaborando i dati. Potrebbero volerci fino a 2 minuti.</p></div>
            <div id="stma-error-container" class="notice notice-error" style="display: none;"></div>
            <div id="stma-results-content" class="stma-results-content"></div>
        </div>
    </div>

    <script type="text/javascript">
        jQuery(document).ready(function($) {
            const $resultsContainer = $('#stma-results-container'), $infographicContent = $('#stma-infographic-content'), $resultsContent = $('#stma-results-content'), $startWeekSelect = $('#stma_start_week'), $endWeekSelect = $('#stma_end_week'), $button = $('#stma-run-analysis'), $loader = $('#stma-loader'), $errorContainer = $('#stma-error-container');
            function loadSavedAnalysis() {
                const savedInfographic = localStorage.getItem('stma_last_infographic'), savedAnalysis = localStorage.getItem('stma_last_analysis'), savedStartDate = localStorage.getItem('stma_last_start_date'), savedEndDate = localStorage.getItem('stma_last_end_date');
                if (savedInfographic && savedAnalysis) {
                    $infographicContent.html(savedInfographic);
                    $resultsContent.html(savedAnalysis);
                    $resultsContainer.show();
                    if (savedStartDate) $startWeekSelect.val(savedStartDate);
                    if (savedEndDate) $endWeekSelect.val(savedEndDate);
                }
            }
            loadSavedAnalysis();
            $button.on('click', function() {
                const startWeek = $startWeekSelect.val(), endWeek = $endWeekSelect.val();
                if (!startWeek || !endWeek) { alert('Per favore, seleziona un intervallo di settimane valido.'); return; }
                localStorage.removeItem('stma_last_infographic'); localStorage.removeItem('stma_last_analysis'); localStorage.removeItem('stma_last_start_date'); localStorage.removeItem('stma_last_end_date');
                $button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt spin"></span> Analisi in corso...');
                $resultsContent.html(''); $infographicContent.html(''); $errorContainer.hide().html('');
                $resultsContainer.show(); $loader.show();
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>', type: 'POST',
                    data: { action: 'stma_get_marketing_analysis', nonce: '<?php echo wp_create_nonce('stma_marketing_advisor_nonce'); ?>', start_week: startWeek, end_week: endWeek },
                    success: function(response) {
                        if (response.success) {
                            const analysis = response.data.analysis, infographic = response.data.infographic || '';
                            $resultsContent.html(analysis).hide().fadeIn();
                            if (infographic) { $infographicContent.html(infographic).hide().fadeIn(); }
                            localStorage.setItem('stma_last_analysis', analysis); localStorage.setItem('stma_last_infographic', infographic); localStorage.setItem('stma_last_start_date', startWeek); localStorage.setItem('stma_last_end_date', endWeek);
                        } else {
                            const errorMessage = '<p><strong>Errore durante l\'analisi:</strong> ' + (response.data.message || 'Errore sconosciuto.') + '</p>';
                            $errorContainer.html(errorMessage).show();
                        }
                    },
                    error: function(jqXHR, textStatus, errorThrown) {
                        let errorDetails = textStatus;
                        if(errorThrown) errorDetails += ' - ' + errorThrown;
                        // Spesso jqXHR.responseText contiene l'errore PHP effettivo
                        if(jqXHR.responseText) errorDetails += '<br><pre style="white-space: pre-wrap; word-wrap: break-word;">' + jqXHR.responseText.substring(0, 500) + '</pre>';
                        const errorMessage = '<p><strong>Errore di comunicazione (AJAX):</strong> ' + errorDetails + '</p>';
                        $errorContainer.html(errorMessage).show();
                    },
                    complete: function() {
                        $loader.hide();
                        $button.prop('disabled', false).html('<span class="dashicons dashicons-analytics"></span> Avvia Analisi Strategica');
                    }
                });
            });
        });
    </script>
    <?php
}


/**
 * Funzione handler per la chiamata AJAX che esegue l'analisi di marketing.
 */
function stma_get_marketing_analysis_ajax() {
    set_time_limit(300);
    check_ajax_referer('stma_marketing_advisor_nonce', 'nonce');


    // =========================================================================
    // ==> CORREZIONE 2: Controlla se la funzione per prendere i dati esiste prima di chiamarla.
    if (!function_exists('wp_trello_get_marketing_data_with_leads_costs_v19')) {
        wp_send_json_error(['message' => 'Funzione dipendente `wp_trello_get_marketing_data_with_leads_costs_v19` non trovata. Assicurati che il plugin principale sia attivo e aggiornato.']);
        return;
    }
    // =========================================================================

    $start_week_key = isset($_POST['start_week']) ? sanitize_text_field($_POST['start_week']) : null;
    $end_week_key = isset($_POST['end_week']) ? sanitize_text_field($_POST['end_week']) : null;
    if (!$start_week_key || !$end_week_key) { wp_send_json_error(['message' => 'Intervallo non valido.']); return; }
    try {
        $start_date = new DateTime(explode('_', $start_week_key)[0]);
        $end_date = new DateTime(explode('_', $end_week_key)[1]);
        $end_date->setTime(23, 59, 59);
    } catch (Exception $e) { wp_send_json_error(['message' => 'Formato data non valido.']); return; }

    $interval = $start_date->diff($end_date);
    $days_in_period = $interval->days + 1;
    $weeks_in_period = ceil($days_in_period / 7);
    if ($weeks_in_period < 1) $weeks_in_period = 1;

    $all_marketing_data = wp_trello_get_marketing_data_with_leads_costs_v19();
    $marketing_costs = [];
    foreach ($all_marketing_data as $week_data) {
        if (isset($week_data['start_date_obj']) && $week_data['start_date_obj'] >= $start_date && $week_data['start_date_obj'] <= $end_date) {
            foreach ($week_data['costs'] as $p => $c) { $marketing_costs[$p] = ($marketing_costs[$p] ?? 0) + $c; }
        }
    }

    $predictions = stma_get_predictions_for_period($start_date, $end_date);
    if (is_wp_error($predictions)) {
        wp_send_json_error(['message' => $predictions->get_error_message()]);
        return;
    }

    $card_ids_with_predictions = array_keys($predictions);

    $trello_details = stma_get_trello_details_for_cards($card_ids_with_predictions);

    $provenance_to_internal_platform_map = ['iol' => 'italiaonline', 'chiamate' => 'chiamate', 'Organic Search' => 'organico', 'taormina' => 'taormina', 'fb' => 'facebook', 'Google Ads' => 'google ads', 'Subito' => 'subito', 'poolindustriale.it' => 'pool industriale', 'archiexpo' => 'archiexpo', 'Bakeka' => 'bakeka', 'Europage' => 'europage'];
    $aggregated_data = [];

    $all_platforms = array_unique(array_merge(array_values($provenance_to_internal_platform_map), array_keys($marketing_costs)));
    foreach ($all_platforms as $internal_platform) {
        $aggregated_data[$internal_platform] = [ 'costo_totale' => $marketing_costs[$internal_platform] ?? 0, 'numero_lead' => 0, 'somma_probabilita' => 0.0, 'lead_con_previsione' => 0 ];
    }

    foreach ($predictions as $card_id => $probability) {
        if (!isset($trello_details[$card_id])) continue;
        $provenance = $trello_details[$card_id]['provenance'];
        $internal_platform = $provenance_to_internal_platform_map[$provenance] ?? null;
        if ($internal_platform) {
            if (!isset($aggregated_data[$internal_platform])) {
                 $aggregated_data[$internal_platform] = ['costo_totale' => 0, 'numero_lead'  => 0, 'somma_probabilita' => 0.0, 'lead_con_previsione' => 0];
            }
            $aggregated_data[$internal_platform]['numero_lead']++;
            $aggregated_data[$internal_platform]['somma_probabilita'] += $probability;
            $aggregated_data[$internal_platform]['lead_con_previsione']++;
        }
    }

    $total_cost_incurred = array_sum(array_column($aggregated_data, 'costo_totale'));
    $average_weekly_budget = round($total_cost_incurred / $weeks_in_period, 2);

    $summary_for_ai = [];
    foreach ($aggregated_data as $platform => $data) {
        $display_name = array_search($platform, $provenance_to_internal_platform_map) ?: ucfirst($platform);
        if ($data['costo_totale'] == 0 && $data['numero_lead'] == 0) continue;
        $cpl = ($data['numero_lead'] > 0) ? round($data['costo_totale'] / $data['numero_lead'], 2) : 'N/A';
        $avg_quality = ($data['lead_con_previsione'] > 0) ? round(($data['somma_probabilita'] / $data['lead_con_previsione']) * 100, 2) : 'N/A';
        $summary_for_ai[$display_name] = ['costo_canale' => $data['costo_totale'], 'costo_per_lead' => $cpl, 'qualita_media_lead_percentuale' => $avg_quality, 'numero_lead_generati' => $data['numero_lead']];
    }

    $prompt = "Sei un consulente di marketing strategico per un'azienda italiana. Nel periodo selezionato, non ci sono dati di spesa o lead generati. Fornisci raccomandazioni strategiche su come iniziare a investire un budget ipotetico (es. 1000€) per raccogliere dati utili. Rispondi in italiano, con formato HTML (`<h3>`, `<p>`, `<ul>`, `<li>`, `<strong>`).";
    if (!empty($summary_for_ai)) {
        $json_summary_for_ai = json_encode($summary_for_ai, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
// --- PROMPT FINALE CON FORMATTAZIONE AVANZATA ---
$prompt = <<<PROMPT
Sei un Senior Marketing Strategist per un'agenzia di consulenza d'elite. Il tuo cliente è un'azienda B2B italiana. Devi produrre un'analisi chiara, professionale e orientata all'azione.

**Regole Fondamentali:**
1.  **La Qualità Vince:** La metrica `qualita_media_lead_percentuale` è il fattore decisionale primario. Un canale con lead di alta qualità (>40%) merita investimento, anche se il CPL è alto. Canali con qualità molto bassa (<25%) sono da tagliare o ridurre drasticamente.
2.  **Giustificazioni Basate sui Dati:** Ogni raccomandazione deve essere supportata da dati numerici specifici (CPL, qualità %).
3.  **Formato HTML Professionale:** Usa le classi UIkit fornite per formattare la tua risposta. Sii analitico e dettagliato.

**Dati da Analizzare:**
- **Periodo:** {$days_in_period} giorni (~{$weeks_in_period} settimane).
- **Budget Medio Settimanale Speso:** {$average_weekly_budget} EUR.
- **Performance Canali (dati aggregati):**
{$json_summary_for_ai}

**FORMATO DI OUTPUT (HTML OBBLIGATORIO):**

<div class="uk-card uk-card-default uk-card-body uk-margin-bottom">
    <h3 class="uk-card-title">Executive Summary</h3>
    <p>Scrivi qui un riassunto di 2-3 frasi con le conclusioni principali. Evidenzia il canale migliore e quello peggiore.</p>
</div>

<div class="uk-card uk-card-default uk-card-body uk-margin-bottom">
    <h3 class="uk-card-title">Analisi Dettagliata per Canale</h3>
    <ul uk-accordion>
        <li>
            <a class="uk-accordion-title" href="#">Nome Canale 1</a>
            <div class="uk-accordion-content">
                <p>Scrivi un'analisi dettagliata per questo canale. Includi CPL, numero di lead e soprattutto la qualità media in percentuale. Spiega perché il budget andrebbe aumentato, diminuito o mantenuto.</p>
                <ul>
                    <li><strong>Punto di Forza:</strong> (Es: Qualità lead eccellente)</li>
                    <li><strong>Punto debole:</strong> (Es: Costo per lead molto alto)</li>
                    <li><strong>Raccomandazione:</strong> (Es: Aumentare budget)</li>
                </ul>
            </div>
        </li>
        <li>
            <a class="uk-accordion-title" href="#">Nome Canale 2</a>
            <div class="uk-accordion-content">
                <p>...</p>
            </div>
        </li>
        </ul>
</div>

<div class="uk-card uk-card-default uk-card-body">
    <h3 class="uk-card-title">Proposta di Allocazione Budget Settimanale</h3>
    <p>Basandosi sull'analisi, ecco una proposta di riallocazione del budget medio settimanale di circa <strong>{$average_weekly_budget} EUR</strong> per massimizzare il ritorno sull'investimento puntando sulla qualità dei lead.</p>
    <table class="uk-table uk-table-striped uk-table-hover">
        <thead>
            <tr>
                <th>Canale</th>
                <th class="uk-text-right">Budget Settimanale Consigliato (€)</th>
                <th>Razionale</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>Nome Canale 1</td>
                <td class="uk-text-right">NUOVO BUDGET</td>
                <td>(Es: Aumento per alta qualità)</td>
            </tr>
             <tr>
                <td>Nome Canale 2</td>
                <td class="uk-text-right">NUOVO BUDGET</td>
                <td>(Es: Riduzione drastica per bassa qualità)</td>
            </tr>
            </tbody>
        <tfoot>
            <tr class="uk-text-bold">
                <td>Totale</td>
                <td class="uk-text-right">SOMMA DEI BUDGET</td>
                <td></td>
            </tr>
        </tfoot>
    </table>
</div>
PROMPT; // Ho abbreviato per leggibilità
    }

    $ai_response_text = stma_call_openai_api($prompt, $openai_api_key);
    $infographic_html = '';
    if (!empty($summary_for_ai)) {
        $infographic_html = stma_generate_infographic_with_openai($summary_for_ai);
    }

    if (is_wp_error($ai_response_text)) {
        wp_send_json_error(['message' => 'Chiamata a OpenAI fallita: ' . $ai_response_text->get_error_message()]);
    } else {
        // Salva il risultato nella nuova tabella del database
        global $wpdb;
        $table_name = STMA_RESULTS_TABLE;
        $data_to_save = [
            'results_json' => json_encode([
                'analysis' => $ai_response_text,
                'infographic' => $infographic_html
            ])
        ];
        $wpdb->insert($table_name, $data_to_save, ['%s']);

        wp_send_json_success(['analysis' => $ai_response_text, 'infographic' => $infographic_html]);
    }
}
add_action('wp_ajax_stma_get_marketing_analysis', 'stma_get_marketing_analysis_ajax');
