<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Formularios_Builder {

    public function __construct() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post_formulario', array( $this, 'save_form_data' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_formularios_search_youtube', array( $this, 'search_youtube' ) );
    }

    public function enqueue_assets( $hook ) {
        global $post_type;
        if ( 'formulario' !== $post_type ) return;
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) return;

        wp_enqueue_media();

        wp_enqueue_style(
            'formularios-admin',
            FORMULARIOS_URL . 'admin/css/builder.css',
            array(),
            FORMULARIOS_VERSION
        );

        wp_enqueue_script(
            'formularios-admin',
            FORMULARIOS_URL . 'admin/js/builder.js',
            array( 'jquery', 'jquery-ui-sortable' ),
            FORMULARIOS_VERSION,
            true
        );

        // Build sections list from current post for conditional logic
        $sections = array();
        if ( isset( $_GET['post'] ) ) {
            $elements = get_post_meta( absint( $_GET['post'] ), '_formularios_elements', true );
            if ( is_array( $elements ) ) {
                foreach ( $elements as $el ) {
                    if ( 'section' === $el['type'] ) {
                        $sections[] = array(
                            'id'    => $el['id'],
                            'title' => $el['title'],
                        );
                    }
                }
            }
        }

        wp_localize_script( 'formularios-admin', 'formularios', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'formularios_nonce' ),
            'sections' => $sections,
            'i18n'     => array(
                'question'        => 'Pregunta',
                'title_desc'      => 'Titulo y descripcion',
                'image'           => 'Imagen',
                'video'           => 'Video',
                'section'         => 'Seccion',
                'select_image'    => 'Seleccionar imagen',
                'remove'          => 'Eliminar',
                'duplicate'       => 'Duplicar',
                'required'        => 'Obligatorio',
                'placeholder'     => 'Escribe tu pregunta...',
                'title_ph'        => 'Escribe el titulo...',
                'desc_ph'         => 'Escribe la descripcion...',
                'section_ph'      => 'Titulo de la seccion...',
                'youtube_search'  => 'Buscar en YouTube o pegar URL...',
                'option_ph'       => 'Opcion',
                'add_option'      => 'Agregar opcion',
                'go_to_section'   => 'Ir a seccion',
                'next_section'    => 'Siguiente seccion',
                'end_form'        => 'Enviar formulario',
                'no_sections'     => 'Agrega secciones al formulario para habilitar la logica condicional',
                'branching'       => 'Logica condicional',
                'image_caption'   => 'Descripcion de la imagen (opcional)',
                'video_caption'   => 'Descripcion del video (opcional)',
                'section_desc'    => 'Descripcion de la seccion (opcional)',
                'placeholder_txt' => 'Texto de ejemplo...',
                'change_image'    => 'Cambiar imagen',
                'embed'           => 'Insertar',
                'drag_reorder'    => 'Arrastrar para reordenar',
                'invalid_youtube' => 'Ingresa una URL de YouTube valida',
                'file_upload'     => 'Subida de archivo',
                'accepted_files'  => 'Tipos aceptados',
                'accepted_ph'     => 'ej: .pdf,.jpg,.png,.docx',
                'max_size'        => 'Tamano maximo (MB)',
            ),
        ) );
    }

    public function add_meta_boxes() {
        add_meta_box(
            'formularios_builder',
            'Constructor de formulario',
            array( $this, 'render_builder' ),
            'formulario',
            'normal',
            'high'
        );

        add_meta_box(
            'formularios_settings',
            'Configuracion del formulario',
            array( $this, 'render_settings' ),
            'formulario',
            'side'
        );
    }

    public function render_builder( $post ) {
        wp_nonce_field( 'formularios_save', 'formularios_nonce_field' );
        $elements = get_post_meta( $post->ID, '_formularios_elements', true );
        $elements = $elements ? $elements : array();
        ?>
        <div id="formularios-builder-wrap">
            <div id="formularios-canvas" data-elements="<?php echo esc_attr( wp_json_encode( $elements ) ); ?>">
                <div id="formularios-elements-list" class="formularios-sortable"></div>
                <div id="formularios-empty-state">
                    <div class="empty-icon">
                        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="12" y1="8" x2="12" y2="16"/><line x1="8" y1="12" x2="16" y2="12"/></svg>
                    </div>
                    <p>Haz clic en los botones de abajo para comenzar a construir tu formulario</p>
                </div>
            </div>

            <div id="formularios-floating-menu">
                <button type="button" class="fmenu-btn" data-type="question" title="Agregar pregunta">
                    <span class="fmenu-icon">+</span>
                    <span class="fmenu-label">Pregunta</span>
                </button>
                <button type="button" class="fmenu-btn" data-type="title_desc" title="Agregar titulo y descripcion">
                    <span class="fmenu-icon">Tt</span>
                    <span class="fmenu-label">Titulo</span>
                </button>
                <button type="button" class="fmenu-btn" data-type="image" title="Agregar imagen">
                    <span class="fmenu-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                    </span>
                    <span class="fmenu-label">Imagen</span>
                </button>
                <button type="button" class="fmenu-btn" data-type="video" title="Agregar video">
                    <span class="fmenu-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                    </span>
                    <span class="fmenu-label">Video</span>
                </button>
                <button type="button" class="fmenu-btn" data-type="section" title="Agregar seccion">
                    <span class="fmenu-icon">=</span>
                    <span class="fmenu-label">Seccion</span>
                </button>
            </div>
        </div>

        <input type="hidden" id="formularios-data" name="formularios_elements" value="<?php echo esc_attr( wp_json_encode( $elements ) ); ?>" />
        <?php
    }

    public function render_settings( $post ) {
        $settings = get_post_meta( $post->ID, '_formularios_settings', true );
        $settings = wp_parse_args( $settings ? $settings : array(), array(
            'submit_text'       => 'Enviar',
            'success_msg'       => 'Gracias! Tu respuesta ha sido registrada.',
            'show_progress'     => '1',
            'theme_color'       => '#4F46E5',
            'accent_color'      => '#10B981',
            'btn_style'         => 'rounded',
            'shadow_style'      => 'soft',
            'border_radius'     => 'medium',
            'font_family'       => 'system',
            'form_width'        => 'medium',
            'notify_admin'      => '',
            'notify_respondent' => '0',
        ) );
        ?>
        <div class="formularios-settings-panel">
            <p>
                <label>Texto del boton de envio</label>
                <input type="text" name="formularios_settings[submit_text]" value="<?php echo esc_attr( $settings['submit_text'] ); ?>" class="widefat" />
            </p>
            <p>
                <label>Mensaje de exito</label>
                <textarea name="formularios_settings[success_msg]" class="widefat" rows="3"><?php echo esc_textarea( $settings['success_msg'] ); ?></textarea>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="formularios_settings[show_progress]" value="1" <?php checked( $settings['show_progress'], '1' ); ?> />
                    Mostrar barra de progreso (formularios con secciones)
                </label>
            </p>
            <hr>
            <p style="margin-bottom:4px;"><strong>Apariencia</strong></p>
            <p>
                <label>Color primario</label>
                <input type="color" name="formularios_settings[theme_color]" value="<?php echo esc_attr( $settings['theme_color'] ); ?>" />
            </p>
            <p>
                <label>Color de acento (exito, iconos)</label>
                <input type="color" name="formularios_settings[accent_color]" value="<?php echo esc_attr( $settings['accent_color'] ); ?>" />
            </p>
            <p>
                <label>Estilo de botones</label>
                <select name="formularios_settings[btn_style]" class="widefat">
                    <option value="rounded" <?php selected( $settings['btn_style'], 'rounded' ); ?>>Redondeado</option>
                    <option value="pill" <?php selected( $settings['btn_style'], 'pill' ); ?>>Pastilla</option>
                    <option value="square" <?php selected( $settings['btn_style'], 'square' ); ?>>Cuadrado</option>
                </select>
            </p>
            <p>
                <label>Intensidad de sombra</label>
                <select name="formularios_settings[shadow_style]" class="widefat">
                    <option value="none" <?php selected( $settings['shadow_style'], 'none' ); ?>>Sin sombra</option>
                    <option value="soft" <?php selected( $settings['shadow_style'], 'soft' ); ?>>Suave</option>
                    <option value="medium" <?php selected( $settings['shadow_style'], 'medium' ); ?>>Media</option>
                    <option value="strong" <?php selected( $settings['shadow_style'], 'strong' ); ?>>Fuerte</option>
                </select>
            </p>
            <p>
                <label>Radio de bordes</label>
                <select name="formularios_settings[border_radius]" class="widefat">
                    <option value="none" <?php selected( $settings['border_radius'], 'none' ); ?>>Sin bordes</option>
                    <option value="small" <?php selected( $settings['border_radius'], 'small' ); ?>>Pequeno</option>
                    <option value="medium" <?php selected( $settings['border_radius'], 'medium' ); ?>>Mediano</option>
                    <option value="large" <?php selected( $settings['border_radius'], 'large' ); ?>>Grande</option>
                </select>
            </p>
            <p>
                <label>Tipografia</label>
                <select name="formularios_settings[font_family]" class="widefat">
                    <option value="system" <?php selected( $settings['font_family'], 'system' ); ?>>Sistema (por defecto)</option>
                    <option value="inter" <?php selected( $settings['font_family'], 'inter' ); ?>>Inter</option>
                    <option value="poppins" <?php selected( $settings['font_family'], 'poppins' ); ?>>Poppins</option>
                    <option value="merriweather" <?php selected( $settings['font_family'], 'merriweather' ); ?>>Merriweather</option>
                </select>
            </p>
            <p>
                <label>Ancho del formulario</label>
                <select name="formularios_settings[form_width]" class="widefat">
                    <option value="compact" <?php selected( $settings['form_width'], 'compact' ); ?>>Compacto (480px)</option>
                    <option value="medium" <?php selected( $settings['form_width'], 'medium' ); ?>>Mediano (680px)</option>
                    <option value="wide" <?php selected( $settings['form_width'], 'wide' ); ?>>Amplio (880px)</option>
                    <option value="xwide" <?php selected( $settings['form_width'], 'xwide' ); ?>>Extra amplio (1080px)</option>
                    <option value="full" <?php selected( $settings['form_width'], 'full' ); ?>>Ancho completo</option>
                </select>
            </p>
            <hr>
            <p>
                <label>Enviar notificacion por email a (uno por linea)</label>
                <textarea name="formularios_settings[notify_admin]" class="widefat" rows="3" placeholder="admin@ejemplo.com"><?php echo esc_textarea( $settings['notify_admin'] ); ?></textarea>
                <small style="color:#6B7280;">Direcciones de email que recibiran una copia de cada respuesta.</small>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="formularios_settings[notify_respondent]" value="1" <?php checked( $settings['notify_respondent'], '1' ); ?> />
                    Enviar copia al encuestado (si el formulario tiene campo de email)
                </label>
            </p>
            <hr>
            <p>
                <label>Shortcode</label>
                <code style="display:block;padding:8px;background:#f0f0f1;margin-top:4px;">[formulario id="<?php echo esc_attr( $post->ID ); ?>"]</code>
            </p>
        </div>
        <?php
    }

    public function save_form_data( $post_id ) {
        if ( ! isset( $_POST['formularios_nonce_field'] ) ||
             ! wp_verify_nonce( $_POST['formularios_nonce_field'], 'formularios_save' ) ) {
            return;
        }

        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;

        if ( isset( $_POST['formularios_elements'] ) ) {
            $elements = json_decode( stripslashes( $_POST['formularios_elements'] ), true );
            if ( is_array( $elements ) ) {
                $sanitized = $this->sanitize_elements( $elements );
                update_post_meta( $post_id, '_formularios_elements', $sanitized );
            }
        }

        if ( isset( $_POST['formularios_settings'] ) ) {
            $raw = $_POST['formularios_settings'];

            $allowed_btn     = array( 'rounded', 'pill', 'square' );
            $allowed_shadow  = array( 'none', 'soft', 'medium', 'strong' );
            $allowed_radius  = array( 'none', 'small', 'medium', 'large' );
            $allowed_font    = array( 'system', 'inter', 'poppins', 'merriweather' );
            $allowed_width   = array( 'compact', 'medium', 'wide', 'xwide', 'full' );

            $settings = array(
                'submit_text'       => sanitize_text_field( $raw['submit_text'] ?? '' ),
                'success_msg'       => sanitize_textarea_field( $raw['success_msg'] ?? '' ),
                'show_progress'     => isset( $raw['show_progress'] ) ? '1' : '0',
                'theme_color'       => sanitize_hex_color( $raw['theme_color'] ?? '#4F46E5' ),
                'accent_color'      => sanitize_hex_color( $raw['accent_color'] ?? '#10B981' ),
                'btn_style'         => in_array( $raw['btn_style'] ?? '', $allowed_btn, true ) ? $raw['btn_style'] : 'rounded',
                'shadow_style'      => in_array( $raw['shadow_style'] ?? '', $allowed_shadow, true ) ? $raw['shadow_style'] : 'soft',
                'border_radius'     => in_array( $raw['border_radius'] ?? '', $allowed_radius, true ) ? $raw['border_radius'] : 'medium',
                'font_family'       => in_array( $raw['font_family'] ?? '', $allowed_font, true ) ? $raw['font_family'] : 'system',
                'form_width'        => in_array( $raw['form_width'] ?? '', $allowed_width, true ) ? $raw['form_width'] : 'medium',
                'notify_admin'      => sanitize_textarea_field( $raw['notify_admin'] ?? '' ),
                'notify_respondent' => isset( $raw['notify_respondent'] ) ? '1' : '0',
            );
            update_post_meta( $post_id, '_formularios_settings', $settings );
        }
    }

    private function sanitize_elements( $elements ) {
        $clean = array();
        foreach ( $elements as $el ) {
            $type = sanitize_text_field( $el['type'] ?? '' );
            $allowed_types = array( 'question', 'title_desc', 'image', 'video', 'section' );
            if ( ! in_array( $type, $allowed_types, true ) ) continue;

            $item = array(
                'type' => $type,
                'id'   => sanitize_text_field( $el['id'] ?? wp_generate_uuid4() ),
            );

            switch ( $type ) {
                case 'question':
                    $item['label']          = sanitize_text_field( $el['label'] ?? '' );
                    $item['input_type']     = sanitize_text_field( $el['input_type'] ?? 'text' );
                    $item['required']       = ! empty( $el['required'] );
                    $item['custom_required_message']       = sanitize_text_field( $el['custom_required_message'] ?? '' );
                    $item['custom_email_error']            = sanitize_text_field( $el['custom_email_error'] ?? '' );
                    $item['custom_email_mismatch_error']   = sanitize_text_field( $el['custom_email_mismatch_error'] ?? '' );
                    $item['custom_number_error']           = sanitize_text_field( $el['custom_number_error'] ?? '' );
                    $item['custom_file_size_error']        = sanitize_text_field( $el['custom_file_size_error'] ?? '' );
                    $item['custom_file_type_error']        = sanitize_text_field( $el['custom_file_type_error'] ?? '' );
                    $item['placeholder']    = sanitize_text_field( $el['placeholder'] ?? '' );
                    $allowed_layouts        = array( 'full', 'half', 'third', 'quarter', 'two_thirds', 'custom' );
                    $item['layout']         = in_array( $el['layout'] ?? '', $allowed_layouts, true ) ? $el['layout'] : 'full';
                    $item['custom_width']   = preg_match( '/^\d+(\.\d+)?\s*(px|%|fr|em|rem|vw)$/', trim( $el['custom_width'] ?? '' ) ) ? trim( $el['custom_width'] ) : '';
                    $item['track_stats']    = ! empty( $el['track_stats'] );
                    $item['accepted_types'] = sanitize_text_field( $el['accepted_types'] ?? '' );
                    $item['max_size']       = absint( $el['max_size'] ?? 5 );
                    $item['options']        = array();
                    if ( ! empty( $el['options'] ) && is_array( $el['options'] ) ) {
                        foreach ( $el['options'] as $opt ) {
                            if ( is_array( $opt ) ) {
                                $item['options'][] = array(
                                    'label'         => sanitize_text_field( $opt['label'] ?? '' ),
                                    'go_to_section' => sanitize_text_field( $opt['go_to_section'] ?? '' ),
                                );
                            } else {
                                $item['options'][] = array(
                                    'label'         => sanitize_text_field( $opt ),
                                    'go_to_section' => '',
                                );
                            }
                        }
                    }
                    break;

                case 'title_desc':
                    $item['title']       = sanitize_text_field( $el['title'] ?? '' );
                    $item['description'] = wp_kses_post( $el['description'] ?? '' );
                    break;

                case 'image':
                    $item['image_url'] = esc_url_raw( $el['image_url'] ?? '' );
                    $item['image_id']  = absint( $el['image_id'] ?? 0 );
                    $item['caption']   = sanitize_text_field( $el['caption'] ?? '' );
                    break;

                case 'video':
                    $item['video_url'] = esc_url_raw( $el['video_url'] ?? '' );
                    $item['caption']   = sanitize_text_field( $el['caption'] ?? '' );
                    break;

                case 'section':
                    $item['title']       = sanitize_text_field( $el['title'] ?? '' );
                    $item['description'] = sanitize_text_field( $el['description'] ?? '' );
                    break;
            }

            $clean[] = $item;
        }
        return $clean;
    }

    public function search_youtube() {
        check_ajax_referer( 'formularios_nonce', 'nonce' );
        $url = isset( $_POST['url'] ) ? esc_url_raw( $_POST['url'] ) : '';

        $video_id = '';
        if ( preg_match( '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/', $url, $matches ) ) {
            $video_id = $matches[1];
        }

        if ( $video_id ) {
            wp_send_json_success( array(
                'video_id'  => $video_id,
                'embed_url' => 'https://www.youtube.com/embed/' . $video_id,
            ) );
        } else {
            wp_send_json_error( 'URL de YouTube invalida' );
        }
    }
}
