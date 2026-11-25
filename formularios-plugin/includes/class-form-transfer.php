<?php
if ( ! defined( 'ABSPATH' ) ) exit;

/**
 * Exportar e importar formularios (configuracion, campos y emails) en un
 * archivo JSON. No incluye las respuestas recibidas ni datos personales.
 */
class Formularios_Transfer {

    const FORMAT       = 1;
    const MAX_BYTES    = 2097152; // 2 MB
    const MAX_FORMS    = 100;
    const PAGE_SLUG    = 'formularios-transfer';
    const NONCE_EXPORT = 'formularios_export_forms';
    const NONCE_IMPORT = 'formularios_import_forms';

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_post_formularios_export_forms', array( $this, 'handle_export' ) );
        add_action( 'admin_post_formularios_import_forms', array( $this, 'handle_import' ) );
    }

    private static function can_transfer() {
        return current_user_can( 'manage_options' );
    }

    public function add_menu_page() {
        add_submenu_page(
            'edit.php?post_type=formulario',
            'Exportar e importar',
            'Exportar / Importar',
            'manage_options',
            self::PAGE_SLUG,
            array( $this, 'render_page' )
        );
    }

    /* ------------------------------------------------------------------ */
    /* Pantalla                                                            */
    /* ------------------------------------------------------------------ */

    public function render_page() {
        if ( ! self::can_transfer() ) wp_die( 'No tiene permisos para ver esta pagina.' );

        $forms = get_posts( array(
            'post_type'      => 'formulario',
            'post_status'    => array( 'publish', 'draft', 'pending', 'private' ),
            'posts_per_page' => -1,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        $notice = $this->read_notice();
        ?>
        <div class="wrap">
            <h1>Exportar e importar formularios</h1>

            <?php if ( $notice ) : ?>
                <div class="notice notice-<?php echo esc_attr( $notice['type'] ); ?> is-dismissible"><p><?php echo esc_html( $notice['text'] ); ?></p></div>
            <?php endif; ?>

            <h2>Exportar</h2>
            <p>Genera un archivo con los formularios elegidos (campos, ajustes y emails). <strong>No incluye las respuestas recibidas.</strong></p>
            <?php if ( empty( $forms ) ) : ?>
                <p>No hay formularios para exportar.</p>
            <?php else : ?>
                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                    <input type="hidden" name="action" value="formularios_export_forms" />
                    <?php wp_nonce_field( self::NONCE_EXPORT ); ?>
                    <table class="widefat striped" style="max-width:720px">
                        <thead><tr>
                            <td class="check-column"><input type="checkbox" id="fm-tr-all" checked
                                onclick="document.querySelectorAll('.fm-tr-item').forEach(function(c){c.checked=this.checked}.bind(this));" /></td>
                            <th>Formulario</th><th>ID</th><th>Estado</th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ( $forms as $f ) : ?>
                            <tr>
                                <th class="check-column"><input type="checkbox" class="fm-tr-item" name="form_ids[]" value="<?php echo esc_attr( $f->ID ); ?>" checked /></th>
                                <td><?php echo esc_html( $f->post_title ? $f->post_title : '(sin titulo)' ); ?></td>
                                <td><?php echo (int) $f->ID; ?></td>
                                <td><?php echo esc_html( $f->post_status ); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                    <p><button type="submit" class="button button-primary">Descargar archivo de exportacion</button></p>
                </form>
            <?php endif; ?>

            <hr />

            <h2>Importar</h2>
            <p>Suba un archivo exportado desde este plugin. Los formularios se <strong>crean como nuevos</strong>: nunca se modifican ni se reemplazan los existentes, y no se tocan las respuestas.</p>
            <form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                <input type="hidden" name="action" value="formularios_import_forms" />
                <?php wp_nonce_field( self::NONCE_IMPORT ); ?>
                <p><input type="file" name="import_file" accept=".json,application/json" required /></p>
                <p><label>
                    <input type="checkbox" name="keep_ids" value="1" />
                    Intentar conservar el ID original de cada formulario (solo si ese ID esta libre; si no, se asigna uno nuevo).
                </label></p>
                <p class="description">Conservar el ID permite que los shortcodes <code>[formulario id="..."]</code> de las paginas sigan funcionando.</p>
                <p><button type="submit" class="button button-primary">Importar</button></p>
            </form>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------ */
    /* Exportar                                                            */
    /* ------------------------------------------------------------------ */

    public function handle_export() {
        if ( ! self::can_transfer() ) wp_die( 'No tiene permisos para exportar.', '', array( 'response' => 403 ) );
        check_admin_referer( self::NONCE_EXPORT );

        $ids = isset( $_POST['form_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['form_ids'] ) ) : array();
        $ids = array_values( array_filter( array_unique( $ids ) ) );
        if ( empty( $ids ) ) {
            $this->redirect_notice( 'error', 'Seleccione al menos un formulario para exportar.' );
        }

        $payload = self::build_export( $ids );

        nocache_headers();
        header( 'Content-Type: application/json; charset=utf-8' );
        header( 'Content-Disposition: attachment; filename="formularios-' . gmdate( 'Y-m-d' ) . '.json"' );
        echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
        exit;
    }

    /**
     * Arma el contenido a exportar. Solo formularios existentes.
     */
    public static function build_export( array $ids ) {
        $forms = array();
        foreach ( $ids as $id ) {
            $post = get_post( $id );
            if ( ! $post || 'formulario' !== $post->post_type ) continue;

            $settings = get_post_meta( $id, '_formularios_settings', true );
            $elements = get_post_meta( $id, '_formularios_elements', true );
            $emails   = get_post_meta( $id, '_formularios_emails', true );

            $forms[] = array(
                'source_id' => (int) $id,
                'title'     => $post->post_title,
                'status'    => $post->post_status,
                'settings'  => is_array( $settings ) ? $settings : array(),
                'elements'  => is_array( $elements ) ? $elements : array(),
                'emails'    => is_array( $emails ) ? $emails : array(),
            );
        }

        return array(
            'plugin'      => 'formularios',
            'format'      => self::FORMAT,
            'version'     => defined( 'FORMULARIOS_VERSION' ) ? FORMULARIOS_VERSION : '',
            'exported_at' => gmdate( 'c' ),
            'forms'       => $forms,
        );
    }

    /* ------------------------------------------------------------------ */
    /* Importar                                                            */
    /* ------------------------------------------------------------------ */

    public function handle_import() {
        if ( ! self::can_transfer() ) wp_die( 'No tiene permisos para importar.', '', array( 'response' => 403 ) );
        check_admin_referer( self::NONCE_IMPORT );

        $file = $_FILES['import_file'] ?? null;
        if ( ! $file || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
            $this->redirect_notice( 'error', 'No se pudo recibir el archivo. Vuelva a intentarlo.' );
        }
        if ( (int) $file['size'] > self::MAX_BYTES ) {
            $this->redirect_notice( 'error', 'El archivo es demasiado grande (maximo 2 MB).' );
        }

        $json = file_get_contents( $file['tmp_name'] );
        $keep = ! empty( $_POST['keep_ids'] );

        $result = self::import_json( (string) $json, $keep );
        if ( is_wp_error( $result ) ) {
            $this->redirect_notice( 'error', $result->get_error_message() );
        }

        $text = sprintf( 'Se importaron %d formulario(s).', $result['created'] );
        if ( $result['skipped'] > 0 ) {
            $text .= sprintf( ' Se omitieron %d por no ser validos.', $result['skipped'] );
        }
        if ( ! empty( $result['new_ids'] ) ) {
            $pairs = array();
            foreach ( $result['new_ids'] as $old => $new ) {
                $pairs[] = $old . ' → ' . $new;
            }
            $text .= ' ID original → ID nuevo: ' . implode( ', ', $pairs ) . '.';
        }
        $this->redirect_notice( 'success', $text );
    }

    /**
     * Crea los formularios del JSON. Devuelve array(created, skipped, new_ids)
     * o WP_Error. Nunca modifica formularios existentes.
     */
    public static function import_json( $json, $keep_ids = false ) {
        $data = json_decode( $json, true );
        if ( ! is_array( $data ) || ( $data['plugin'] ?? '' ) !== 'formularios' || ! isset( $data['forms'] ) || ! is_array( $data['forms'] ) ) {
            return new WP_Error( 'formularios_import', 'El archivo no es una exportacion valida de este plugin.' );
        }
        if ( (int) ( $data['format'] ?? 0 ) !== self::FORMAT ) {
            return new WP_Error( 'formularios_import', 'La version del formato del archivo no es compatible.' );
        }
        if ( count( $data['forms'] ) > self::MAX_FORMS ) {
            return new WP_Error( 'formularios_import', 'El archivo contiene demasiados formularios.' );
        }

        $created = 0;
        $skipped = 0;
        $new_ids = array();
        $allowed_status = array( 'publish', 'draft', 'pending', 'private' );

        foreach ( $data['forms'] as $form ) {
            if ( ! is_array( $form ) || ! isset( $form['elements'] ) || ! is_array( $form['elements'] ) ) {
                $skipped++;
                continue;
            }

            $title  = sanitize_text_field( (string) ( $form['title'] ?? '' ) );
            $status = in_array( $form['status'] ?? '', $allowed_status, true ) ? $form['status'] : 'draft';

            $postarr = array(
                'post_type'   => 'formulario',
                'post_title'  => '' !== $title ? $title : 'Formulario importado',
                'post_status' => $status,
            );

            $source_id = absint( $form['source_id'] ?? 0 );
            if ( $keep_ids && $source_id && null === get_post( $source_id ) ) {
                $postarr['import_id'] = $source_id;
            }

            $post_id = wp_insert_post( wp_slash( $postarr ), true );
            if ( is_wp_error( $post_id ) || ! $post_id ) {
                $skipped++;
                continue;
            }

            $elements = Formularios_Builder::sanitize_elements( $form['elements'] );
            foreach ( $elements as &$el ) {
                // Las imagenes de la mediateca no existen en otro sitio: queda la URL.
                if ( isset( $el['image_id'] ) && $el['image_id'] && 'attachment' !== get_post_type( $el['image_id'] ) ) {
                    $el['image_id'] = 0;
                }
            }
            unset( $el );
            update_post_meta( $post_id, '_formularios_elements', $elements );

            $raw_settings = is_array( $form['settings'] ?? null ) ? $form['settings'] : array();
            // Las casillas se guardan como '1'/'0': solo '1' cuenta como marcada.
            foreach ( array( 'show_progress', 'notify_respondent', 'show_code' ) as $flag ) {
                if ( ( $raw_settings[ $flag ] ?? '0' ) !== '1' ) {
                    unset( $raw_settings[ $flag ] );
                }
            }
            update_post_meta( $post_id, '_formularios_settings', Formularios_Builder::sanitize_settings( $raw_settings ) );

            if ( ! empty( $form['emails'] ) && is_array( $form['emails'] ) ) {
                update_post_meta( $post_id, '_formularios_emails', Formularios_Emails::sanitize_config( $form['emails'] ) );
            }

            if ( $source_id ) {
                $new_ids[ $source_id ] = (int) $post_id;
            }
            $created++;
        }

        return array( 'created' => $created, 'skipped' => $skipped, 'new_ids' => $new_ids );
    }

    /* ------------------------------------------------------------------ */
    /* Avisos                                                              */
    /* ------------------------------------------------------------------ */

    private function redirect_notice( $type, $text ) {
        set_transient( 'formularios_transfer_notice_' . get_current_user_id(), array( 'type' => $type, 'text' => $text ), 120 );
        wp_safe_redirect( admin_url( 'edit.php?post_type=formulario&page=' . self::PAGE_SLUG ) );
        exit;
    }

    private function read_notice() {
        $key    = 'formularios_transfer_notice_' . get_current_user_id();
        $notice = get_transient( $key );
        if ( $notice ) delete_transient( $key );
        return is_array( $notice ) ? $notice : null;
    }
}
