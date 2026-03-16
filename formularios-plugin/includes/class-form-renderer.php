<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Formularios_Renderer {

    public function __construct() {
        add_shortcode( 'formulario', array( $this, 'render_shortcode' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
    }

    public function register_assets() {
        wp_register_style(
            'formularios-front',
            FORMULARIOS_URL . 'public/css/form.css',
            array(),
            FORMULARIOS_VERSION
        );
        wp_register_script(
            'formularios-front',
            FORMULARIOS_URL . 'public/js/form.js',
            array( 'jquery' ),
            FORMULARIOS_VERSION,
            true
        );
    }

    public function render_shortcode( $atts ) {
        $atts = shortcode_atts( array( 'id' => 0 ), $atts, 'formulario' );
        $form_id = absint( $atts['id'] );

        if ( ! $form_id || 'formulario' !== get_post_type( $form_id ) ) {
            return '<p class="formularios-error">' . esc_html( 'Formulario no encontrado.' ) . '</p>';
        }

        $elements = get_post_meta( $form_id, '_formularios_elements', true );
        if ( empty( $elements ) || ! is_array( $elements ) ) {
            return '<p class="formularios-error">' . esc_html( 'Este formulario no tiene elementos.' ) . '</p>';
        }

        $settings = get_post_meta( $form_id, '_formularios_settings', true );
        $settings = wp_parse_args( $settings ? $settings : array(), array(
            'submit_text'   => 'Enviar',
            'success_msg'   => 'Gracias! Tu respuesta ha sido registrada.',
            'show_progress' => '1',
            'theme_color'   => '#4F46E5',
        ) );

        wp_enqueue_style( 'formularios-front' );
        wp_enqueue_script( 'formularios-front' );

        $captcha_enabled = Formularios_Captcha::is_enabled();
        $captcha_site_key = $captcha_enabled ? Formularios_Captcha::get_site_key() : '';

        if ( $captcha_enabled ) {
            wp_enqueue_script(
                'google-recaptcha',
                'https://www.google.com/recaptcha/api.js?render=' . urlencode( $captcha_site_key ),
                array(),
                null,
                true
            );
        }

        // Check if form has file upload fields
        $has_file_upload = false;
        foreach ( $elements as $el ) {
            if ( 'question' === $el['type'] && 'file' === ( $el['input_type'] ?? '' ) ) {
                $has_file_upload = true;
                break;
            }
        }

        wp_localize_script( 'formularios-front', 'formulariosFront', array(
            'ajax_url'          => admin_url( 'admin-ajax.php' ),
            'nonce'             => wp_create_nonce( 'formularios_submit' ),
            'captcha_enabled'   => $captcha_enabled,
            'captcha_site_key'  => $captcha_site_key,
            'has_file_upload'   => $has_file_upload,
            'i18n'              => array(
                'sending'        => 'Enviando...',
                'required_error' => 'Este campo es obligatorio.',
                'email_error'    => 'Ingresa un email valido.',
                'error_generic'  => 'Ocurrio un error. Intenta de nuevo.',
                'file_too_large' => 'es demasiado grande.',
                'file_type_err'  => 'Tipo de archivo no permitido.',
            ),
        ) );

        // Check for sections to determine multi-step
        $has_sections = false;
        foreach ( $elements as $el ) {
            if ( 'section' === $el['type'] ) {
                $has_sections = true;
                break;
            }
        }

        // Build branching map: for each section, check if any radio/select field
        // has go_to_section options set
        $branching_map = array();
        if ( $has_sections ) {
            $branching_map = $this->build_branching_map( $elements );
        }

        ob_start();
        ?>
        <div class="formularios-form-wrap" data-form-id="<?php echo esc_attr( $form_id ); ?>" style="--fm-theme: <?php echo esc_attr( $settings['theme_color'] ); ?>"
            <?php if ( ! empty( $branching_map ) ) : ?>
                data-branching="<?php echo esc_attr( wp_json_encode( $branching_map ) ); ?>"
            <?php endif; ?>>
            <form class="formularios-form" method="post" novalidate<?php if ( $has_file_upload ) echo ' enctype="multipart/form-data"'; ?>>
                <input type="hidden" name="formularios_form_id" value="<?php echo esc_attr( $form_id ); ?>" />
                <input type="hidden" name="formularios_nonce" value="<?php echo wp_create_nonce( 'formularios_submit' ); ?>" />

                <?php if ( $has_sections && '1' === $settings['show_progress'] ) : ?>
                    <div class="fm-progress-bar">
                        <div class="fm-progress-fill"></div>
                    </div>
                <?php endif; ?>

                <?php
                $section_index = 0;
                $in_section = false;

                foreach ( $elements as $i => $el ) {
                    if ( 'section' === $el['type'] ) {
                        if ( $in_section ) {
                            echo '</div>'; // close previous section
                        }
                        $section_index++;
                        $active = ( 1 === $section_index ) ? ' active' : '';
                        echo '<div class="fm-section' . $active . '" data-section="' . esc_attr( $section_index ) . '" data-section-id="' . esc_attr( $el['id'] ) . '">';
                        if ( ! empty( $el['title'] ) ) {
                            echo '<div class="fm-section-header">';
                            echo '<h3 class="fm-section-title">' . esc_html( $el['title'] ) . '</h3>';
                            if ( ! empty( $el['description'] ) ) {
                                echo '<p class="fm-section-desc">' . esc_html( $el['description'] ) . '</p>';
                            }
                            echo '</div>';
                        }
                        $in_section = true;
                        continue;
                    }

                    $this->render_element( $el, $i );
                }

                if ( $in_section ) {
                    echo '</div>'; // close last section
                }
                ?>

                <?php if ( $has_sections ) : ?>
                    <div class="fm-nav-buttons">
                        <button type="button" class="fm-btn fm-btn-prev" style="display:none">Anterior</button>
                        <button type="button" class="fm-btn fm-btn-next">Siguiente</button>
                        <button type="submit" class="fm-btn fm-btn-submit" style="display:none"><?php echo esc_html( $settings['submit_text'] ); ?></button>
                    </div>
                <?php else : ?>
                    <div class="fm-nav-buttons">
                        <button type="submit" class="fm-btn fm-btn-submit"><?php echo esc_html( $settings['submit_text'] ); ?></button>
                    </div>
                <?php endif; ?>
            </form>

            <div class="fm-success-message" style="display:none">
                <div class="fm-success-icon">
                    <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                </div>
                <p><?php echo esc_html( $settings['success_msg'] ); ?></p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Build a branching map: fieldName => { optionValue => targetSectionId }
     */
    private function build_branching_map( $elements ) {
        $map = array();
        foreach ( $elements as $el ) {
            if ( 'question' !== $el['type'] ) continue;
            if ( ! in_array( $el['input_type'], array( 'radio', 'select' ), true ) ) continue;
            if ( empty( $el['options'] ) ) continue;

            $has_branching = false;
            $field_map = array();
            foreach ( $el['options'] as $opt ) {
                if ( is_array( $opt ) && ! empty( $opt['go_to_section'] ) ) {
                    $has_branching = true;
                    $field_map[ $opt['label'] ] = $opt['go_to_section'];
                }
            }

            if ( $has_branching ) {
                $name = 'fm_field_' . sanitize_key( $el['id'] );
                $map[ $name ] = $field_map;
            }
        }
        return $map;
    }

    private function render_element( $el, $index ) {
        switch ( $el['type'] ) {
            case 'question':
                $this->render_question( $el, $index );
                break;
            case 'title_desc':
                $this->render_title_desc( $el );
                break;
            case 'image':
                $this->render_image( $el );
                break;
            case 'video':
                $this->render_video( $el );
                break;
        }
    }

    private function render_question( $el, $index ) {
        $required = ! empty( $el['required'] );
        $req_attr = $required ? ' required' : '';
        $req_mark = $required ? ' <span class="fm-required">*</span>' : '';
        $name = 'fm_field_' . sanitize_key( $el['id'] );
        ?>
        <div class="fm-field" data-type="<?php echo esc_attr( $el['input_type'] ); ?>">
            <?php if ( ! empty( $el['label'] ) ) : ?>
                <label class="fm-label"><?php echo esc_html( $el['label'] ); ?><?php echo $req_mark; ?></label>
            <?php endif; ?>

            <?php
            switch ( $el['input_type'] ) {
                case 'textarea':
                    echo '<textarea name="' . esc_attr( $name ) . '" class="fm-control fm-textarea" placeholder="' . esc_attr( $el['placeholder'] ?? '' ) . '" rows="4"' . $req_attr . '></textarea>';
                    break;

                case 'select':
                    echo '<select name="' . esc_attr( $name ) . '" class="fm-control fm-select"' . $req_attr . '>';
                    echo '<option value="">Selecciona una opcion...</option>';
                    if ( ! empty( $el['options'] ) ) {
                        foreach ( $el['options'] as $opt ) {
                            $label = is_array( $opt ) ? ( $opt['label'] ?? '' ) : $opt;
                            echo '<option value="' . esc_attr( $label ) . '">' . esc_html( $label ) . '</option>';
                        }
                    }
                    echo '</select>';
                    break;

                case 'radio':
                    echo '<div class="fm-choices">';
                    if ( ! empty( $el['options'] ) ) {
                        foreach ( $el['options'] as $j => $opt ) {
                            $label = is_array( $opt ) ? ( $opt['label'] ?? '' ) : $opt;
                            $rid = $name . '_' . $j;
                            echo '<label class="fm-choice" for="' . esc_attr( $rid ) . '">';
                            echo '<input type="radio" id="' . esc_attr( $rid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $label ) . '"' . $req_attr . ' />';
                            echo '<span class="fm-choice-label">' . esc_html( $label ) . '</span>';
                            echo '</label>';
                        }
                    }
                    echo '</div>';
                    break;

                case 'checkbox':
                    echo '<div class="fm-choices">';
                    if ( ! empty( $el['options'] ) ) {
                        foreach ( $el['options'] as $j => $opt ) {
                            $label = is_array( $opt ) ? ( $opt['label'] ?? '' ) : $opt;
                            $cid = $name . '_' . $j;
                            echo '<label class="fm-choice" for="' . esc_attr( $cid ) . '">';
                            echo '<input type="checkbox" id="' . esc_attr( $cid ) . '" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $label ) . '" />';
                            echo '<span class="fm-choice-label">' . esc_html( $label ) . '</span>';
                            echo '</label>';
                        }
                    }
                    echo '</div>';
                    break;

                case 'file':
                    $accept = '';
                    if ( ! empty( $el['accepted_types'] ) ) {
                        $accept = ' accept="' . esc_attr( $el['accepted_types'] ) . '"';
                    }
                    $max_size = absint( $el['max_size'] ?? 5 );
                    echo '<div class="fm-file-upload-wrap">';
                    echo '<input type="file" name="' . esc_attr( $name ) . '[]" class="fm-control fm-file-input" data-max-size="' . esc_attr( $max_size ) . '"' . $accept . $req_attr . ' multiple />';
                    if ( ! empty( $el['accepted_types'] ) || $max_size ) {
                        echo '<p class="fm-file-hint">';
                        $hints = array();
                        if ( ! empty( $el['accepted_types'] ) ) {
                            $hints[] = 'Formatos: ' . esc_html( $el['accepted_types'] );
                        }
                        if ( $max_size ) {
                            $hints[] = 'Max: ' . esc_html( $max_size ) . ' MB por archivo';
                        }
                        echo esc_html( implode( ' | ', $hints ) );
                        echo '</p>';
                    }
                    echo '</div>';
                    break;

                default:
                    $input_type = in_array( $el['input_type'], array( 'email', 'number', 'date' ), true ) ? $el['input_type'] : 'text';
                    echo '<input type="' . esc_attr( $input_type ) . '" name="' . esc_attr( $name ) . '" class="fm-control fm-input" placeholder="' . esc_attr( $el['placeholder'] ?? '' ) . '"' . $req_attr . ' />';
                    break;
            }
            ?>
            <span class="fm-error-msg"></span>
        </div>
        <?php
    }

    private function render_title_desc( $el ) {
        ?>
        <div class="fm-title-block">
            <?php if ( ! empty( $el['title'] ) ) : ?>
                <h3 class="fm-block-title"><?php echo esc_html( $el['title'] ); ?></h3>
            <?php endif; ?>
            <?php if ( ! empty( $el['description'] ) ) : ?>
                <p class="fm-block-desc"><?php echo wp_kses_post( $el['description'] ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_image( $el ) {
        if ( empty( $el['image_url'] ) ) return;
        ?>
        <div class="fm-image-block">
            <img src="<?php echo esc_url( $el['image_url'] ); ?>" alt="<?php echo esc_attr( $el['caption'] ?? '' ); ?>" />
            <?php if ( ! empty( $el['caption'] ) ) : ?>
                <p class="fm-image-caption"><?php echo esc_html( $el['caption'] ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_video( $el ) {
        if ( empty( $el['video_url'] ) ) return;
        $video_id = '';
        if ( preg_match( '/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/', $el['video_url'], $m ) ) {
            $video_id = $m[1];
        }
        if ( ! $video_id ) return;
        ?>
        <div class="fm-video-block">
            <div class="fm-video-embed">
                <iframe src="<?php echo esc_url( 'https://www.youtube.com/embed/' . $video_id ); ?>" allowfullscreen loading="lazy"></iframe>
            </div>
            <?php if ( ! empty( $el['caption'] ) ) : ?>
                <p class="fm-video-caption"><?php echo esc_html( $el['caption'] ); ?></p>
            <?php endif; ?>
        </div>
        <?php
    }
}
