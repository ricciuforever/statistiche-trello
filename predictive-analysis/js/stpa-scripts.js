document.addEventListener('DOMContentLoaded', function() {
    const statusContainer = document.getElementById('stpa-status-container');
    const controlsContainer = document.getElementById('stpa-controls');
    const resultsContainer = document.getElementById('stpa-results-container');
    const tabNav = document.querySelector('.stpa-tab-nav');
    const tabContent = document.getElementById('stpa-tab-content');

    // stpa_data is localized via wp_localize_script
    const thresholds = stpa_data.thresholds;
    const periods = stpa_data.periods;
    let statusInterval = null;

    let lastAnalysisId = stpa_data.last_analysis_status.analysis_id || '';
    let currentResults = {}; // Variabile per memorizzare i risultati correnti

    function renderControls(status) {
        let html = '';
        if (status.status === 'idle' || status.status === 'completed' || status.status === 'stalled' || status.status === 'completed_with_errors') {
            html = `
                <div class="stpa-control-group">
                    <label>Schede per Batch:</label>
                    <input type="text" id="stpa-batch-size-display" value="100 (Fisso)" disabled class="uk-input">
                    <input type="hidden" id="stpa-batch-size" value="100">
                </div>
                <div class="stpa-control-group">
                    <label>Limite Schede (0=tutte):</label>
                    <input type="number" id="stpa-max-cards" value="3000" min="0" step="100">
                </div>
                <button id="stpa-start-btn" class="stpa-button-primary">🚀 Avvia Nuova Analisi</button>
            `;
            if(status.status === 'completed' || status.status === 'stalled' || status.status === 'completed_with_errors') {
                html += `<button id="stpa-clear-btn" class="stpa-button-secondary">🗑️ Pulisci Risultati</button>`;
            }
        } else if (status.status === 'running') {
            html = `
                <p>Un\'analisi è in corso. Puoi interromperla qui:</p>
                <button id="stpa-stop-btn" class="stpa-button-secondary">⏹️ Ferma e Pulisci</button>
            `;
        }
        controlsContainer.innerHTML = html;
    }

    function renderStatus(status) {
        let html = '';
        let statusClass = `stpa-status-${status.status || 'idle'}`;
        statusContainer.className = `stpa-status-container ${statusClass}`;

        switch (status.status) {
            case 'running':
                const progress = status.total_chunks > 0 ? (status.processed_chunks / status.total_chunks) * 100 : 0;
                html = `
                    <h4>Analisi in Corso...</h4>
                    <p>Elaborazione di <strong>${status.total_cards}</strong> schede. Il processo continuerà in background anche se chiudi la pagina.</p>
                    <p>Stato: ${status.processed_chunks} / ${status.total_chunks} batch elaborati (${status.processed_cards} schede).</p>
                    <div class="stpa-progress-bar"><div class="stpa-progress-fill" style="width:${progress.toFixed(1)}%;"></div></div>
                `;
                break;
            case 'completed':
                html = `<h4>✅ Analisi Completata</h4><p>Ultima analisi terminata il ${new Date(status.finished_at).toLocaleString('it-IT')}. Sono state elaborate ${status.processed_cards} schede. I risultati sono mostrati di seguito.</p>`;
                break;
            case 'completed_with_errors':
                html = `<h4>⚠️ Analisi Terminata con Errori</h4><p>Il processo è terminato ma non ha prodotto risultati. Controlla la tua <strong>chiave API di OpenAI (ChatGPT)</strong> in <code>wp-config.php</code> o i permessi del tuo account OpenAI (ChatGPT). Per dettagli, verifica il file <code>/wp-content/debug.log</code> sul server.</p>`;
                break;
            case 'stalled':
                html = `<h4>❌ Analisi Bloccata</h4><p>L'ultimo processo sembra bloccato. Puoi ripulire i risultati e avviarne uno nuovo.</p>`;
                break;
            default:
                html = `<h4>Nessuna Analisi in Corso</h4><p>Pronto per avviare una nuova analisi in background.</p>`;
        }
        statusContainer.innerHTML = html;
    }

    async function checkStatus() {
        const response = await fetch(stpa_data.ajax_url, {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: new URLSearchParams({action: 'stpa_predictive_analysis', action_type: 'get_background_status', nonce: stpa_data.nonce})
        });
        const status = await response.json();

        if (status.success) {
            renderStatus(status.data);
            renderControls(status.data);
            lastAnalysisId = status.data.analysis_id || lastAnalysisId;

            if (JSON.stringify(currentResults) !== JSON.stringify(status.data.results)) {
                currentResults = status.data.results;
                updateAllTabStats(currentResults);
                const activeTab = tabNav.querySelector('.stpa-tab-button.active');
                if (activeTab) {
                    handleTabClick(parseInt(activeTab.dataset.period), false);
                } else {
                    if (periods.length > 0) {
                        handleTabClick(periods[0], false);
                    }
                }
            }

            if (status.data.status !== 'running') {
                clearInterval(statusInterval);
                statusInterval = null;
            }
        }
    }

    document.addEventListener('click', async (e) => {
        if (e.target.id === 'stpa-start-btn') {
            e.target.disabled = true;
            e.target.textContent = 'Avvio...';

            const maxCardsForAnalysis = document.getElementById('stpa-max-cards').value;
            if (maxCardsForAnalysis <= 0) {
                alert('Il limite di schede per l\'analisi deve essere maggiore di zero.');
                e.target.disabled = false;
                e.target.textContent = '🚀 Avvia Nuova Analisi';
                return;
            }

            const formData = new FormData();
            formData.append('action', 'stpa_predictive_analysis');
            formData.append('action_type', 'start_background_analysis');
            formData.append('nonce', stpa_data.nonce);
            formData.append('max_cards_for_analysis', maxCardsForAnalysis);

            const response = await fetch(stpa_data.ajax_url, { method: 'POST', body: formData });
            const result = await response.json();
            if(result.success) {
                lastAnalysisId = result.data.analysis_id;
                statusInterval = setInterval(checkStatus, 15000); // MODIFICATO: 5s -> 15s
                checkStatus();
            } else {
                alert('Errore: ' + result.data);
                e.target.disabled = false;
                e.target.textContent = '🚀 Avvia Nuova Analisi';
            }
        }
        if (e.target.id === 'stpa-clear-btn') {
            if (!confirm('Sei sicuro di voler cancellare i risultati dell\'ultima analisi? Questo interromperà anche qualsiasi analisi in corso.')) return;
            const response = await fetch(stpa_data.ajax_url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({action: 'stpa_predictive_analysis', action_type: 'clear_previous_analysis', nonce: stpa_data.nonce, analysis_id: lastAnalysisId})
            });
            const result = await response.json();
            if(result.success) location.reload();
        } else if (e.target.id === 'stpa-stop-btn') {
            if (!confirm('Sei sicuro di voler interrompere l\'analisi in corso? I risultati parziali verranno salvati.')) return;
            const response = await fetch(stpa_data.ajax_url, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: new URLSearchParams({
                    action: 'stpa_predictive_analysis',
                    action_type: 'clear_previous_analysis',
                    nonce: stpa_data.nonce,
                    analysis_id: lastAnalysisId
                })
            });
            const result = await response.json();
            if (result.success) {
                alert('Analisi interrotta con successo!');
                location.reload();
            } else {
                alert('Errore durante l\'interruzione dell\'analisi: ' + result.data);
            }
        }
        if (e.target.closest('.stpa-tab-button')) {
            const button = e.target.closest('.stpa-tab-button');
            handleTabClick(parseInt(button.dataset.period));
        }
    });

    function handleTabClick(period, updateOnly = true) {
        tabNav.querySelectorAll('.stpa-tab-button').forEach(btn => btn.classList.remove('active'));
        const button = tabNav.querySelector(`.stpa-tab-button[data-period="${period}"]`);
        if (button) {
            button.classList.add('active');
        }
        renderTabContent(period);
    }

    function renderTabContent(period) {
        const results = currentResults[period] || [];
        const stats = calculateStats(results);
        const tabContentEl = document.getElementById('stpa-tab-content');

        let tableRowsHtml = '';
        if (results.length === 0) {
            tableRowsHtml = `<tr><td colspan="4" style="text-align:center; padding: 20px;"><em>Nessun risultato da mostrare per questo periodo. Avvia una nuova analisi o attendi i progressi.</em></td></tr>`;
        } else {
            tableRowsHtml = results.map((r, i) => `
                <tr>
                    <td>${i + 1}</td>
                    <td><a href="${r.card_url}" target="_blank" class="stpa-card-name">${r.card_name || 'Nome non disponibile'}</a></td>
                    <td class="stpa-probability ${getProbabilityClass(r.probability)}">${(parseFloat(r.probability) * 100).toFixed(1)}%</td>
                    <td><em>${r.reasoning || 'N/D'}</em></td>
                </tr>`).join('');
        }

        tabContentEl.innerHTML = `
            <div class="stpa-stats-summary">
                <div class="stpa-stat-card high"><h4>Alta Prob.</h4><div class="stpa-stat-number">${stats.high}</div></div>
                <div class="stpa-stat-card medium"><h4>Media Prob.</h4><div class="stpa-stat-number">${stats.medium}</div></div>
                <div class="stpa-stat-card low"><h4>Bassa Prob.</h4><div class="stpa-stat-number">${stats.low}</div></div>
            </div>
            <h4>Top ${results.length} Schede con Maggiore Probabilità</h4>
            <div class="stpa-results-table">
                <table>
                    <thead><tr><th>Pos.</th><th>Nome Scheda</th><th>Probabilità</th><th>Motivazione Analisi</th></tr></thead>
                    <tbody>
                        ${tableRowsHtml}
                    </tbody>
                </table>
            </div>`;
    }

    function calculateStats(results) {
        if (!results) return { total: 0, high: 0, medium: 0, low: 0 };
        const stats = { total: results.length, high: 0, medium: 0, low: 0 };
        results.forEach(r => {
            if (r.probability > thresholds.high) stats.high++;
            else if (r.probability >= thresholds.medium) stats.medium++;
            else stats.low++;
        });
        return stats;
    }

    function getProbabilityClass(prob) {
        if (prob > thresholds.high) return 'high';
        if (prob >= thresholds.medium) return 'medium';
        return 'low';
    }

    function updateAllTabStats(results) {
        for (const period of periods) {
            const periodResults = results[period] || [];
            const stats = calculateStats(periodResults);
            const el = document.getElementById(`stpa-tab-stats-${period}`);
            if(el) el.textContent = `${stats.high} alta, ${stats.total} tot.`;
        }
    }

    checkStatus();

    if (stpa_data.last_analysis_status.status === 'running') {
        statusInterval = setInterval(checkStatus, 15000); // MODIFICATO: 5s -> 15s
    }
});
