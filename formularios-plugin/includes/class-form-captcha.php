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
        register_setting( 'formularios_captcha', 'formularios_captcha_enabled', 'sanitize_text_field' );
        register_setting( 'formularios_captcha', 'formularios_captcha_provider', 'sanitize_text_field' );
        register_setting( 'formularios_captcha', 'formularios_captcha_site_key', 'sanitize_text_field' );
        register_setting( 'formularios_captcha', 'formularios_captcha_secret_key', 'sanitize_text_field' );
        register_setting( 'formularios_captcha', 'formularios_turnstile_site_key', 'sanitize_text_field' );
        register_setting( 'formularios_captcha', 'formularios_turnstile_secret_key', 'sanitize_text_field' );
    }

    public function render_settings_page() {
        $enabled          = get_option( 'formularios_captcha_enabled', '0' );
        $provider         = get_option( 'formularios_captcha_provider', 'recaptcha' );
        $recaptcha_site   = get_option( 'formularios_captcha_site_key', '' );
        $recaptcha_secret = get_option( 'formularios_captcha_secret_key', '' );
        $turnstile_site   = get_option( 'formularios_turnstile_site_key', '' );
        $turnstile_secret = get_option( 'formularios_turnstile_secret_key', '' );
        ?>
        <div class="wrap">
            <h1>Configuracion de CAPTCHA</h1>
            <p style="color:#6B7280;max-width:640px;">
                Protege tus formularios contra spam. Elige entre Google reCAPTCHA v3 (invisible, basado en puntaje) o Cloudflare Turnstile (invisible o desafio minimo, sin cookies de seguimiento).
            </p>
            <form method="post" action="options.php" style="max-width:640px;">
                <?php settings_fields( 'formularios_captcha' ); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="formularios_captcha_enabled">Habilitar CAPTCHA</label></th>
                        <td>
                            <label>
                                <input type="checkbox" id="formularios_captcha_enabled" name="formularios_captcha_enabled" value="1" <?php checked( $enabled, '1' ); ?> />
                                Activar proteccion CAPTCHA en todos los formularios
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="formularios_captcha_provider">Proveedor</label></th>
                        <td>
                            <select id="formularios_captcha_provider" name="formularios_captcha_provider">
                                <option value="recaptcha" <?php selected( $provider, 'recaptcha' ); ?>>Google reCAPTCHA v3</option>
                                <option value="turnstile" <?php selected( $provider, 'turnstile' ); ?>>Cloudflare Turnstile</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <div id="fm-recaptcha-fields" style="<?php echo 'recaptcha' !== $provider ? 'display:none' : ''; ?>">
                    <h2 class="title">Google reCAPTCHA v3</h2>
                    <p style="color:#6B7280;">
                        Obtene tus claves en <a href="https://www.google.com/recaptcha/admin" target="_blank" rel="noopener">google.com/recaptcha/admin</a>.
                        Selecciona <strong>reCAPTCHA v3</strong> al crear el sitio.
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="formularios_captcha_site_key">Clave del sitio (Site Key)</label></th>
                            <td>
                                <input type="text" id="formularios_captcha_site_key" name="formularios_captcha_site_key"
                                       value="<?php echo esc_attr( $recaptcha_site ); ?>" class="regular-text" placeholder="6Lc..." />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="formularios_captcha_secret_key">Clave secreta (Secret Key)</label></th>
                            <td>
                                <input type="text" id="formularios_captcha_secret_key" name="formularios_captcha_secret_key"
                                       value="<?php echo esc_attr( $recaptcha_secret ); ?>" class="regular-text" placeholder="6Lc..." />
                            </td>
                        </tr>
                    </table>
                </div>

                <div id="fm-turnstile-fields" style="<?php echo 'turnstile' !== $provider ? 'display:none' : ''; ?>">
                    <h2 class="title">Cloudflare Turnstile</h2>
                    <p style="color:#6B7280;">
                        Obtene tus claves en <a href="https://dash.cloudflare.com/?to=/:account/turnstile" target="_blank" rel="noopener">dash.cloudflare.com &rarr; Turnstile</a>.
                        Crea un widget de tipo <strong>Invisible</strong> o <strong>Managed</strong>.
                    </p>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="formularios_turnstile_site_key">Clave del sitio (Site Key)</label></th>
                            <td>
                                <input type="text" id="formularios_turnstile_site_key" name="formularios_turnstile_site_key"
                                       value="<?php echo esc_attr( $turnstile_site ); ?>" class="regular-text" placeholder="0x4AAAA..." />
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><label for="formularios_turnstile_secret_key">Clave secreta (Secret Key)</label></th>
                            <td>
                                <input type="text" id="formularios_turnstile_secret_key" name="formularios_turnstile_secret_key"
                                       value="<?php echo esc_attr( $turnstile_secret ); ?>" class="regular-text" placeholder="0x4AAAA..." />
                            </td>
                        </tr>
                    </table>
                </div>

                <?php submit_button( 'Guardar configuracion' ); ?>
            </form>
        </div>
        <script>
        (function() {
            var select       = document.getElementById('formularios_captcha_provider');
            var recaptchaDiv = document.getElementById('fm-recaptcha-fields');
            var turnstileDiv = document.getElementById('fm-turnstile-fields');
            function toggle() {
                recaptchaDiv.style.display = (select.value === 'recaptcha') ? '' : 'none';
                turnstileDiv.style.display = (select.value === 'turnstile') ? '' : 'none';
            }
            select.addEventListener('change', toggle);
        })();
        </script>
        <?php
    }

    public static function get_provider() {
        return get_option( 'formularios_captcha_provider', 'recaptcha' );
    }

    public static function is_enabled() {
        if ( '1' !== get_option( 'formularios_captcha_enabled', '0' ) ) {
            return false;
        }
        if ( 'turnstile' === self::get_provider() ) {
            return '' !== get_option( 'formularios_turnstile_site_key', '' )
                && '' !== get_option( 'formularios_turnstile_secret_key', '' );
        }
        return '' !== get_option( 'formularios_captcha_site_key', '' )
            && '' !== get_option( 'formularios_captcha_secret_key', '' );
    }

    public static function get_site_key() {
        if ( 'turnstile' === self::get_provider() ) {
            return get_option( 'formularios_turnstile_site_key', '' );
        }
        return get_option( 'formularios_captcha_site_key', '' );
    }

    /**
     * Verify a CAPTCHA token server-side (dispatches to the active provider).
     *
     * Returns true (pass), false (fail), or null (could not verify — allow through).
     */
    public static function verify_token( $token ) {
        if ( 'turnstile' === self::get_provider() ) {
            return self::verify_turnstile( $token );
        }
        return self::verify_recaptcha( $token );
    }

    private static function verify_recaptcha( $token ) {
        $secret = get_option( 'formularios_captcha_secret_key', '' );
        if ( empty( $secret ) || empty( $token ) ) return null;

        $response = wp_remote_post( 'https://www.google.com/recaptcha/api/siteverify', array(
            'body'    => array(
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) return null;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) return null;

        if ( ! empty( $body['success'] ) ) {
            $score = $body['score'] ?? 0;
            return $score >= 0.5;
        }
        return false;
    }

    private static function verify_turnstile( $token ) {
        $secret = get_option( 'formularios_turnstile_secret_key', '' );
        if ( empty( $secret ) || empty( $token ) ) return null;

        $response = wp_remote_post( 'https://challenges.cloudflare.com/turnstile/v0/siteverify', array(
            'body'    => array(
                'secret'   => $secret,
                'response' => $token,
                'remoteip' => $_SERVER['REMOTE_ADDR'] ?? '',
            ),
            'timeout' => 10,
        ) );

        if ( is_wp_error( $response ) ) return null;

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) return null;

        return ! empty( $body['success'] );
    }
}
