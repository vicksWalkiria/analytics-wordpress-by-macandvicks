<?php
/*
Plugin Name: Analytics Tracker Plugin
Description: Registra visitas y detalles en la base de datos desde el pie de página.
Version: 1.0
Author: Tu Nombre
*/

if (!defined('ABSPATH')) exit; // Salir si se accede directamente

global $analytics_db_version;
$analytics_db_version = '1.0';

// Función para crear tablas al activar el plugin
function analytics_tracker_install() {
    global $wpdb, $analytics_db_version;

    // Prefijo de tabla de WordPress
    $charset_collate = $wpdb->get_charset_collate();
    $analytics_table = "{$wpdb->prefix}analytics";
    $social_networks_table = "{$wpdb->prefix}social_networks";

    // SQL para crear la tabla analytics
    $sql_analytics = "CREATE TABLE $analytics_table (
        id INT(11) NOT NULL AUTO_INCREMENT,
        url_visited VARCHAR(255) NOT NULL,
        visit_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        referrer_url VARCHAR(255) DEFAULT NULL,
        id_social INT(11) DEFAULT NULL,
        bot_name VARCHAR(255) DEFAULT NULL,
        PRIMARY KEY (id)
    ) $charset_collate;";

    // SQL para crear la tabla social_networks
    $sql_social_networks = "CREATE TABLE $social_networks_table (
        id_social INT(11) NOT NULL AUTO_INCREMENT,
        name VARCHAR(50) NOT NULL,
        PRIMARY KEY (id_social)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_analytics);
    dbDelta($sql_social_networks);

    // Insertar datos iniciales en la tabla social_networks si está vacía
    $social_networks = ['Facebook', 'Twitter', 'Instagram', 'Google', 'LinkedIn', 'Pinterest'];
    foreach ($social_networks as $network) {
        $wpdb->insert(
            $social_networks_table,
            ['name' => $network],
            ['%s']
        );
    }

    // Guardar la versión de la base de datos
    add_option('analytics_db_version', $analytics_db_version);
}
register_activation_hook(__FILE__, 'analytics_tracker_install');

// Función para registrar la visita
function record_visit() {
    global $wpdb;

    $site_domain = parse_url(home_url(), PHP_URL_HOST);

    // Obtener la URL visitada
    $url_visited = $_SERVER['REQUEST_URI'];

    // Obtener la URL de referencia si está disponible
    $referrer_url = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : null;

    // Inicializar variables para la red social y la referencia interna
    $id_social = null;
    $internal_referrer_path = null;

    // Verificar si hay una URL de referencia
    if ($referrer_url) {
        // Extraer el dominio de la referencia
        $referrer_domain = parse_url($referrer_url, PHP_URL_HOST);

        if ($referrer_domain === $site_domain) {
            // Si es una URL interna, obtener la ruta relativa
            $internal_referrer_path = str_replace(home_url(), '', $referrer_url);
        } else {
            // Consultar las redes sociales para encontrar coincidencias
            $social_networks = $wpdb->get_results("SELECT id_social, name FROM {$wpdb->prefix}social_networks");
            foreach ($social_networks as $social) {
                if (strpos($referrer_url, strtolower($social->name)) !== false) {
                    $id_social = $social->id_social;
                    break;
                }
            }
        }
    }

    // Obtener el User-Agent y detectar bots
    $user_agent = $_SERVER['HTTP_USER_AGENT'];
    $bot_name = null;

    // Lista de bots comunes
    $bots = [
        'Googlebot' => 'Googlebot',
        'Bingbot' => 'Bingbot',
        'Slurp' => 'Yahoo! Slurp',
        'DuckDuckBot' => 'DuckDuckGo',
        'Baiduspider' => 'Baidu',
        'YandexBot' => 'Yandex',
        'Sogou' => 'Sogou',
        'Exabot' => 'Exabot',
        'facebot' => 'Facebook',
        'ia_archiver' => 'Alexa'
    ];

    foreach ($bots as $key => $name) {
        if (stripos($user_agent, $key) !== false) {
            $bot_name = $name;
            break;
        }
    }

    // Preparar los valores para la inserción
    $referrer_to_store = $internal_referrer_path ? $internal_referrer_path : $referrer_url;
    $id_social_value = $id_social ? $id_social : null;

    // Insertar los datos en la tabla analytics
    $wpdb->insert(
        "{$wpdb->prefix}analytics",
        [
            'url_visited' => $url_visited,
            'visit_date' => current_time('mysql'),
            'referrer_url' => $referrer_to_store,
            'id_social' => $id_social_value,
            'bot_name' => $bot_name
        ],
        [
            '%s', '%s', '%s', '%d', '%s'
        ]
    );
}
add_action('wp_footer', 'record_visit'); // Ejecuta la función en el pie de página
?>
