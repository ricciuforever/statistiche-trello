<?php
/**
 * Plugin Name: Trello Marketing Advisor
 * Description: Un modulo per analizzare le performance dei canali di marketing e suggerire ottimizzazioni di budget tramite IA.
 * Version: 1.0
 */

if (!defined('ABSPATH')) exit;

// Include altri file necessari
require_once plugin_dir_path(__FILE__) . 'marketing-advisor-ui.php';
require_once plugin_dir_path(__FILE__) . 'marketing-advisor-ajax.php';

/**
 * Aggiunge la pagina del sottomenù al menu principale del plugin.
 */
function stma_add_submenu_page() {
    add_submenu_page(
        'statistiche-trello', // Slug del menu genitore
        'Marketing Advisor',       // Titolo della pagina
        'Marketing Advisor',       // Titolo del menu
        'manage_options',          // Capability richiesta
        'stma-marketing-advisor',  // Slug del menu
        'stma_render_advisor_page' // Funzione che renderizza il contenuto della pagina
    );
}
add_action('admin_menu', 'stma_add_submenu_page');

/**
 * Carica gli script e gli stili solo per la pagina del Marketing Advisor.
 */
function stma_enqueue_assets($hook) {
    // L'hook per una pagina di sottomenù è '{parent_slug}_page_{sub_slug}'
    if ($hook != 'wp-trello-plugin_page_stma-marketing-advisor') {
        return;
    }

    $plugin_url = plugin_dir_url(__FILE__);

    wp_enqueue_style(
        'stma-styles',
        $plugin_url . 'css/marketing-advisor.css',
        [],
        '1.0.0'
    );

    wp_enqueue_script(
        'stma-scripts',
        $plugin_url . 'js/marketing-advisor.js',
        [],
        '1.0.0',
        true
    );

    // Passa i dati al JavaScript in modo sicuro
    $data_for_js = [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('stma_nonce'),
    ];
    wp_add_inline_script('stma-scripts', 'const stma_js_data = ' . json_encode($data_for_js) . ';', 'before');
}
add_action('admin_enqueue_scripts', 'stma_enqueue_assets');
