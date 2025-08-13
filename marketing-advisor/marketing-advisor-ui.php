<?php
/**
 * File per il rendering dell'interfaccia utente del Marketing Advisor.
 */

if (!defined('ABSPATH')) exit;

/**
 * Renderizza il contenuto della pagina del Marketing Advisor.
 */
function stma_render_advisor_page() {
    ?>
    <div class="wrap">
        <h1>🤖 Marketing Advisor AI</h1>
        <p>Questo strumento analizza le performance dei tuoi canali di marketing degli ultimi 7 giorni, combinando i dati di costo per lead con le previsioni di chiusura, per suggerire ottimizzazioni di budget.</p>

        <div id="stma-controls">
            <button id="stma-start-analysis" class="button button-primary">Avvia Analisi</button>
        </div>

        <div id="stma-results-container" style="margin-top: 20px;">
            <!-- I risultati dell'analisi verranno mostrati qui -->
        </div>
    </div>
    <?php
}
