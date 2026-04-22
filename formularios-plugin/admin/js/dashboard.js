(function($) {
    'use strict';

    var chartInstance = null;
    var lastDashboardData = null;

    $(document).ready(function() {
        if (typeof fmDashboard === 'undefined') return;

        // Set default date range inputs
        $('#fm-date-from').val(fmDashboard.date_from);
        $('#fm-date-to').val(fmDashboard.date_to);
        highlightActivePreset();

        renderAll(fmDashboard);
        bindDateControls();
    });

    function renderAll(data) {
        lastDashboardData = data;
        renderStats(data);
        renderChart(data);
        renderTable(data);
        renderFieldStats(data);
    }

    // --- Date range controls ---

    function bindDateControls() {
        $('#fm-dash-apply').on('click', function() {
            loadData();
        });

        // Preset buttons
        $('.fm-dash-preset').on('click', function() {
            var days = parseInt($(this).data('days'), 10);
            var to = fmDashboard.today;
            var from = subtractDays(to, days);
            $('#fm-date-from').val(from);
            $('#fm-date-to').val(to);
            $('.fm-dash-preset').removeClass('active');
            $(this).addClass('active');
            loadData();
        });

        // Clear active preset when user manually changes date
        $('#fm-date-from, #fm-date-to').on('change', function() {
            $('.fm-dash-preset').removeClass('active');
        });
    }

    function loadData() {
        var from = $('#fm-date-from').val();
        var to = $('#fm-date-to').val();
        if (!from || !to) return;

        $('#fm-dash-loading').show();
        $('#fm-dash-apply').prop('disabled', true);

        $.post(fmDashboard.ajax_url, {
            action: 'formularios_dashboard_data',
            nonce: fmDashboard.nonce,
            date_from: from,
            date_to: to,
        }, function(response) {
            $('#fm-dash-loading').hide();
            $('#fm-dash-apply').prop('disabled', false);
            if (response.success && response.data) {
                renderAll(response.data);
            }
        }).fail(function() {
            $('#fm-dash-loading').hide();
            $('#fm-dash-apply').prop('disabled', false);
        });
    }

    function subtractDays(dateStr, days) {
        var d = new Date(dateStr + 'T00:00:00');
        d.setDate(d.getDate() - days);
        return d.toISOString().split('T')[0];
    }

    function highlightActivePreset() {
        var from = $('#fm-date-from').val();
        var to = $('#fm-date-to').val();
        $('.fm-dash-preset').each(function() {
            var days = parseInt($(this).data('days'), 10);
            var expected = subtractDays(to, days);
            if (from === expected && to === fmDashboard.today) {
                $(this).addClass('active');
            }
        });
    }

    // --- Stats cards ---

    function renderStats(data) {
        animateCounter('#fm-stat-total', data.total);
        animateCounter('#fm-stat-today', data.total_today);
        animateCounter('#fm-stat-week', data.total_week);
        animateCounter('#fm-stat-range', data.range_total);
        $('#fm-form-count').text(data.form_count + ' formulario' + (data.form_count !== 1 ? 's' : ''));

        // Update period label
        var fromParts = (data.date_from || '').split('-');
        var toParts = (data.date_to || '').split('-');
        if (fromParts.length === 3 && toParts.length === 3) {
            $('#fm-chart-period-label').text(
                fromParts[2] + '/' + fromParts[1] + '/' + fromParts[0] +
                ' — ' +
                toParts[2] + '/' + toParts[1] + '/' + toParts[0]
            );
        }
    }

    function animateCounter(selector, target) {
        target = parseInt(target, 10) || 0;
        var $el = $(selector);
        if (target === 0) { $el.text('0'); return; }

        var duration = 600;
        var startTime = null;
        function step(timestamp) {
            if (!startTime) startTime = timestamp;
            var progress = Math.min((timestamp - startTime) / duration, 1);
            var eased = 1 - Math.pow(1 - progress, 3);
            $el.text(Math.round(target * eased).toLocaleString());
            if (progress < 1) requestAnimationFrame(step);
        }
        requestAnimationFrame(step);
    }

    // --- Chart ---

    function renderChart(data) {
        var ctx = document.getElementById('fm-timeline-chart');
        if (!ctx) return;

        if (chartInstance) {
            chartInstance.destroy();
            chartInstance = null;
        }

        var labels = (data.timeline_labels || []).map(function(d) {
            var p = d.split('-');
            return p[2] + '/' + p[1];
        });

        var datasets = [];
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

        if (data.form_timelines && data.form_timelines.length > 1) {
            data.form_timelines.forEach(function(ft, i) {
                var c = colors[i % colors.length];
                datasets.push({
                    label: ft.title,
                    data: ft.data,
                    borderColor: c.border,
                    backgroundColor: c.bg,
                    fill: true, tension: 0.4,
                    pointRadius: 2, pointHoverRadius: 5, borderWidth: 2,
                });
            });
        } else {
            datasets.push({
                label: 'Respuestas',
                data: data.timeline_data,
                borderColor: '#4F46E5',
                backgroundColor: 'rgba(79, 70, 229, 0.08)',
                fill: true, tension: 0.4,
                pointRadius: 2, pointHoverRadius: 6,
                pointBackgroundColor: '#4F46E5', borderWidth: 2.5,
            });
        }

        chartInstance = new Chart(ctx, {
            type: 'line',
            data: { labels: labels, datasets: datasets },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: { mode: 'index', intersect: false },
                plugins: {
                    legend: {
                        display: datasets.length > 1,
                        position: 'top',
                        labels: { usePointStyle: true, pointStyle: 'circle', padding: 20, font: { size: 12, weight: '500' } },
                    },
                    tooltip: {
                        backgroundColor: '#1F2937',
                        titleFont: { size: 13, weight: '600' },
                        bodyFont: { size: 12 },
                        padding: 12, cornerRadius: 8,
                        displayColors: datasets.length > 1,
                    },
                },
                scales: {
                    x: {
                        grid: { display: false },
                        ticks: { font: { size: 11 }, color: '#9CA3AF', maxTicksLimit: 15 },
                        border: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        grid: { color: '#F3F4F6', drawBorder: false },
                        ticks: { font: { size: 11 }, color: '#9CA3AF', precision: 0 },
                        border: { display: false },
                    },
                },
            },
        });
    }

    // --- Table ---

    function renderTable(data) {
        var forms = data.forms;
        if (!forms || forms.length === 0) {
            $('#fm-dash-table-body').html('<tr><td colspan="8" class="fm-dash-empty">No hay formularios creados.</td></tr>');
            return;
        }

        var rangeTotal = parseInt(data.range_total, 10) || 0;
        var dateFrom = data.date_from || '';
        var dateTo = data.date_to || '';
        var rangeDays = 1;
        if (dateFrom && dateTo) {
            rangeDays = Math.max(1, Math.round((new Date(dateTo) - new Date(dateFrom)) / 86400000));
        }

        var html = '';
        forms.forEach(function(f) {
            var pct = rangeTotal > 0 ? ((f.total / rangeTotal) * 100).toFixed(1) : 0;
            var avg = f.total > 0 ? (f.total / rangeDays).toFixed(1) : '0';
            var statusLabel = { publish: 'Activo', draft: 'Borrador', private: 'Privado' };
            var statusClass = 'fm-dash-status-' + f.status;
            var lastDate = f.last_submission
                ? formatDate(f.last_submission)
                : '<span class="fm-dash-no-data">Sin respuestas</span>';

            html += '<tr>';
            html += '<td class="fm-dtcol-check"><input type="checkbox" class="fm-dash-form-check" value="' + f.id + '"></td>';
            html += '<td><span class="fm-dash-form-name">' + escHtml(f.title) + '</span><span class="fm-dash-form-id">#' + f.id + '</span></td>';
            html += '<td class="fm-dtcol-stat">' + f.total + '</td>';
            html += '<td class="fm-dtcol-stat">' + f.this_week + '</td>';
            html += '<td class="fm-dtcol-stat">' + avg + '</td>';
            html += '<td>';
            html += '<div class="fm-dash-bar-track"><div class="fm-dash-bar-fill" style="width:' + pct + '%"></div></div>';
            html += '<span class="fm-dash-bar-pct">' + pct + '%</span>';
            html += '</td>';
            html += '<td class="fm-dash-last-date">' + lastDate + '</td>';
            html += '<td style="text-align:center"><span class="fm-dash-status ' + statusClass + '">' + (statusLabel[f.status] || f.status) + '</span></td>';
            html += '</tr>';
        });

        $('#fm-dash-table-body').html(html);
        $('#fm-dash-select-all').prop('checked', false);
    }

    // --- Field Stats ---

    function renderFieldStats(data) {
        var stats = data.field_stats;
        var $section = $('#fm-field-stats-section');
        var $body = $('#fm-field-stats-body');

        lastFieldStats = stats;
        lastDateRange = { from: data.date_from || '', to: data.date_to || '' };

        if (!stats || stats.length === 0) {
            $section.hide();
            return;
        }

        $section.show();
        var html = '';

        stats.forEach(function(formGroup) {
            html += '<div class="fm-fstat-form">';
            html += '<h3 class="fm-fstat-form-title">' + escHtml(formGroup.form_title) + '</h3>';
            html += '<div class="fm-fstat-fields">';

            formGroup.fields.forEach(function(field) {
                html += '<div class="fm-fstat-card">';
                html += '<div class="fm-fstat-card-header">';
                html += '<span class="fm-fstat-label">' + escHtml(field.label) + '</span>';
                html += '<span class="fm-fstat-type">' + escHtml(field.input_type) + '</span>';
                html += '</div>';

                html += '<div class="fm-fstat-summary">';
                html += '<div class="fm-fstat-metric"><span class="fm-fstat-metric-val">' + field.total + '</span><span class="fm-fstat-metric-label">Respuestas</span></div>';
                html += '<div class="fm-fstat-metric"><span class="fm-fstat-metric-val">' + field.unique + '</span><span class="fm-fstat-metric-label">Valores unicos</span></div>';
                if (field.numeric_avg !== null && field.numeric_avg !== undefined) {
                    html += '<div class="fm-fstat-metric"><span class="fm-fstat-metric-val">' + field.numeric_avg + '</span><span class="fm-fstat-metric-label">Promedio</span></div>';
                }
                html += '</div>';

                // Value distribution
                var topValues = field.top_values;
                if (topValues && typeof topValues === 'object') {
                    var keys = Object.keys(topValues);
                    if (keys.length > 0) {
                        var maxCount = topValues[keys[0]] || 1;
                        html += '<div class="fm-fstat-dist">';
                        html += '<span class="fm-fstat-dist-title">Distribucion de valores</span>';
                        keys.forEach(function(val) {
                            var count = topValues[val];
                            var pct = field.total > 0 ? ((count / field.total) * 100).toFixed(1) : 0;
                            var barW = maxCount > 0 ? ((count / maxCount) * 100) : 0;
                            html += '<div class="fm-fstat-dist-row">';
                            html += '<span class="fm-fstat-dist-label" title="' + escAttr(val) + '">' + escHtml(val.length > 40 ? val.substring(0, 37) + '...' : val) + '</span>';
                            html += '<div class="fm-fstat-dist-bar-track"><div class="fm-fstat-dist-bar" style="width:' + barW + '%"></div></div>';
                            html += '<span class="fm-fstat-dist-count">' + count + ' <small>(' + pct + '%)</small></span>';
                            html += '</div>';
                        });
                        html += '</div>';
                    }
                }

                html += '</div>';
            });

            html += '</div>';
            html += '</div>';
        });

        $body.html(html);
    }

    // --- Export Dropdown ---

    var lastFieldStats = null;
    var lastDateRange = {};

    // Toggle dropdown
    $(document).on('click', '.fm-export-trigger', function(e) {
        e.stopPropagation();
        var $dropdown = $(this).closest('.fm-export-dropdown');
        var wasOpen = $dropdown.hasClass('open');
        $('.fm-export-dropdown').removeClass('open');
        if (!wasOpen) $dropdown.addClass('open');
    });

    // Close dropdown on outside click
    $(document).on('click', function() {
        $('.fm-export-dropdown').removeClass('open');
    });

    $(document).on('click', '.fm-export-menu', function(e) {
        e.stopPropagation();
    });

    // --- Export Field Stats CSV ---

    $(document).on('click', '#fm-export-field-stats-csv', function() {
        $('.fm-export-dropdown').removeClass('open');
        if (!lastFieldStats || lastFieldStats.length === 0) return;
        exportFieldStatsCSV();
    });

    function exportFieldStatsCSV() {
        var rows = [];
        rows.push(['Formulario', 'Campo', 'Tipo', 'Respuestas', 'Valores unicos', 'Promedio', 'Valor', 'Cantidad', 'Porcentaje']);

        lastFieldStats.forEach(function(formGroup) {
            formGroup.fields.forEach(function(field) {
                var topValues = field.top_values;
                var keys = topValues && typeof topValues === 'object' ? Object.keys(topValues) : [];

                if (keys.length === 0) {
                    rows.push([
                        formGroup.form_title, field.label, field.input_type,
                        field.total, field.unique,
                        field.numeric_avg !== null && field.numeric_avg !== undefined ? field.numeric_avg : '',
                        '', '', ''
                    ]);
                } else {
                    keys.forEach(function(val, i) {
                        var count = topValues[val];
                        var pct = field.total > 0 ? ((count / field.total) * 100).toFixed(1) + '%' : '0%';
                        rows.push([
                            i === 0 ? formGroup.form_title : '',
                            i === 0 ? field.label : '',
                            i === 0 ? field.input_type : '',
                            i === 0 ? field.total : '',
                            i === 0 ? field.unique : '',
                            i === 0 ? (field.numeric_avg !== null && field.numeric_avg !== undefined ? field.numeric_avg : '') : '',
                            val, count, pct
                        ]);
                    });
                }
            });
        });

        downloadCSV(rows, 'estadisticas-campos');
    }

    // --- Export Field Stats PDF ---

    $(document).on('click', '#fm-export-field-stats-pdf', function() {
        $('.fm-export-dropdown').removeClass('open');
        if (!lastFieldStats || lastFieldStats.length === 0) return;
        exportFieldStatsPDF();
    });

    function exportFieldStatsPDF() {
        var from = lastDateRange.from || '';
        var to = lastDateRange.to || '';

        var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Estadisticas por campo</title>';
        html += '<style>';
        html += 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1F2937;max-width:900px;margin:0 auto;padding:40px 32px;font-size:13px;line-height:1.5}';
        html += 'h1{font-size:20px;font-weight:700;margin:0 0 4px;color:#1F2937}';
        html += '.period{font-size:12px;color:#6B7280;margin-bottom:28px}';
        html += '.form-title{font-size:15px;font-weight:700;color:#374151;margin:24px 0 10px;padding-bottom:6px;border-bottom:2px solid #E5E7EB}';
        html += '.field-card{border:1px solid #E5E7EB;border-radius:8px;padding:16px;margin-bottom:12px;page-break-inside:avoid}';
        html += '.field-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #F3F4F6}';
        html += '.field-label{font-size:13px;font-weight:700;color:#1F2937}';
        html += '.field-type{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#9CA3AF;background:#F3F4F6;padding:2px 8px;border-radius:4px}';
        html += '.metrics{display:flex;gap:20px;margin-bottom:10px}';
        html += '.metric-val{font-size:18px;font-weight:700;color:#4F46E5}';
        html += '.metric-label{font-size:10px;color:#9CA3AF}';
        html += 'table{width:100%;border-collapse:collapse;font-size:12px}';
        html += 'th{text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6B7280;padding:6px 8px;border-bottom:1px solid #E5E7EB}';
        html += 'td{padding:5px 8px;border-bottom:1px solid #F3F4F6;color:#374151}';
        html += '.bar-cell{width:40%}.bar-track{height:6px;background:#F3F4F6;border-radius:99px;overflow:hidden}';
        html += '.bar-fill{height:100%;background:linear-gradient(90deg,#4F46E5,#818CF8);border-radius:99px}';
        html += '@media print{body{padding:20px}}';
        html += '</style></head><body>';
        html += '<h1>Estadisticas por campo</h1>';
        if (from && to) {
            html += '<div class="period">' + escHtml(from) + ' — ' + escHtml(to) + '</div>';
        }

        lastFieldStats.forEach(function(formGroup) {
            html += '<div class="form-title">' + escHtml(formGroup.form_title) + '</div>';
            formGroup.fields.forEach(function(field) {
                html += '<div class="field-card">';
                html += '<div class="field-header"><span class="field-label">' + escHtml(field.label) + '</span><span class="field-type">' + escHtml(field.input_type) + '</span></div>';
                html += '<div class="metrics">';
                html += '<div><div class="metric-val">' + field.total + '</div><div class="metric-label">Respuestas</div></div>';
                html += '<div><div class="metric-val">' + field.unique + '</div><div class="metric-label">Valores unicos</div></div>';
                if (field.numeric_avg !== null && field.numeric_avg !== undefined) {
                    html += '<div><div class="metric-val">' + field.numeric_avg + '</div><div class="metric-label">Promedio</div></div>';
                }
                html += '</div>';

                var topValues = field.top_values;
                if (topValues && typeof topValues === 'object') {
                    var keys = Object.keys(topValues);
                    if (keys.length > 0) {
                        var maxCount = topValues[keys[0]] || 1;
                        html += '<table><thead><tr><th>Valor</th><th class="bar-cell">Distribucion</th><th style="text-align:right">Cantidad</th><th style="text-align:right">%</th></tr></thead><tbody>';
                        keys.forEach(function(val) {
                            var count = topValues[val];
                            var pct = field.total > 0 ? ((count / field.total) * 100).toFixed(1) : 0;
                            var barW = maxCount > 0 ? ((count / maxCount) * 100) : 0;
                            html += '<tr><td>' + escHtml(val) + '</td>';
                            html += '<td class="bar-cell"><div class="bar-track"><div class="bar-fill" style="width:' + barW + '%"></div></div></td>';
                            html += '<td style="text-align:right;font-weight:600">' + count + '</td>';
                            html += '<td style="text-align:right;color:#9CA3AF">' + pct + '%</td></tr>';
                        });
                        html += '</tbody></table>';
                    }
                }
                html += '</div>';
            });
        });

        html += '</body></html>';
        openPrintWindow(html);
    }

    function openPrintWindow(html) {
        var w = window.open('', '_blank', 'width=900,height=700');
        w.document.write(html);
        w.document.close();
        w.focus();
        setTimeout(function() { w.print(); }, 300);
    }

    function downloadCSV(rows, basename) {
        var csv = rows.map(function(r) {
            return r.map(function(cell) {
                var s = String(cell === null || cell === undefined ? '' : cell);
                if (s.indexOf(',') !== -1 || s.indexOf('"') !== -1 || s.indexOf('\n') !== -1) {
                    return '"' + s.replace(/"/g, '""') + '"';
                }
                return s;
            }).join(',');
        }).join('\r\n');

        var BOM = '\uFEFF';
        var blob = new Blob([BOM + csv], { type: 'text/csv;charset=utf-8;' });
        var url = URL.createObjectURL(blob);
        var a = document.createElement('a');
        a.href = url;
        var from = lastDateRange.from || '';
        var to = lastDateRange.to || '';
        a.download = basename + (from ? '_' + from : '') + (to ? '_' + to : '') + '.csv';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        URL.revokeObjectURL(url);
    }

    // --- Select All / Form Checkboxes ---

    $(document).on('change', '#fm-dash-select-all', function() {
        var checked = $(this).is(':checked');
        $('.fm-dash-form-check').prop('checked', checked);
        updateExportLabel();
    });

    $(document).on('change', '.fm-dash-form-check', function() {
        var total = $('.fm-dash-form-check').length;
        var checked = $('.fm-dash-form-check:checked').length;
        $('#fm-dash-select-all').prop('checked', total > 0 && checked === total);
        updateExportLabel();
    });

    function updateExportLabel() {
        var checked = $('.fm-dash-form-check:checked').length;
        var label = checked > 0 ? 'Exportar (' + checked + ')' : 'Exportar todo';
        $('.fm-dash-export-all .fm-export-trigger').contents().filter(function() {
            return this.nodeType === 3 && $.trim(this.nodeValue).length > 0;
        }).first().replaceWith(' ' + label + ' ');
    }

    function getSelectedFormIds() {
        var ids = [];
        $('.fm-dash-form-check:checked').each(function() {
            ids.push(parseInt($(this).val(), 10));
        });
        return ids;
    }

    function filterDataByForms(data, formIds) {
        if (!formIds || formIds.length === 0) return data;
        var filtered = $.extend(true, {}, data);
        filtered.forms = (data.forms || []).filter(function(f) { return formIds.indexOf(f.id) !== -1; });
        filtered.form_timelines = (data.form_timelines || []).filter(function(ft) { return formIds.indexOf(ft.id) !== -1; });
        filtered.field_stats = (data.field_stats || []).filter(function(fs) { return formIds.indexOf(fs.form_id) !== -1; });
        return filtered;
    }

    // --- Export All CSV ---

    $(document).on('click', '#fm-export-all-csv', function() {
        $('.fm-export-dropdown').removeClass('open');
        if (!lastDashboardData) return;
        exportAllCSV();
    });

    function exportAllCSV() {
        var formIds = getSelectedFormIds();
        var data = filterDataByForms(lastDashboardData, formIds);
        var rows = [];

        // Section 1: Summary
        rows.push(['=== RESUMEN GENERAL ===']);
        rows.push(['Metrica', 'Valor']);
        rows.push(['Respuestas totales', lastDashboardData.total]);
        rows.push(['Hoy', lastDashboardData.total_today]);
        rows.push(['Esta semana', lastDashboardData.total_week]);
        rows.push(['En el rango', lastDashboardData.range_total]);
        rows.push(['Rango', (lastDashboardData.date_from || '') + ' a ' + (lastDashboardData.date_to || '')]);
        rows.push(['Formularios', lastDashboardData.form_count]);
        if (formIds.length > 0) {
            rows.push(['Formularios seleccionados', formIds.length]);
        }
        rows.push([]);

        // Section 2: Per-form performance
        rows.push(['=== RENDIMIENTO POR FORMULARIO ===']);
        rows.push(['Formulario', 'ID', 'En rango', 'Esta semana', 'Prom. diario', 'Distribucion %', 'Ultima respuesta', 'Estado']);

        var rangeTotal = parseInt(data.range_total, 10) || 0;
        var rangeDays = 1;
        if (data.date_from && data.date_to) {
            rangeDays = Math.max(1, Math.round((new Date(data.date_to) - new Date(data.date_from)) / 86400000));
        }

        (data.forms || []).forEach(function(f) {
            var pct = rangeTotal > 0 ? ((f.total / rangeTotal) * 100).toFixed(1) + '%' : '0%';
            var avg = f.total > 0 ? (f.total / rangeDays).toFixed(1) : '0';
            var statusLabel = { publish: 'Activo', draft: 'Borrador', private: 'Privado' };
            rows.push([f.title, f.id, f.total, f.this_week, avg, pct, f.last_submission || 'Sin respuestas', statusLabel[f.status] || f.status]);
        });
        rows.push([]);

        // Section 3: Timeline
        rows.push(['=== LINEA DE TIEMPO ===']);
        var timelineHeader = ['Fecha', 'Total'];
        (data.form_timelines || []).forEach(function(ft) {
            timelineHeader.push(ft.title);
        });
        rows.push(timelineHeader);

        var labels = lastDashboardData.timeline_labels || [];
        var totalData = lastDashboardData.timeline_data || [];
        labels.forEach(function(date, i) {
            var row = [date, totalData[i] || 0];
            (data.form_timelines || []).forEach(function(ft) {
                row.push(ft.data[i] || 0);
            });
            rows.push(row);
        });
        rows.push([]);

        // Section 4: Field stats
        var fieldStats = data.field_stats || [];
        if (fieldStats.length > 0) {
            rows.push(['=== ESTADISTICAS POR CAMPO ===']);
            rows.push(['Formulario', 'Campo', 'Tipo', 'Respuestas', 'Valores unicos', 'Promedio', 'Valor', 'Cantidad', 'Porcentaje']);

            fieldStats.forEach(function(formGroup) {
                formGroup.fields.forEach(function(field) {
                    var topValues = field.top_values;
                    var keys = topValues && typeof topValues === 'object' ? Object.keys(topValues) : [];

                    if (keys.length === 0) {
                        rows.push([
                            formGroup.form_title, field.label, field.input_type,
                            field.total, field.unique,
                            field.numeric_avg !== null && field.numeric_avg !== undefined ? field.numeric_avg : '',
                            '', '', ''
                        ]);
                    } else {
                        keys.forEach(function(val, j) {
                            var count = topValues[val];
                            var pct = field.total > 0 ? ((count / field.total) * 100).toFixed(1) + '%' : '0%';
                            rows.push([
                                j === 0 ? formGroup.form_title : '',
                                j === 0 ? field.label : '',
                                j === 0 ? field.input_type : '',
                                j === 0 ? field.total : '',
                                j === 0 ? field.unique : '',
                                j === 0 ? (field.numeric_avg !== null && field.numeric_avg !== undefined ? field.numeric_avg : '') : '',
                                val, count, pct
                            ]);
                        });
                    }
                });
            });
        }

        downloadCSV(rows, 'estadisticas-completas');
    }

    // --- Export All PDF ---

    $(document).on('click', '#fm-export-all-pdf', function() {
        $('.fm-export-dropdown').removeClass('open');
        if (!lastDashboardData) return;
        exportAllPDF();
    });

    function exportAllPDF() {
        var formIds = getSelectedFormIds();
        var data = filterDataByForms(lastDashboardData, formIds);
        var from = lastDashboardData.date_from || '';
        var to = lastDashboardData.date_to || '';

        var chartImage = '';
        if (chartInstance && typeof chartInstance.toBase64Image === 'function') {
            chartImage = chartInstance.toBase64Image();
        }

        var html = '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Reporte de Estadisticas</title>';
        html += '<style>';
        html += 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;color:#1F2937;max-width:960px;margin:0 auto;padding:40px 32px;font-size:13px;line-height:1.5}';
        html += 'h1{font-size:22px;font-weight:700;margin:0 0 4px;color:#1F2937}';
        html += 'h2{font-size:16px;font-weight:700;color:#1F2937;margin:32px 0 14px;padding-bottom:8px;border-bottom:2px solid #E5E7EB}';
        html += '.period{font-size:12px;color:#6B7280;margin-bottom:28px}';
        html += '.cards{display:flex;gap:16px;margin-bottom:28px;flex-wrap:wrap}';
        html += '.card{flex:1;min-width:140px;padding:16px 20px;border:1px solid #E5E7EB;border-radius:10px;text-align:center}';
        html += '.card-val{font-size:28px;font-weight:700;color:#4F46E5;display:block}';
        html += '.card-label{font-size:11px;color:#6B7280;font-weight:500;text-transform:uppercase;letter-spacing:.5px}';
        html += '.chart-img{width:100%;max-width:900px;margin:0 auto 28px;display:block;border:1px solid #E5E7EB;border-radius:10px}';
        html += 'table{width:100%;border-collapse:collapse;font-size:12px;margin-bottom:20px}';
        html += 'th{text-align:left;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#6B7280;padding:8px 10px;border-bottom:2px solid #E5E7EB;background:#F9FAFB}';
        html += 'td{padding:8px 10px;border-bottom:1px solid #F3F4F6;color:#374151}';
        html += '.text-center{text-align:center} .text-right{text-align:right} .fw-600{font-weight:600}';
        html += '.status{display:inline-block;padding:2px 8px;font-size:10px;font-weight:700;border-radius:12px;text-transform:uppercase}';
        html += '.status-publish{background:#D1FAE5;color:#065F46} .status-draft{background:#FEF3C7;color:#92400E} .status-private{background:#E5E7EB;color:#374151}';
        html += '.bar-track{height:6px;background:#F3F4F6;border-radius:99px;overflow:hidden;display:inline-block;width:80px;vertical-align:middle;margin-right:6px}';
        html += '.bar-fill{height:100%;background:linear-gradient(90deg,#4F46E5,#818CF8);border-radius:99px}';
        html += '.field-card{border:1px solid #E5E7EB;border-radius:8px;padding:16px;margin-bottom:12px;page-break-inside:avoid}';
        html += '.field-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;padding-bottom:8px;border-bottom:1px solid #F3F4F6}';
        html += '.field-label{font-size:13px;font-weight:700;color:#1F2937} .field-type{font-size:10px;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:#9CA3AF;background:#F3F4F6;padding:2px 8px;border-radius:4px}';
        html += '.metrics{display:flex;gap:20px;margin-bottom:10px}';
        html += '.metric-val{font-size:18px;font-weight:700;color:#4F46E5} .metric-label{font-size:10px;color:#9CA3AF}';
        html += '.form-title{font-size:15px;font-weight:700;color:#374151;margin:24px 0 10px;padding-bottom:6px;border-bottom:2px solid #E5E7EB}';
        html += '.no-data{color:#9CA3AF;font-style:italic;text-align:center;padding:20px}';
        html += '@media print{body{padding:20px} .page-break{page-break-before:always}}';
        html += '</style></head><body>';

        // Title
        html += '<h1>Reporte de Estadisticas</h1>';
        if (from && to) {
            html += '<div class="period">Periodo: ' + escHtml(from) + ' — ' + escHtml(to);
            if (formIds.length > 0) {
                html += ' &nbsp;|&nbsp; ' + formIds.length + ' formulario' + (formIds.length !== 1 ? 's' : '') + ' seleccionado' + (formIds.length !== 1 ? 's' : '');
            }
            html += '</div>';
        }

        // Summary cards
        html += '<h2>Resumen general</h2>';
        html += '<div class="cards">';
        html += '<div class="card"><span class="card-val">' + lastDashboardData.total + '</span><span class="card-label">Total</span></div>';
        html += '<div class="card"><span class="card-val">' + lastDashboardData.total_today + '</span><span class="card-label">Hoy</span></div>';
        html += '<div class="card"><span class="card-val">' + lastDashboardData.total_week + '</span><span class="card-label">Semana</span></div>';
        html += '<div class="card"><span class="card-val">' + lastDashboardData.range_total + '</span><span class="card-label">En rango</span></div>';
        html += '</div>';

        // Chart image
        if (chartImage) {
            html += '<h2>Tendencia de respuestas</h2>';
            html += '<img class="chart-img" src="' + chartImage + '" alt="Grafico de tendencia">';
        }

        // Per-form performance table
        var forms = data.forms || [];
        html += '<h2>Rendimiento por formulario</h2>';
        if (forms.length === 0) {
            html += '<p class="no-data">No hay formularios en la seleccion.</p>';
        } else {
            var rangeTotal = parseInt(data.range_total, 10) || 0;
            var rangeDays = 1;
            if (data.date_from && data.date_to) {
                rangeDays = Math.max(1, Math.round((new Date(data.date_to) - new Date(data.date_from)) / 86400000));
            }
            html += '<table><thead><tr><th>Formulario</th><th class="text-center">En rango</th><th class="text-center">Semana</th><th class="text-center">Prom.</th><th>Distribucion</th><th>Ultima respuesta</th><th class="text-center">Estado</th></tr></thead><tbody>';
            forms.forEach(function(f) {
                var pct = rangeTotal > 0 ? ((f.total / rangeTotal) * 100).toFixed(1) : 0;
                var avg = f.total > 0 ? (f.total / rangeDays).toFixed(1) : '0';
                var statusLabel = { publish: 'Activo', draft: 'Borrador', private: 'Privado' };
                html += '<tr>';
                html += '<td class="fw-600">' + escHtml(f.title) + ' <small style="color:#9CA3AF">#' + f.id + '</small></td>';
                html += '<td class="text-center fw-600">' + f.total + '</td>';
                html += '<td class="text-center">' + f.this_week + '</td>';
                html += '<td class="text-center">' + avg + '</td>';
                html += '<td><span class="bar-track"><span class="bar-fill" style="width:' + pct + '%"></span></span>' + pct + '%</td>';
                html += '<td style="font-size:11px;color:#6B7280">' + (f.last_submission ? escHtml(f.last_submission) : '<em>Sin respuestas</em>') + '</td>';
                html += '<td class="text-center"><span class="status status-' + f.status + '">' + (statusLabel[f.status] || f.status) + '</span></td>';
                html += '</tr>';
            });
            html += '</tbody></table>';
        }

        // Field stats
        var fieldStats = data.field_stats || [];
        if (fieldStats.length > 0) {
            html += '<h2 class="page-break">Estadisticas por campo</h2>';
            fieldStats.forEach(function(formGroup) {
                html += '<div class="form-title">' + escHtml(formGroup.form_title) + '</div>';
                formGroup.fields.forEach(function(field) {
                    html += '<div class="field-card">';
                    html += '<div class="field-header"><span class="field-label">' + escHtml(field.label) + '</span><span class="field-type">' + escHtml(field.input_type) + '</span></div>';
                    html += '<div class="metrics">';
                    html += '<div><div class="metric-val">' + field.total + '</div><div class="metric-label">Respuestas</div></div>';
                    html += '<div><div class="metric-val">' + field.unique + '</div><div class="metric-label">Valores unicos</div></div>';
                    if (field.numeric_avg !== null && field.numeric_avg !== undefined) {
                        html += '<div><div class="metric-val">' + field.numeric_avg + '</div><div class="metric-label">Promedio</div></div>';
                    }
                    html += '</div>';

                    var topValues = field.top_values;
                    if (topValues && typeof topValues === 'object') {
                        var keys = Object.keys(topValues);
                        if (keys.length > 0) {
                            var maxCount = topValues[keys[0]] || 1;
                            html += '<table><thead><tr><th>Valor</th><th style="width:40%">Distribucion</th><th class="text-right">Cantidad</th><th class="text-right">%</th></tr></thead><tbody>';
                            keys.forEach(function(val) {
                                var count = topValues[val];
                                var pctVal = field.total > 0 ? ((count / field.total) * 100).toFixed(1) : 0;
                                var barW = maxCount > 0 ? ((count / maxCount) * 100) : 0;
                                html += '<tr><td>' + escHtml(val) + '</td>';
                                html += '<td><span class="bar-track" style="width:100%"><span class="bar-fill" style="width:' + barW + '%"></span></span></td>';
                                html += '<td class="text-right fw-600">' + count + '</td>';
                                html += '<td class="text-right" style="color:#9CA3AF">' + pctVal + '%</td></tr>';
                            });
                            html += '</tbody></table>';
                        }
                    }
                    html += '</div>';
                });
            });
        }

        html += '</body></html>';
        openPrintWindow(html);
    }

    // --- Helpers ---

    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr);
        var day = String(d.getDate()).padStart(2, '0');
        var month = String(d.getMonth() + 1).padStart(2, '0');
        return day + '/' + month + '/' + d.getFullYear() + ' ' +
            String(d.getHours()).padStart(2, '0') + ':' + String(d.getMinutes()).padStart(2, '0');
    }

    function escHtml(str) {
        if (!str) return '';
        return $('<span>').text(str).html();
    }

    function escAttr(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

})(jQuery);
