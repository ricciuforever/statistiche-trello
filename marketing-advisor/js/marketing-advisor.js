document.addEventListener('DOMContentLoaded', function() {
    const startButton = document.getElementById('stma-start-analysis');
    const resultsContainer = document.getElementById('stma-results-container');

    if (startButton) {
        startButton.addEventListener('click', async function() {
            startButton.disabled = true;
            resultsContainer.innerHTML = '<p>Analisi in corso, attendere prego...</p>';

            try {
                // I dati AJAX come nonce e url verranno passati da PHP
                const formData = new FormData();
                formData.append('action', 'stma_get_marketing_advice');
                formData.append('nonce', stma_js_data.nonce);

                const response = await fetch(stma_js_data.ajax_url, {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.success) {
                    let html = '<div class="updated notice is-dismissible"><p>' + result.data.analysis + '</p></div>';
                    html += '<div class="uk-card uk-card-default uk-card-body"><h3>Raccomandazioni Budget</h3>' + result.data.recommendations + '</div>';
                    resultsContainer.innerHTML = html;
                } else {
                    resultsContainer.innerHTML = '<div class="error notice is-dismissible"><p>Errore: ' + result.data.message + '</p></div>';
                }

            } catch (error) {
                resultsContainer.innerHTML = '<div class="error notice is-dismissible"><p>Errore durante la richiesta: ' + error.message + '</p></div>';
                console.error('Marketing Advisor Error:', error);
            } finally {
                startButton.disabled = false;
            }
        });
    }
});
