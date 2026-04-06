<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class Formularios_Dashboard {

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
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

        // Gather data for the dashboard
        $stats = $this->get_dashboard_data();

        wp_localize_script( 'formularios-dashboard', 'fmDashboard', $stats );
    }

    private function get_dashboard_data() {
        global $wpdb;
        $table = $wpdb->prefix . 'formularios_submissions';

        // Get all forms
        $forms = get_posts( array(
            'post_type'      => 'formulario',
            'posts_per_page' => -1,
            'post_status'    => array( 'publish', 'draft', 'private' ),
            'orderby'        => 'title',
            'order'          => 'ASC',
        ) );

        // Total submissions
        $total_submissions = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

        // Submissions today
        $today = current_time( 'Y-m-d' );
        $submissions_today = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE DATE(submitted_at) = %s",
            $today
        ) );

        // Submissions this week (last 7 days)
        $week_ago = gmdate( 'Y-m-d', strtotime( '-7 days', strtotime( $today ) ) );
        $submissions_week = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE DATE(submitted_at) >= %s",
            $week_ago
        ) );

        // Submissions this month
        $month_start = gmdate( 'Y-m-01', strtotime( $today ) );
        $submissions_month = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE DATE(submitted_at) >= %s",
            $month_start
        ) );

        // Per-form counts
        $form_stats = array();
        foreach ( $forms as $form ) {
            $count = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE form_id = %d",
                $form->ID
            ) );

            $last_submission = $wpdb->get_var( $wpdb->prepare(
                "SELECT submitted_at FROM {$table} WHERE form_id = %d ORDER BY submitted_at DESC LIMIT 1",
                $form->ID
            ) );

            // Count this week for this form
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

        // Timeline data: submissions per day for the last 30 days
        $days_back = 30;
        $start_date = gmdate( 'Y-m-d', strtotime( "-{$days_back} days", strtotime( $today ) ) );

        // Generate all dates in range
        $timeline_labels = array();
        $timeline_data = array();
        for ( $d = 0; $d <= $days_back; $d++ ) {
            $date = gmdate( 'Y-m-d', strtotime( "+{$d} days", strtotime( $start_date ) ) );
            $timeline_labels[] = $date;
            $timeline_data[ $date ] = 0;
        }

        // Fill with actual counts
        $daily_counts = $wpdb->get_results( $wpdb->prepare(
            "SELECT DATE(submitted_at) as day, COUNT(*) as cnt FROM {$table} WHERE DATE(submitted_at) >= %s GROUP BY DATE(submitted_at)",
            $start_date
        ) );

        foreach ( $daily_counts as $dc ) {
            if ( isset( $timeline_data[ $dc->day ] ) ) {
                $timeline_data[ $dc->day ] = (int) $dc->cnt;
            }
        }

        // Per-form timeline data
        $form_timelines = array();
        foreach ( $forms as $form ) {
            $form_daily = $wpdb->get_results( $wpdb->prepare(
                "SELECT DATE(submitted_at) as day, COUNT(*) as cnt FROM {$table} WHERE form_id = %d AND DATE(submitted_at) >= %s GROUP BY DATE(submitted_at)",
                $form->ID, $start_date
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

        return array(
            'total'           => $total_submissions,
            'today'           => $submissions_today,
            'week'            => $submissions_week,
            'month'           => $submissions_month,
            'form_count'      => count( $forms ),
            'forms'           => $form_stats,
            'timeline_labels' => $timeline_labels,
            'timeline_data'   => array_values( $timeline_data ),
            'form_timelines'  => $form_timelines,
        );
    }

    public function render_page() {
        ?>
        <div class="wrap fm-dashboard-wrap">
            <h1 class="wp-heading-inline">Estadisticas</h1>

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
                    <div class="fm-dash-card-icon fm-dash-icon-month">
                        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>
                    </div>
                    <div class="fm-dash-card-body">
                        <span class="fm-dash-card-value" id="fm-stat-month">0</span>
                        <span class="fm-dash-card-label">Este mes</span>
                    </div>
                </div>
            </div>

            <!-- Timeline Chart -->
            <div class="fm-dash-chart-wrap">
                <div class="fm-dash-chart-header">
                    <h2>Tendencia de respuestas</h2>
                    <span class="fm-dash-chart-period">Ultimos 30 dias</span>
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
                            <th class="fm-dtcol-stat">Total</th>
                            <th class="fm-dtcol-stat">Esta semana</th>
                            <th class="fm-dtcol-stat">Promedio diario</th>
                            <th class="fm-dtcol-bar">Distribucion</th>
                            <th class="fm-dtcol-date">Ultima respuesta</th>
                            <th class="fm-dtcol-status">Estado</th>
                        </tr>
                    </thead>
                    <tbody id="fm-dash-table-body"></tbody>
                </table>
            </div>
        </div>
        <?php
    }
}
