<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Actualizaciones del plugin desde las versiones (Releases) de GitHub.
 *
 * - Solo afecta a este plugin; no toca ningun otro.
 * - Ofrece una actualizacion unicamente cuando la version publicada en GitHub
 *   incluye el ZIP armado por el flujo de publicacion (formularios-X.Y.Z.zip).
 *   Nunca se instala el codigo "crudo" del repositorio.
 * - Esta pensado para no romper el sitio: si la biblioteca no se puede cargar
 *   (PHP antiguo, archivo faltante, error), simplemente no se ofrecen
 *   actualizaciones y el plugin sigue funcionando igual.
 *
 * Para desactivarlo desde wp-config.php:
 *     define( 'FORMULARIOS_DISABLE_UPDATER', true );
 */
class Formularios_Updater {

    const REPO_URL    = 'https://github.com/FhorasteroAR/formularios/';
    const ASSET_REGEX = '/^formularios-[0-9][0-9A-Za-z.\-]*\.zip$/';

    public static function init() {
        if ( defined( 'FORMULARIOS_DISABLE_UPDATER' ) && FORMULARIOS_DISABLE_UPDATER ) {
            return;
        }

        // La biblioteca requiere PHP 7.4+. En servidores mas antiguos no se carga.
        if ( version_compare( PHP_VERSION, '7.4', '<' ) || ! function_exists( 'json_decode' ) ) {
            return;
        }

        // Solo donde hace falta: panel de administracion, tareas programadas y WP-CLI.
        $is_cron = function_exists( 'wp_doing_cron' ) && wp_doing_cron();
        if ( ! is_admin() && ! $is_cron && ! defined( 'WP_CLI' ) ) {
            return;
        }

        $lib = FORMULARIOS_PATH . 'vendor/plugin-update-checker/plugin-update-checker.php';
        if ( ! is_readable( $lib ) ) {
            return;
        }

        try {
            require_once $lib;

            $checker = \YahnisElsts\PluginUpdateChecker\v6\PucFactory::buildUpdateChecker(
                self::REPO_URL,
                FORMULARIOS_PATH . 'formularios.php'
            );

            // Exigir el ZIP adjunto a la version: sin el, no se ofrece actualizacion.
            $checker->getVcsApi()->enableReleaseAssets(
                self::ASSET_REGEX,
                \YahnisElsts\PluginUpdateChecker\v6p0\Vcs\Api::REQUIRE_RELEASE_ASSETS
            );
        } catch ( \Throwable $e ) {
            error_log( 'Formularios: no se pudo iniciar el actualizador — ' . $e->getMessage() );
        }
    }
}
