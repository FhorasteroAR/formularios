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
            'accent_color'  => '#10B981',
            'btn_style'     => 'rounded',
            'shadow_style'  => 'soft',
            'border_radius' => 'medium',
            'font_family'   => 'system',
            'form_width'    => 'medium',
        ) );

        wp_enqueue_style( 'formularios-front' );
        wp_enqueue_script( 'formularios-front' );

        // Enqueue Google Font if a non-system font is selected
        $google_fonts = array(
            'inter'         => 'Inter:wght@400;500;600;700',
            'poppins'       => 'Poppins:wght@400;500;600;700',
            'merriweather'  => 'Merriweather:wght@400;700',
        );
        if ( isset( $google_fonts[ $settings['font_family'] ] ) ) {
            $font_slug = $google_fonts[ $settings['font_family'] ];
            wp_enqueue_style(
                'formularios-gfont-' . $settings['font_family'],
                'https://fonts.googleapis.com/css2?family=' . urlencode( $font_slug ) . '&display=swap',
                array(),
                null
            );
        }

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
                'file_too_large'         => 'es demasiado grande.',
                'file_type_err'          => 'Tipo de archivo no permitido.',
                'email_confirm_required' => 'Confirma tu email.',
                'email_mismatch'         => 'Los emails no coinciden.',
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

        // Build CSS custom properties from settings
        $radius_map = array( 'none' => '0px', 'small' => '4px', 'medium' => '8px', 'large' => '16px' );
        $radius_lg_map = array( 'none' => '0px', 'small' => '6px', 'medium' => '12px', 'large' => '20px' );
        $btn_radius_map = array( 'rounded' => 'var(--fm-radius)', 'pill' => '999px', 'square' => '2px' );
        $shadow_map = array(
            'none'   => array( 'none', 'none' ),
            'soft'   => array( '0 1px 3px rgba(0,0,0,0.06)', '0 8px 24px rgba(0,0,0,0.06)' ),
            'medium' => array( '0 2px 6px rgba(0,0,0,0.1)', '0 10px 30px rgba(0,0,0,0.1)' ),
            'strong' => array( '0 4px 12px rgba(0,0,0,0.12)', '0 16px 40px rgba(0,0,0,0.14)' ),
        );
        $font_map = array(
            'system'       => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
            'inter'        => "'Inter', sans-serif",
            'poppins'      => "'Poppins', sans-serif",
            'merriweather' => "'Merriweather', Georgia, serif",
        );
        $width_map = array(
            'compact' => '480px',
            'medium'  => '680px',
            'wide'    => '880px',
            'xwide'   => '1080px',
            'full'    => '100%',
        );

        $s_radius      = $settings['border_radius'];
        $s_shadow       = $settings['shadow_style'];
        $s_btn          = $settings['btn_style'];
        $s_font         = $settings['font_family'];
        $s_width        = $settings['form_width'];

        $css_vars  = '--fm-theme:' . esc_attr( $settings['theme_color'] ) . ';';
        $css_vars .= '--fm-accent:' . esc_attr( $settings['accent_color'] ) . ';';
        $css_vars .= '--fm-radius:' . ( $radius_map[ $s_radius ] ?? '8px' ) . ';';
        $css_vars .= '--fm-radius-lg:' . ( $radius_lg_map[ $s_radius ] ?? '12px' ) . ';';
        $css_vars .= '--fm-btn-radius:' . ( $btn_radius_map[ $s_btn ] ?? 'var(--fm-radius)' ) . ';';
        $shadow_pair = $shadow_map[ $s_shadow ] ?? $shadow_map['soft'];
        $css_vars .= '--fm-shadow:' . $shadow_pair[0] . ';';
        $css_vars .= '--fm-shadow-lg:' . $shadow_pair[1] . ';';
        $css_vars .= '--fm-font:' . ( $font_map[ $s_font ] ?? $font_map['system'] ) . ';';
        $css_vars .= '--fm-max-width:' . ( $width_map[ $s_width ] ?? '680px' ) . ';';

        ob_start();
        ?>
        <div class="formularios-form-wrap" data-form-id="<?php echo esc_attr( $form_id ); ?>" style="<?php echo $css_vars; ?>"
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
                $in_row = false;

                foreach ( $elements as $i => $el ) {
                    if ( 'section' === $el['type'] ) {
                        if ( $in_row ) { echo '</div>'; $in_row = false; }
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

                    // Determine if this element uses inline layout
                    $el_layout = $el['layout'] ?? 'full';
                    $is_inline = ( 'question' === $el['type'] && 'full' !== $el_layout );

                    if ( $is_inline && ! $in_row ) {
                        echo '<div class="fm-fields-row">';
                        $in_row = true;
                    } elseif ( ! $is_inline && $in_row ) {
                        echo '</div>';
                        $in_row = false;
                    }

                    $this->render_element( $el, $i );
                }

                if ( $in_row ) { echo '</div>'; }
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

            <div class="fm-submit-overlay" style="display:none">
                <div class="fm-submit-overlay-inner">
                    <div class="fm-submit-spinner">
                        <svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round"><path d="M12 2v4m0 12v4m-7.07-3.93l2.83-2.83m8.48-8.48l2.83-2.83M2 12h4m12 0h4M4.93 4.93l2.83 2.83m8.48 8.48l2.83 2.83"/></svg>
                    </div>
                    <p class="fm-submit-overlay-text">Enviando respuesta...</p>
                    <div class="fm-submit-progress-track">
                        <div class="fm-submit-progress-bar"></div>
                    </div>
                </div>
            </div>

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
        $layout = $el['layout'] ?? 'full';
        $layout_class = 'full' !== $layout && 'custom' !== $layout ? ' fm-field-' . esc_attr( $layout ) : '';
        $inline_style = '';
        if ( 'custom' === $layout && ! empty( $el['custom_width'] ) ) {
            $layout_class = ' fm-field-custom';
            $inline_style = ' style="flex:0 0 ' . esc_attr( $el['custom_width'] ) . ';max-width:' . esc_attr( $el['custom_width'] ) . '"';
        }
        ?>
        <div class="fm-field<?php echo $layout_class; ?>"<?php echo $inline_style; ?> data-type="<?php echo esc_attr( $el['input_type'] ); ?>">
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
                    echo '<input type="file" name="' . esc_attr( $name ) . '[]" class="fm-control fm-file-input" data-max-size="' . esc_attr( $max_size ) . '"' . $accept . $req_attr . ' multiple style="display:none" />';
                    echo '<div class="fm-file-dropzone" tabindex="0">';
                    echo '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>';
                    echo '<span class="fm-file-dropzone-text">Haz clic o arrastra archivos aqui</span>';
                    if ( ! empty( $el['accepted_types'] ) || $max_size ) {
                        $hints = array();
                        if ( ! empty( $el['accepted_types'] ) ) {
                            $hints[] = 'Formatos: ' . esc_html( $el['accepted_types'] );
                        }
                        if ( $max_size ) {
                            $hints[] = 'Max: ' . esc_html( $max_size ) . ' MB por archivo';
                        }
                        echo '<span class="fm-file-dropzone-hint">' . esc_html( implode( ' | ', $hints ) ) . '</span>';
                    }
                    echo '</div>';
                    echo '<ul class="fm-file-list"></ul>';
                    echo '</div>';
                    break;

                case 'email':
                    echo '<input type="email" name="' . esc_attr( $name ) . '" class="fm-control fm-input fm-email-input" placeholder="' . esc_attr( $el['placeholder'] ?: 'correo@ejemplo.com' ) . '"' . $req_attr . ' />';
                    echo '<label class="fm-label fm-label-confirm">Confirmar email' . $req_mark . '</label>';
                    echo '<input type="email" name="' . esc_attr( $name ) . '_confirm" class="fm-control fm-input fm-email-confirm" placeholder="Repite tu email"' . $req_attr . ' />';
                    echo '<span class="fm-error-msg fm-email-match-error"></span>';
                    break;

                default:
                    $input_type = in_array( $el['input_type'], array( 'number', 'date' ), true ) ? $el['input_type'] : 'text';
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
