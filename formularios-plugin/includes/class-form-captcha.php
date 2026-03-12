<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Formularios_Captcha {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
    }

    public function add_settings_page() {
        add_submenu_page(
            'edit.php?post_type=formulario',
            'Configuracion de Captcha',
            'Captcha',
            'manage_options',
            'formularios-captcha',
            array( $this, 'render_settings_page' )
        );
    }

    public function register_settings() {
        register_setting( 'formularios_captcha', 'formularios_captcha_site_key', 'sanitize_text_field' );
        register_setting( 'formularios_captcha', 'formularios_captcha_secret_key', 'sanitize_text_field' );
        register_setting( 'formularios_captcha', 'formularios_captcha_enabled', 'sanitize_text_field' );
    }

    public function render_settings_page() {
        $site_key   = get_option( 'formularios_captcha_site_key', '' );
        $secret_key = get_option( 'formularios_captcha_secret_key', '' );
        $enabled    = get_option( 'formularios_captcha_enabled', '0' );
        ?>
        <div class="wrap">
            <h1>Configuracion de reCAPTCHA</h1>
            <p style="color:#6B7280;max-width:600px;">
                Protege tus formularios contra spam usando Google reCAPTCHA v3.
                Obtene tus claves en <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">google.com/recaptcha/admin</a>.
                Selecciona <strong>reCAPTCHA v3</strong> al crear el sitio.
            </p>
            <form method="post" action="options.php" style="max-width:600px;">
                <?php settings_fields( 'formularios_captcha' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="formularios_captcha_enabled">Habilitar reCAPTCHA</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="formularios_captcha_enabled" name="formularios_captcha_enabled" value="1" <?php checked( $enabled, '1' ); ?> />
                                Activar proteccion reCAPTCHA en todos los formularios
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="formularios_captcha_site_key">Clave del sitio (Site Key)</label></th>
                        <td>
                            <input type="text" id="formularios_captcha_site_key" name="formularios_captcha_site_key"
                                   value="<?php echo esc_attr( $site_key ); ?>" class="regular-text"
                                   placeholder="6Lc..." />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="formularios_captcha_secret_key">Clave secreta (Secret Key)</label></th>
                        <td>
                            <input type="text" id="formularios_captcha_secret_key" name="formularios_captcha_secret_key"
                                   value="<?php echo esc_attr( $secret_key ); ?>" class="regular-text"
                                   placeholder="6Lc..." />
                        </td>
                    </tr>
                </table>
                <?php submit_button( 'Guardar configuracion' ); ?>
            </form>
        </div>
        <?php
    }

    /**
     * Check if captcha is configured and enabled.
     */
    public static function is_enabled() {
        return '1' === get_option( 'formularios_captcha_enabled', '0' )
            && '' !== get_option( 'formularios_captcha_site_key', '' )
            && '' !== get_option( 'formularios_captcha_secret_key', '' );
    }

    /**
     * Get the site key.
     */
    public static function get_site_key() {
        return get_option( 'formularios_captcha_site_key', '' );
    }

    /**
     * Verify a reCAPTCHA token server-side.
     *
     * @param string $token The g-recaptcha-response token.
     * @return bool Whether the token is valid.
     */
    public static function verify_token( $token ) {
        if ( empty( $token ) ) return false;

        $secret = get_option( 'formularios_captcha_secret_key', '' );
        if ( empty( $secret ) ) return false;

        $response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
            'body' => array(
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) {
            return false;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        // reCAPTCHA v3 returns a score (0.0-1.0). Threshold at 0.5.
        if ( ! empty( $body['success'] ) ) {
            $score = $body['score'] ?? 0;
            return $score >= 0.5;
        }

        return false;
    }
}
