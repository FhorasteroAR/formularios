<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Formularios_Dashboard {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_ajax_formularios_dashboard_data', array( $this, 'ajax_dashboard_data' ) );
    }

    public function add_menu_page() {
        add_submenu_page(
            'edit.php?post_type=formulario',
            'Estadisticas',
            'Estadisticas',
            'edit_posts',
            'formularios-dashboard',
            array( $this, 'render_page' )
        );
    }

    public function enqueue_assets( $hook ) {
        if ( 'formulario_page_formularios-dashboard' !== $hook ) return;

        wp_enqueue_script(
            'chartjs',
            'https://cdn.jsdelivr.net/npm/chart.js@4/dist/chart.umd.min.js',
            array(),
            '4.4.0',
            true
        );

        wp_enqueue_style(
            'formularios-dashboard',
            FORMULARIOS_URL . 'admin/css/dashboard.css',
            array(),
            FORMULARIOS_VERSION
        );

        wp_enqueue_script(
            'formularios-dashboard',
            FORMULARIOS_URL . 'admin/js/dashboard.js',
            array( 'jquery', 'chartjs' ),
            FORMULARIOS_VERSION,
            true
        );

        $today = current_time( 'Y-m-d' );
        $default_from = gmdate( 'Y-m-d', strtotime( '-30 days', strtotime( $today ) ) );

        $stats = $this->get_dashboard_data( $default_from, $today );

        wp_localize_script( 'formularios-dashboard', 'fmDashboard', array_merge( $stats, array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'formularios_dashboard' ),
            'today'    => $today,
        ) ) );
    }

    /**
     * AJAX handler for reloading dashboard with a date range.
     */
    public function ajax_dashboard_data() {
        check_ajax_referer( 'formularios_dashboard', 'nonce' );

        if ( ! current_user_can( 'edit_posts' ) ) {
            wp_send_json_error( 'Unauthorized' );
        }

        $date_from = sanitize_text_field( $_POST['date_from'] ?? '' );
        $date_to   = sanitize_text_field( $_POST['date_to'] ?? '' );

        if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_from ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date_to ) ) {
            wp_send_json_error( 'Invalid dates' );
        }

        $stats = $this->get_dashboard_data( $date_from, $date_to );
        wp_send_json_success( $stats );
    }

    /**
     * Gather all dashboard data for a given date range.
     */
    private function get_dashboard_data( $date_from, $date_to ) {
        global $wpdb;
        $table = $wpdb->prefix . 'formularios_submissions';

        $forms = get_posts( array(
            'post_type'      => 'formulario',
            'posts_per_page' => -1,
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        $today      = current_time( 'Y-m-d' );
        $week_ago   = gmdate( 'Y-m-d', strtotime( '-7 days', strtotime( $today ) ) );
        $month_start = gmdate( 'Y-m-01', strtotime( $today ) );

        // Summary counts (always absolute, not filtered by range)
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        $total_today = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE DATE(submitted_at) = %s", $today
        ) );
        $total_week = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE DATE(submitted_at) >= %s", $week_ago
        ) );
        $total_month = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE DATE(submitted_at) >= %s", $month_start
        ) );

        // Range-scoped count
        $range_total = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE DATE(submitted_at) >= %s AND DATE(submitted_at) <= %s",
            $date_from, $date_to
        ) );

        // Per-form stats (scoped to range)
        $form_stats = array();
        foreach ( $forms as $form ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND DATE(submitted_at) >= %s AND DATE(submitted_at) <= %s",
                $form->ID, $date_from, $date_to
            ) );

            $last_submission = $wpdb->get_var( $wpdb->prepare(
                "SELECT submitted_at FROM {$table} WHERE form_id = %d ORDER BY submitted_at DESC LIMIT 1",
                $form->ID
            ) );

            $form_week = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE form_id = %d AND DATE(submitted_at) >= %s",
                $form->ID, $week_ago
            ) );

            $form_stats[] = array(
                'id'              => $form->ID,
                'title'           => $form->post_title ?: 'Sin titulo #' . $form->ID,
                'total'           => $count,
                'this_week'       => $form_week,
                'last_submission' => $last_submission ?: '',
                'status'          => $form->post_status,
            );
        }

        // Timeline data scoped to range
        $start_ts = strtotime( $date_from );
        $end_ts   = strtotime( $date_to );
        $days     = max( 0, (int) round( ( $end_ts - $start_ts ) / 86400 ) );

        $timeline_labels = array();
        $timeline_data   = array();
        for ( $d = 0; $d <= $days; $d++ ) {
            $date = gmdate( 'Y-m-d', strtotime( "+{$d} days", $start_ts ) );
            $timeline_labels[] = $date;
            $timeline_data[ $date ] = 0;
        }

        $daily_counts = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(submitted_at) as day, COUNT(*) as cnt FROM {$table} WHERE DATE(submitted_at) >= %s AND DATE(submitted_at) <= %s GROUP BY DATE(submitted_at)",
            $date_from, $date_to
        ) );
        foreach ( $daily_counts as $dc ) {
            if ( isset( $timeline_data[ $dc->day ] ) ) {
                $timeline_data[ $dc->day ] = (int) $dc->cnt;
            }
        }

        // Per-form timelines
        $form_timelines = array();
        foreach ( $forms as $form ) {
            $form_daily = $wpdb->get_results( $wpdb->prepare(
                "SELECT DATE(submitted_at) as day, COUNT(*) as cnt FROM {$table} WHERE form_id = %d AND DATE(submitted_at) >= %s AND DATE(submitted_at) <= %s GROUP BY DATE(submitted_at)",
                $form->ID, $date_from, $date_to
            ) );

            $series = array();
            foreach ( $timeline_labels as $date ) {
                $series[ $date ] = 0;
            }
            foreach ( $form_daily as $fd ) {
                if ( isset( $series[ $fd->day ] ) ) {
                    $series[ $fd->day ] = (int) $fd->cnt;
                }
            }

            $form_timelines[] = array(
                'id'    => $form->ID,
                'title' => $form->post_title ?: 'Sin titulo #' . $form->ID,
                'data'  => array_values( $series ),
            );
        }

        // Tracked field stats: for each form, find fields with track_stats=true,
        // then compute value distributions from submissions in the date range.
        $field_stats = array();
        foreach ( $forms as $form ) {
            $elements = get_post_meta( $form->ID, '_formularios_elements', true );
            if ( ! is_array( $elements ) ) continue;

            $tracked = array();
            foreach ( $elements as $el ) {
                if ( 'question' !== ( $el['type'] ?? '' ) ) continue;
                if ( empty( $el['track_stats'] ) ) continue;
                $tracked[] = array(
                    'id'         => $el['id'],
                    'label'      => $el['label'] ?: $el['id'],
                    'input_type' => $el['input_type'] ?? 'text',
                );
            }

            if ( empty( $tracked ) ) continue;

            // Fetch submissions in range for this form
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT data FROM {$table} WHERE form_id = %d AND DATE(submitted_at) >= %s AND DATE(submitted_at) <= %s",
                $form->ID, $date_from, $date_to
            ) );

            $field_distributions = array();
            foreach ( $tracked as $tf ) {
                $values = array();
                foreach ( $rows as $row ) {
                    $data = json_decode( $row->data, true );
                    if ( ! is_array( $data ) ) continue;
                    foreach ( $data as $field ) {
                        if ( $field['id'] !== $tf['id'] ) continue;
                        $v = $field['value'];
                        if ( is_array( $v ) ) {
                            foreach ( $v as $sub ) {
                                $sub = trim( $sub );
                                if ( '' !== $sub ) {
                                    $values[] = $sub;
                                }
                            }
                        } else {
                            $v = trim( $v );
                            if ( '' !== $v ) {
                                $values[] = $v;
                            }
                        }
                    }
                }

                // Build frequency distribution
                $freq = array_count_values( $values );
                arsort( $freq );

                // For numeric fields compute average
                $numeric_avg = null;
                if ( 'number' === $tf['input_type'] && ! empty( $values ) ) {
                    $nums = array_filter( $values, 'is_numeric' );
                    if ( ! empty( $nums ) ) {
                        $numeric_avg = round( array_sum( $nums ) / count( $nums ), 2 );
                    }
                }

                $field_distributions[] = array(
                    'id'          => $tf['id'],
                    'label'       => $tf['label'],
                    'input_type'  => $tf['input_type'],
                    'total'       => count( $values ),
                    'unique'      => count( $freq ),
                    'top_values'  => array_slice( $freq, 0, 10, true ),
                    'numeric_avg' => $numeric_avg,
                );
            }

            if ( ! empty( $field_distributions ) ) {
                $field_stats[] = array(
                    'form_id'    => $form->ID,
                    'form_title' => $form->post_title ?: 'Sin titulo #' . $form->ID,
                    'fields'     => $field_distributions,
                );
            }
        }

        return array(
            'total'           => $total,
            'total_today'     => $total_today,
            'total_week'      => $total_week,
            'total_month'     => $total_month,
            'range_total'     => $range_total,
            'form_count'      => count( $forms ),
            'forms'           => $form_stats,
            'timeline_labels' => $timeline_labels,
            'timeline_data'   => array_values( $timeline_data ),
            'form_timelines'  => $form_timelines,
            'field_stats'     => $field_stats,
            'date_from'       => $date_from,
            'date_to'         => $date_to,
        );
    }

    public function render_page() {
        ?>
        <div class="wrap fm-dashboard-wrap">
            <div class="fm-dash-top-bar">
                <h1 class="wp-heading-inline">Estadisticas</h1>
                <div class="fm-dash-date-range">
                    <label for="fm-date-from">Desde</label>
                    <input type="date" id="fm-date-from" class="fm-dash-date-input" />
                    <label for="fm-date-to">Hasta</label>
                    <input type="date" id="fm-date-to" class="fm-dash-date-input" />
                    <div class="fm-dash-presets">
                        <button type="button" class="fm-dash-preset" data-days="7">7d</button>
                        <button type="button" class="fm-dash-preset" data-days="30">30d</button>
                        <button type="button" class="fm-dash-preset" data-days="90">90d</button>
                        <button type="button" class="fm-dash-preset" data-days="365">1a</button>
                    </div>
                    <button type="button" id="fm-dash-apply" class="button button-primary fm-dash-apply-btn">Aplicar</button>
                    <span id="fm-dash-loading" class="fm-dash-loading" style="display:none">
                        <span class="spinner is-active" style="float:none;margin:0"></span>
                    </span>
                </div>
            </div>

            <!-- Summary Cards -->
            <div class="fm-dash-cards">
                <div class="fm-dash-card">
                    <div class="fm-dash-card-icon fm-dash-icon-total">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>
                    </div>
                    <div class="fm-dash-card-body">
                        <span class="fm-dash-card-value" id="fm-stat-total">0</span>
                        <span class="fm-dash-card-label">Respuestas totales</span>
                    </div>
                </div>
                <div class="fm-dash-card">
                    <div class="fm-dash-card-icon fm-dash-icon-today">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                    </div>
                    <div class="fm-dash-card-body">
                        <span class="fm-dash-card-value" id="fm-stat-today">0</span>
                        <span class="fm-dash-card-label">Hoy</span>
                    </div>
                </div>
                <div class="fm-dash-card">
                    <div class="fm-dash-card-icon fm-dash-icon-week">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                    </div>
                    <div class="fm-dash-card-body">
                        <span class="fm-dash-card-value" id="fm-stat-week">0</span>
                        <span class="fm-dash-card-label">Esta semana</span>
                    </div>
                </div>
                <div class="fm-dash-card">
                    <div class="fm-dash-card-icon fm-dash-icon-range">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
                    </div>
                    <div class="fm-dash-card-body">
                        <span class="fm-dash-card-value" id="fm-stat-range">0</span>
                        <span class="fm-dash-card-label">En el rango</span>
                    </div>
                </div>
            </div>

            <!-- Timeline Chart -->
            <div class="fm-dash-chart-wrap">
                <div class="fm-dash-chart-header">
                    <h2>Tendencia de respuestas</h2>
                    <span class="fm-dash-chart-period" id="fm-chart-period-label">Ultimos 30 dias</span>
                </div>
                <div class="fm-dash-chart-container">
                    <canvas id="fm-timeline-chart"></canvas>
                </div>
            </div>

            <!-- Per-Form Stats Table -->
            <div class="fm-dash-table-wrap">
                <div class="fm-dash-table-header">
                    <h2>Rendimiento por formulario</h2>
                    <span class="fm-dash-form-count" id="fm-form-count">0 formularios</span>
                </div>
                <table class="wp-list-table widefat fixed striped fm-dash-table">
                    <thead>
                        <tr>
                            <th class="fm-dtcol-name">Formulario</th>
                            <th class="fm-dtcol-stat">En rango</th>
                            <th class="fm-dtcol-stat">Esta semana</th>
                            <th class="fm-dtcol-stat">Prom. diario</th>
                            <th class="fm-dtcol-bar">Distribucion</th>
                            <th class="fm-dtcol-date">Ultima respuesta</th>
                            <th class="fm-dtcol-status">Estado</th>
                        </tr>
                    </thead>
                    <tbody id="fm-dash-table-body"></tbody>
                </table>
            </div>

            <!-- Field Stats Section -->
            <div id="fm-field-stats-section" class="fm-dash-field-stats-section" style="display:none">
                <div class="fm-dash-field-stats-header">
                    <div class="fm-dash-field-stats-header-left">
                        <h2>Estadisticas por campo</h2>
                        <span class="fm-dash-field-stats-hint">Campos marcados con "Estadisticas" en el constructor</span>
                    </div>
                    <button type="button" id="fm-export-field-stats" class="button fm-dash-export-btn">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
                        Exportar CSV
                    </button>
                </div>
                <div id="fm-field-stats-body" class="fm-dash-field-stats-body"></div>
            </div>
        </div>
        <?php
    }
}
