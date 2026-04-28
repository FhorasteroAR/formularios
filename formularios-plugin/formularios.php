<?php
/**
 * Plugin Name: Formularios
 * Description: Crea y muestra formularios modernos usando shortcodes. Construye formularios con preguntas, titulos, imagenes, videos y secciones de multiples pasos.
 * Version: 1.2.4
 * Author: Formularios Team
 * Text Domain: formularios
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FORMULARIOS_VERSION', '1.2.4' );
define( 'FORMULARIOS_PATH', plugin_dir_path( __FILE__ ) );
define( 'FORMULARIOS_URL', plugin_dir_url( __FILE__ ) );

require_once FORMULARIOS_PATH . 'includes/class-form-cpt.php';
require_once FORMULARIOS_PATH . 'includes/class-form-builder.php';
require_once FORMULARIOS_PATH . 'includes/class-form-renderer.php';
require_once FORMULARIOS_PATH . 'includes/class-form-submissions.php';
require_once FORMULARIOS_PATH . 'includes/class-form-submissions-page.php';
require_once FORMULARIOS_PATH . 'includes/class-form-emails.php';
require_once FORMULARIOS_PATH . 'includes/class-form-captcha.php';
require_once FORMULARIOS_PATH . 'includes/class-form-dashboard.php';

final class Formularios {

    private static $instance = null;

    public static function instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        new Formularios_CPT();
        new Formularios_Builder();
        new Formularios_Renderer();
        new Formularios_Submissions();
        new Formularios_Submissions_Page();
        new Formularios_Emails();
        new Formularios_Captcha();
        new Formularios_Dashboard();

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
        add_action( 'init', array( $this, 'maybe_upgrade' ) );
    }

    public function activate() {
        Formularios_Submissions::create_table();
        flush_rewrite_rules();
    }

    /**
     * Check if the DB schema needs to be created or upgraded.
     * This handles the case where the plugin is updated without
     * re-activation (the activation hook does not fire on updates).
     */
    public function maybe_upgrade() {
        $db_version = get_option( 'formularios_db_version', '0' );
        if ( version_compare( $db_version, FORMULARIOS_VERSION, '<' ) ) {
            Formularios_Submissions::create_table();
        }
    }
}

Formularios::instance();
