<?php
/**
 * File: class-stsg-google-sheets-client.php
 * Descrizione: Gestisce l'interazione con l'API di Google Sheets per il modulo Sheet Generator.
 * Richiede la libreria Google API Client per PHP.
 * Author: Emanuele Tolomei
 */

if (!defined('ABSPATH')) exit;

// Path all'autoload di Composer.
// Assicurati che Composer sia stato eseguito nella directory principale del plugin.
require_once __DIR__ . '/../vendor/autoload.php';

class STSG_Google_Sheets_Client {

    private $service;
    private $credentials_path;
    private $service_account_email;

    public function __construct() {
        // Percorso del file delle credenziali JSON.
        // Lo abbiamo messo un livello sopra per maggiore sicurezza, nella cartella wp-content.
        $this->credentials_path = WP_CONTENT_DIR . '/trello-sheet-credentials.json';

        if (!file_exists($this->credentials_path)) {
            error_log('STSG Google Sheets Client Error: Google Sheet credentials file not found at ' . $this->credentials_path);
            $this->service = new WP_Error('sheet_credentials_missing', 'Credenziali Google Sheet mancanti. Assicurati che il file `trello-sheet-credentials.json` sia in `wp-content/`.');
            return;
        }

        try {
            $credentials_content = json_decode(file_get_contents($this->credentials_path), true);
            $this->service_account_email = $credentials_content['client_email'] ?? 'N/D';

            $client = new Google_Client();
            $client->setApplicationName('Trello Statistics Sheet Generator');
            $client->setScopes([Google_Service_Sheets::SPREADSHEETS]); // Solo permesso per Sheets, non Drive a meno che non sia necessario
            $client->setAccessType('offline');
            $client->setAuthConfig($this->credentials_path);

            $this->service = new Google_Service_Sheets($client);
        } catch (Exception $e) {
            error_log('STSG Google Sheets Client Error: Failed to initialize Google Client or Service: ' . $e->getMessage());
            $this->service = new WP_Error('sheet_api_init_failed', 'Impossibile inizializzare il client API di Google Sheets: ' . $e->getMessage());
        }
    }

    /**
     * Restituisce il servizio Google Sheets inizializzato o un WP_Error.
     *
     * @return Google_Service_Sheets|WP_Error
     */
    public function getService() {
        return $this->service;
    }

    /**
     * Restituisce l'email dell'account di servizio.
     *
     * @return string
     */
    public function getServiceAccountEmail() {
        return $this->service_account_email;
    }
}