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

        wp_localize_script( 'formularios-admin', 'formularios', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'formularios_nonce' ),
            'i18n'     => array(
                'question'       => __( 'Question', 'formularios' ),
                'title_desc'     => __( 'Title & Description', 'formularios' ),
                'image'          => __( 'Image', 'formularios' ),
                'video'          => __( 'Video', 'formularios' ),
                'section'        => __( 'Section', 'formularios' ),
                'select_image'   => __( 'Select Image', 'formularios' ),
                'remove'         => __( 'Remove', 'formularios' ),
                'duplicate'      => __( 'Duplicate', 'formularios' ),
                'required'       => __( 'Required', 'formularios' ),
                'placeholder'    => __( 'Enter your question...', 'formularios' ),
                'title_ph'       => __( 'Enter title...', 'formularios' ),
                'desc_ph'        => __( 'Enter description...', 'formularios' ),
                'section_ph'     => __( 'Section title...', 'formularios' ),
                'youtube_search' => __( 'Search YouTube or paste URL...', 'formularios' ),
                'option_ph'      => __( 'Option', 'formularios' ),
                'add_option'     => __( 'Add option', 'formularios' ),
            ),
        ) );
    }

    public function add_meta_boxes() {
        add_meta_box(
            'formularios_builder',
            __( 'Form Builder', 'formularios' ),
            array( $this, 'render_builder' ),
            'formulario',
            'normal',
            'high'
        );

        add_meta_box(
            'formularios_settings',
            __( 'Form Settings', 'formularios' ),
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
                    <p><?php esc_html_e( 'Click the buttons below to start building your form', 'formularios' ); ?></p>
                </div>
            </div>

            <div id="formularios-floating-menu">
                <button type="button" class="fmenu-btn" data-type="question" title="<?php esc_attr_e( 'Add Question', 'formularios' ); ?>">
                    <span class="fmenu-icon">+</span>
                    <span class="fmenu-label"><?php esc_html_e( 'Question', 'formularios' ); ?></span>
                </button>
                <button type="button" class="fmenu-btn" data-type="title_desc" title="<?php esc_attr_e( 'Add Title & Description', 'formularios' ); ?>">
                    <span class="fmenu-icon">Tt</span>
                    <span class="fmenu-label"><?php esc_html_e( 'Title', 'formularios' ); ?></span>
                </button>
                <button type="button" class="fmenu-btn" data-type="image" title="<?php esc_attr_e( 'Add Image', 'formularios' ); ?>">
                    <span class="fmenu-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>
                    </span>
                    <span class="fmenu-label"><?php esc_html_e( 'Image', 'formularios' ); ?></span>
                </button>
                <button type="button" class="fmenu-btn" data-type="video" title="<?php esc_attr_e( 'Add Video', 'formularios' ); ?>">
                    <span class="fmenu-icon">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                    </span>
                    <span class="fmenu-label"><?php esc_html_e( 'Video', 'formularios' ); ?></span>
                </button>
                <button type="button" class="fmenu-btn" data-type="section" title="<?php esc_attr_e( 'Add Section', 'formularios' ); ?>">
                    <span class="fmenu-icon">=</span>
                    <span class="fmenu-label"><?php esc_html_e( 'Section', 'formularios' ); ?></span>
                </button>
            </div>
        </div>

        <input type="hidden" id="formularios-data" name="formularios_elements" value="<?php echo esc_attr( wp_json_encode( $elements ) ); ?>" />
        <?php
    }

    public function render_settings( $post ) {
        $settings = get_post_meta( $post->ID, '_formularios_settings', true );
        $settings = wp_parse_args( $settings ? $settings : array(), array(
            'submit_text'    => __( 'Submit', 'formularios' ),
            'success_msg'    => __( 'Thank you! Your response has been recorded.', 'formularios' ),
            'show_progress'  => '1',
            'theme_color'    => '#4F46E5',
        ) );
        ?>
        <div class="formularios-settings-panel">
            <p>
                <label><?php esc_html_e( 'Submit Button Text', 'formularios' ); ?></label>
                <input type="text" name="formularios_settings[submit_text]" value="<?php echo esc_attr( $settings['submit_text'] ); ?>" class="widefat" />
            </p>
            <p>
                <label><?php esc_html_e( 'Success Message', 'formularios' ); ?></label>
                <textarea name="formularios_settings[success_msg]" class="widefat" rows="3"><?php echo esc_textarea( $settings['success_msg'] ); ?></textarea>
            </p>
            <p>
                <label>
                    <input type="checkbox" name="formularios_settings[show_progress]" value="1" <?php checked( $settings['show_progress'], '1' ); ?> />
                    <?php esc_html_e( 'Show progress bar (multi-section forms)', 'formularios' ); ?>
                </label>
            </p>
            <p>
                <label><?php esc_html_e( 'Theme Color', 'formularios' ); ?></label>
                <input type="color" name="formularios_settings[theme_color]" value="<?php echo esc_attr( $settings['theme_color'] ); ?>" />
            </p>
            <hr>
            <p>
                <label><?php esc_html_e( 'Shortcode', 'formularios' ); ?></label>
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
            $settings = array(
                'submit_text'   => sanitize_text_field( $raw['submit_text'] ?? '' ),
                'success_msg'   => sanitize_textarea_field( $raw['success_msg'] ?? '' ),
                'show_progress' => isset( $raw['show_progress'] ) ? '1' : '0',
                'theme_color'   => sanitize_hex_color( $raw['theme_color'] ?? '#4F46E5' ),
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
                    $item['label']       = sanitize_text_field( $el['label'] ?? '' );
                    $item['input_type']  = sanitize_text_field( $el['input_type'] ?? 'text' );
                    $item['required']    = ! empty( $el['required'] );
                    $item['placeholder'] = sanitize_text_field( $el['placeholder'] ?? '' );
                    $item['options']     = array();
                    if ( ! empty( $el['options'] ) && is_array( $el['options'] ) ) {
                        foreach ( $el['options'] as $opt ) {
                            $item['options'][] = sanitize_text_field( $opt );
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
            wp_send_json_error( __( 'Invalid YouTube URL', 'formularios' ) );
        }
    }
}
