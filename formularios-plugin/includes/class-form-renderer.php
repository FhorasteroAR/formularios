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
            return '<p class="formularios-error">' . esc_html__( 'Form not found.', 'formularios' ) . '</p>';
        }

        $elements = get_post_meta( $form_id, '_formularios_elements', true );
        if ( empty( $elements ) || ! is_array( $elements ) ) {
            return '<p class="formularios-error">' . esc_html__( 'This form has no elements.', 'formularios' ) . '</p>';
        }

        $settings = get_post_meta( $form_id, '_formularios_settings', true );
        $settings = wp_parse_args( $settings ? $settings : array(), array(
            'submit_text'   => __( 'Submit', 'formularios' ),
            'success_msg'   => __( 'Thank you! Your response has been recorded.', 'formularios' ),
            'show_progress' => '1',
            'theme_color'   => '#4F46E5',
        ) );

        wp_enqueue_style( 'formularios-front' );
        wp_enqueue_script( 'formularios-front' );
        wp_localize_script( 'formularios-front', 'formulariosFront', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'formularios_submit' ),
        ) );

        // Check for sections to determine multi-step
        $has_sections = false;
        foreach ( $elements as $el ) {
            if ( 'section' === $el['type'] ) {
                $has_sections = true;
                break;
            }
        }

        ob_start();
        ?>
        <div class="formularios-form-wrap" data-form-id="<?php echo esc_attr( $form_id ); ?>" style="--fm-theme: <?php echo esc_attr( $settings['theme_color'] ); ?>">
            <form class="formularios-form" method="post" novalidate>
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
                        echo '<div class="fm-section' . $active . '" data-section="' . esc_attr( $section_index ) . '">';
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
                        <button type="button" class="fm-btn fm-btn-prev" style="display:none"><?php esc_html_e( 'Previous', 'formularios' ); ?></button>
                        <button type="button" class="fm-btn fm-btn-next"><?php esc_html_e( 'Next', 'formularios' ); ?></button>
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
                    echo '<option value="">' . esc_html__( 'Select an option...', 'formularios' ) . '</option>';
                    if ( ! empty( $el['options'] ) ) {
                        foreach ( $el['options'] as $opt ) {
                            echo '<option value="' . esc_attr( $opt ) . '">' . esc_html( $opt ) . '</option>';
                        }
                    }
                    echo '</select>';
                    break;

                case 'radio':
                    echo '<div class="fm-choices">';
                    if ( ! empty( $el['options'] ) ) {
                        foreach ( $el['options'] as $j => $opt ) {
                            $rid = $name . '_' . $j;
                            echo '<label class="fm-choice" for="' . esc_attr( $rid ) . '">';
                            echo '<input type="radio" id="' . esc_attr( $rid ) . '" name="' . esc_attr( $name ) . '" value="' . esc_attr( $opt ) . '"' . $req_attr . ' />';
                            echo '<span class="fm-choice-label">' . esc_html( $opt ) . '</span>';
                            echo '</label>';
                        }
                    }
                    echo '</div>';
                    break;

                case 'checkbox':
                    echo '<div class="fm-choices">';
                    if ( ! empty( $el['options'] ) ) {
                        foreach ( $el['options'] as $j => $opt ) {
                            $cid = $name . '_' . $j;
                            echo '<label class="fm-choice" for="' . esc_attr( $cid ) . '">';
                            echo '<input type="checkbox" id="' . esc_attr( $cid ) . '" name="' . esc_attr( $name ) . '[]" value="' . esc_attr( $opt ) . '" />';
                            echo '<span class="fm-choice-label">' . esc_html( $opt ) . '</span>';
                            echo '</label>';
                        }
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
