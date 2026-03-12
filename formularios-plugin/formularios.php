<?php
/**
 * Plugin Name: Formularios
 * Description: Crea y muestra formularios modernos usando shortcodes. Construye formularios con preguntas, titulos, imagenes, videos y secciones de multiples pasos.
 * Version: 1.1.0
 * Author: Formularios Team
 * Text Domain: formularios
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'FORMULARIOS_VERSION', '1.2.0' );
define( 'FORMULARIOS_PATH', plugin_dir_path( __FILE__ ) );
define( 'FORMULARIOS_URL', plugin_dir_url( __FILE__ ) );

require_once FORMULARIOS_PATH . 'includes/class-form-cpt.php';
require_once FORMULARIOS_PATH . 'includes/class-form-builder.php';
require_once FORMULARIOS_PATH . 'includes/class-form-renderer.php';
require_once FORMULARIOS_PATH . 'includes/class-form-submissions.php';
require_once FORMULARIOS_PATH . 'includes/class-form-submissions-page.php';
require_once FORMULARIOS_PATH . 'includes/class-form-emails.php';
require_once FORMULARIOS_PATH . 'includes/class-form-captcha.php';

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

        register_activation_hook( __FILE__, array( $this, 'activate' ) );
    }

    public function activate() {
        Formularios_Submissions::create_table();
        flush_rewrite_rules();
    }
}

Formularios::instance();
