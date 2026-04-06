(function($) {
    'use strict';

    $(document).ready(function() {
        if (typeof fmDashboard === 'undefined') return;

        renderStats();
        renderChart();
        renderTable();
    });

    function renderStats() {
        animateCounter('#fm-stat-total', fmDashboard.total);
        animateCounter('#fm-stat-today', fmDashboard.today);
        animateCounter('#fm-stat-week', fmDashboard.week);
        animateCounter('#fm-stat-month', fmDashboard.month);
        $('#fm-form-count').text(fmDashboard.form_count + ' formulario' + (fmDashboard.form_count !== 1 ? 's' : ''));
    }

    function animateCounter(selector, target) {
        target = parseInt(target, 10) || 0;
        var $el = $(selector);
        if (target === 0) {
            $el.text('0');
            return;
        }

        var duration = 600;
        var start = 0;
        var startTime = null;

        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3); // easeOutCubic
            var current = Math.round(start + (target - start) * eased);
            $el.text(current.toLocaleString());
            if (progress < 1) {
                requestAnimationFrame(step);
            }
        }

        requestAnimationFrame(step);
    }

    function renderChart() {
        var ctx = document.getElementById('fm-timeline-chart');
        if (!ctx) return;

        var labels = fmDashboard.timeline_labels.map(function(d) {
            var parts = d.split('-');
            return parts[2] + '/' + parts[1];
        });

        var datasets = [];

        // If there are multiple forms, show per-form lines
        if (fmDashboard.form_timelines && fmDashboard.form_timelines.length > 1) {
            var colors = [
                { bg: 'rgba(79, 70, 229, 0.1)',  border: '#4F46E5' },
                { bg: 'rgba(16, 185, 129, 0.1)', border: '#10B981' },
                { bg: 'rgba(245, 158, 11, 0.1)', border: '#F59E0B' },
                { bg: 'rgba(239, 68, 68, 0.1)',  border: '#EF4444' },
                { bg: 'rgba(139, 92, 246, 0.1)', border: '#8B5CF6' },
                { bg: 'rgba(236, 72, 153, 0.1)', border: '#EC4899' },
                { bg: 'rgba(6, 182, 212, 0.1)',  border: '#06B6D4' },
                { bg: 'rgba(132, 204, 22, 0.1)', border: '#84CC16' },
            ];

            fmDashboard.form_timelines.forEach(function(ft, i) {
                var c = colors[i % colors.length];
                datasets.push({
                    label: ft.title,
                    data: ft.data,
                    borderColor: c.border,
                    backgroundColor: c.bg,
                    fill: true,
                    tension: 0.4,
                    pointRadius: 2,
                    pointHoverRadius: 5,
                    borderWidth: 2,
                });
            });
        } else {
            // Single total line
            datasets.push({
                label: 'Respuestas',
                data: fmDashboard.timeline_data,
                borderColor: '#4F46E5',
                backgroundColor: 'rgba(79, 70, 229, 0.08)',
                fill: true,
                tension: 0.4,
                pointRadius: 2,
                pointHoverRadius: 6,
                pointBackgroundColor: '#4F46E5',
                borderWidth: 2.5,
            });
        }

        new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: datasets,
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false,
                },
                plugins: {
                    legend: {
                        display: datasets.length > 1,
                        position: 'top',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'circle',
                            padding: 20,
                            font: { size: 12, weight: '500' },
                        },
                    },
                    tooltip: {
                        backgroundColor: '#1F2937',
                        titleFont: { size: 13, weight: '600' },
                        bodyFont: { size: 12 },
                        padding: 12,
                        cornerRadius: 8,
                        displayColors: datasets.length > 1,
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: {
                            font: { size: 11 },
                            color: '#9CA3AF',
                            maxTicksLimit: 15,
                        },
                        border: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: '#F3F4F6',
                            drawBorder: false,
                        },
                        ticks: {
                            font: { size: 11 },
                            color: '#9CA3AF',
                            precision: 0,
                        },
                        border: { display: false },
                    },
                },
            },
        });
    }

    function renderTable() {
        var forms = fmDashboard.forms;
        if (!forms || forms.length === 0) {
            $('#fm-dash-table-body').html(
                '<tr><td colspan="7" class="fm-dash-empty">No hay formularios creados.</td></tr>'
            );
            return;
        }

        var total = parseInt(fmDashboard.total, 10) || 0;
        var html = '';

        forms.forEach(function(f) {
            var pct = total > 0 ? ((f.total / total) * 100).toFixed(1) : 0;
            var avg = f.this_week > 0 ? (f.this_week / 7).toFixed(1) : '0';

            var statusLabel = { publish: 'Activo', draft: 'Borrador', private: 'Privado' };
            var statusClass = 'fm-dash-status-' + f.status;

            var lastDate = f.last_submission
                ? formatDate(f.last_submission)
                : '<span class="fm-dash-no-data">Sin respuestas</span>';

            html += '<tr>';
            html += '<td><span class="fm-dash-form-name">' + escHtml(f.title) + '</span><span class="fm-dash-form-id">#' + f.id + '</span></td>';
            html += '<td class="fm-dtcol-stat">' + f.total + '</td>';
            html += '<td class="fm-dtcol-stat">' + f.this_week + '</td>';
            html += '<td class="fm-dtcol-stat">' + avg + '</td>';
            html += '<td>';
            html += '<div class="fm-dash-bar-track"><div class="fm-dash-bar-fill" style="width: ' + pct + '%"></div></div>';
            html += '<span class="fm-dash-bar-pct">' + pct + '%</span>';
            html += '</td>';
            html += '<td class="fm-dash-last-date">' + lastDate + '</td>';
            html += '<td style="text-align:center"><span class="fm-dash-status ' + statusClass + '">' + (statusLabel[f.status] || f.status) + '</span></td>';
            html += '</tr>';
        });

        $('#fm-dash-table-body').html(html);

        // Animate bars after a short delay
        setTimeout(function() {
            $('.fm-dash-bar-fill').each(function() {
                var w = $(this).css('width');
                $(this).css('width', '0%');
                var self = this;
                setTimeout(function() {
                    $(self).css('width', w);
                }, 50);
            });
        }, 100);
    }

    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        var day = String(d.getDate()).padStart(2, '0');
        var month = String(d.getMonth() + 1).padStart(2, '0');
        var year = d.getFullYear();
        var hours = String(d.getHours()).padStart(2, '0');
        var mins = String(d.getMinutes()).padStart(2, '0');
        return day + '/' + month + '/' + year + ' ' + hours + ':' + mins;
    }

    function escHtml(str) {
        if (!str) return '';
        return $('<span>').text(str).html();
    }

})(jQuery);
