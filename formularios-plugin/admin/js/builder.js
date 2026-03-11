(function($) {
    'use strict';

    var elements = [];
    var counter = 0;

    var QUESTION_TYPES = {
        text:     'Short Text',
        textarea: 'Long Text',
        email:    'Email',
        number:   'Number',
        date:     'Date',
        select:   'Dropdown',
        radio:    'Multiple Choice',
        checkbox: 'Checkboxes'
    };

    var NEEDS_OPTIONS = ['select', 'radio', 'checkbox'];

    // Initialize
    $(document).ready(function() {
        loadElements();
        bindEvents();
        initSortable();
        updateEmptyState();
    });

    function loadElements() {
        var canvas = $('#formularios-canvas');
        var data = canvas.attr('data-elements');
        if (data) {
            try {
                elements = JSON.parse(data);
                if (!Array.isArray(elements)) elements = [];
            } catch(e) {
                elements = [];
            }
        }
        elements.forEach(function(el) {
            counter++;
            renderElement(el);
        });
    }

    function bindEvents() {
        // Floating menu buttons
        $('#formularios-floating-menu').on('click', '.fmenu-btn', function() {
            var type = $(this).data('type');
            addElement(type);
        });

        // Element actions
        $('#formularios-elements-list').on('click', '.fm-btn-delete', function() {
            var id = $(this).closest('.fm-element').data('id');
            removeElement(id);
        });

        $('#formularios-elements-list').on('click', '.fm-btn-duplicate', function() {
            var id = $(this).closest('.fm-element').data('id');
            duplicateElement(id);
        });

        // Question type change
        $('#formularios-elements-list').on('change', '.fm-question-type-select', function() {
            var $el = $(this).closest('.fm-element');
            var id = $el.data('id');
            var newType = $(this).val();
            updateElementData(id, 'input_type', newType);
            toggleOptionsUI($el, newType);
        });

        // Input changes
        $('#formularios-elements-list').on('input change', '.fm-data-input', function() {
            var $el = $(this).closest('.fm-element');
            var id = $el.data('id');
            var field = $(this).data('field');
            var value = $(this).is(':checkbox') ? $(this).is(':checked') : $(this).val();
            updateElementData(id, field, value);
        });

        // Add option
        $('#formularios-elements-list').on('click', '.fm-add-option-btn', function() {
            var $el = $(this).closest('.fm-element');
            var id = $el.data('id');
            addOption(id, $el);
        });

        // Remove option
        $('#formularios-elements-list').on('click', '.fm-option-remove', function() {
            var $el = $(this).closest('.fm-element');
            var id = $el.data('id');
            $(this).closest('.fm-option-row').remove();
            syncOptions(id, $el);
        });

        // Option input change
        $('#formularios-elements-list').on('input', '.fm-option-input', function() {
            var $el = $(this).closest('.fm-element');
            var id = $el.data('id');
            syncOptions(id, $el);
        });

        // Image upload
        $('#formularios-elements-list').on('click', '.fm-image-upload-btn, .fm-image-change-btn', function() {
            var $el = $(this).closest('.fm-element');
            var id = $el.data('id');
            openMediaUploader(id, $el);
        });

        // Video parse
        $('#formularios-elements-list').on('click', '.fm-video-parse-btn', function() {
            var $el = $(this).closest('.fm-element');
            var id = $el.data('id');
            parseVideoUrl(id, $el);
        });

        // Also parse on Enter key
        $('#formularios-elements-list').on('keypress', '.fm-video-url-input', function(e) {
            if (e.which === 13) {
                e.preventDefault();
                $(this).closest('.fm-element-body').find('.fm-video-parse-btn').click();
            }
        });

        // Before form submit, sync data
        $('form#post').on('submit', function() {
            syncAllData();
        });
    }

    function initSortable() {
        $('#formularios-elements-list').sortable({
            handle: '.fm-element-drag',
            placeholder: 'fm-element ui-sortable-placeholder',
            tolerance: 'pointer',
            start: function(e, ui) {
                ui.item.addClass('is-dragging');
            },
            stop: function(e, ui) {
                ui.item.removeClass('is-dragging');
                reorderElements();
            }
        });
    }

    // --- Element CRUD ---

    function addElement(type) {
        counter++;
        var id = 'el_' + Date.now() + '_' + counter;
        var el = createElementData(type, id);
        elements.push(el);
        renderElement(el);
        updateEmptyState();
        syncAllData();

        // Scroll to new element
        var $new = $('[data-id="' + id + '"]');
        if ($new.length) {
            $('html, body').animate({ scrollTop: $new.offset().top - 100 }, 300);
        }
    }

    function createElementData(type, id) {
        var base = { type: type, id: id };
        switch(type) {
            case 'question':
                return $.extend(base, {
                    label: '',
                    input_type: 'text',
                    required: false,
                    placeholder: '',
                    options: []
                });
            case 'title_desc':
                return $.extend(base, { title: '', description: '' });
            case 'image':
                return $.extend(base, { image_url: '', image_id: 0, caption: '' });
            case 'video':
                return $.extend(base, { video_url: '', caption: '' });
            case 'section':
                return $.extend(base, { title: '', description: '' });
        }
        return base;
    }

    function removeElement(id) {
        $('[data-id="' + id + '"]').fadeOut(200, function() {
            $(this).remove();
            elements = elements.filter(function(el) { return el.id !== id; });
            updateEmptyState();
            syncAllData();
        });
    }

    function duplicateElement(id) {
        var original = findElement(id);
        if (!original) return;
        counter++;
        var newId = 'el_' + Date.now() + '_' + counter;
        var clone = $.extend(true, {}, original, { id: newId });

        // Insert after original
        var idx = elements.findIndex(function(el) { return el.id === id; });
        elements.splice(idx + 1, 0, clone);

        var $original = $('[data-id="' + id + '"]');
        var $html = $(buildElementHTML(clone));
        $original.after($html);

        updateEmptyState();
        syncAllData();
    }

    function reorderElements() {
        var newOrder = [];
        $('#formularios-elements-list .fm-element').each(function() {
            var id = $(this).data('id');
            var el = findElement(id);
            if (el) newOrder.push(el);
        });
        elements = newOrder;
        syncAllData();
    }

    function findElement(id) {
        return elements.find(function(el) { return el.id === id; });
    }

    function updateElementData(id, field, value) {
        var el = findElement(id);
        if (el) {
            el[field] = value;
            syncAllData();
        }
    }

    // --- Rendering ---

    function renderElement(el) {
        var html = buildElementHTML(el);
        $('#formularios-elements-list').append(html);
    }

    function buildElementHTML(el) {
        var typeLabel = {
            question: formularios.i18n.question,
            title_desc: formularios.i18n.title_desc,
            image: formularios.i18n.image,
            video: formularios.i18n.video,
            section: formularios.i18n.section
        };

        var html = '<div class="fm-element type-' + el.type + '" data-id="' + el.id + '">';

        // Header
        html += '<div class="fm-element-header">';
        html += '<span class="fm-element-drag" title="Drag to reorder">';
        html += '<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></svg>';
        html += '</span>';
        html += '<span class="fm-element-type-badge type-' + el.type + '">' + typeLabel[el.type] + '</span>';
        html += '<div class="fm-element-actions">';
        html += '<button type="button" class="fm-btn-duplicate" title="Duplicate">&#x2398; Duplicate</button>';
        html += '<button type="button" class="fm-btn-delete" title="Delete">&times; Delete</button>';
        html += '</div>';
        html += '</div>';

        // Body
        html += '<div class="fm-element-body">';
        html += buildElementBody(el);
        html += '</div>';

        html += '</div>';
        return html;
    }

    function buildElementBody(el) {
        var html = '';
        switch(el.type) {
            case 'question':
                html += '<input type="text" class="fm-input fm-data-input" data-field="label" value="' + escAttr(el.label) + '" placeholder="' + escAttr(formularios.i18n.placeholder) + '" />';
                html += '<div class="fm-question-type-row">';
                html += '<select class="fm-select fm-question-type-select fm-data-input" data-field="input_type">';
                for (var key in QUESTION_TYPES) {
                    html += '<option value="' + key + '"' + (el.input_type === key ? ' selected' : '') + '>' + QUESTION_TYPES[key] + '</option>';
                }
                html += '</select>';
                html += '<input type="text" class="fm-input fm-data-input" data-field="placeholder" value="' + escAttr(el.placeholder) + '" placeholder="Placeholder text..." style="flex:1" />';
                html += '<label class="fm-required-toggle"><input type="checkbox" class="fm-data-input" data-field="required"' + (el.required ? ' checked' : '') + ' /> ' + formularios.i18n.required + '</label>';
                html += '</div>';

                // Options area
                var showOpts = NEEDS_OPTIONS.indexOf(el.input_type) !== -1;
                html += '<div class="fm-options-area" style="' + (showOpts ? '' : 'display:none') + '">';
                html += '<label class="fm-input-label">' + formularios.i18n.add_option + 's</label>';
                html += '<div class="fm-options-list">';
                if (el.options && el.options.length) {
                    el.options.forEach(function(opt, i) {
                        html += buildOptionRow(opt, i);
                    });
                }
                html += '</div>';
                html += '<button type="button" class="fm-add-option-btn">+ ' + formularios.i18n.add_option + '</button>';
                html += '</div>';
                break;

            case 'title_desc':
                html += '<input type="text" class="fm-input fm-data-input" data-field="title" value="' + escAttr(el.title) + '" placeholder="' + escAttr(formularios.i18n.title_ph) + '" style="font-size:16px;font-weight:600" />';
                html += '<textarea class="fm-textarea fm-data-input" data-field="description" placeholder="' + escAttr(formularios.i18n.desc_ph) + '" rows="3">' + escHtml(el.description) + '</textarea>';
                break;

            case 'image':
                if (el.image_url) {
                    html += '<div class="fm-image-preview"><img src="' + escAttr(el.image_url) + '" />';
                    html += '<button type="button" class="fm-image-change-btn" style="margin-top:8px">Change Image</button>';
                    html += '</div>';
                } else {
                    html += '<div class="fm-image-upload-btn">';
                    html += '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><path d="M21 15l-5-5L5 21"/></svg>';
                    html += '<span>' + formularios.i18n.select_image + '</span>';
                    html += '</div>';
                }
                html += '<input type="text" class="fm-input fm-data-input" data-field="caption" value="' + escAttr(el.caption) + '" placeholder="Image caption (optional)" />';
                break;

            case 'video':
                html += '<div class="fm-video-input-row">';
                html += '<input type="text" class="fm-input fm-video-url-input fm-data-input" data-field="video_url" value="' + escAttr(el.video_url) + '" placeholder="' + escAttr(formularios.i18n.youtube_search) + '" />';
                html += '<button type="button" class="fm-video-parse-btn">Embed</button>';
                html += '</div>';
                if (el.video_url) {
                    var vid = extractYouTubeId(el.video_url);
                    if (vid) {
                        html += '<div class="fm-video-preview"><iframe src="https://www.youtube.com/embed/' + vid + '" allowfullscreen></iframe></div>';
                    }
                }
                html += '<input type="text" class="fm-input fm-data-input" data-field="caption" value="' + escAttr(el.caption) + '" placeholder="Video caption (optional)" />';
                break;

            case 'section':
                html += '<input type="text" class="fm-input fm-data-input" data-field="title" value="' + escAttr(el.title) + '" placeholder="' + escAttr(formularios.i18n.section_ph) + '" style="font-size:16px;font-weight:600" />';
                html += '<input type="text" class="fm-input fm-data-input" data-field="description" value="' + escAttr(el.description) + '" placeholder="Section description (optional)" />';
                break;
        }
        return html;
    }

    function buildOptionRow(value, index) {
        var html = '<div class="fm-option-row">';
        html += '<input type="text" class="fm-input fm-option-input" value="' + escAttr(value) + '" placeholder="' + formularios.i18n.option_ph + ' ' + (index + 1) + '" />';
        html += '<button type="button" class="fm-option-remove" title="Remove">&times;</button>';
        html += '</div>';
        return html;
    }

    // --- Options ---

    function addOption(id, $el) {
        var el = findElement(id);
        if (!el) return;
        if (!el.options) el.options = [];
        var idx = el.options.length;
        el.options.push('');
        $el.find('.fm-options-list').append(buildOptionRow('', idx));
        syncAllData();
    }

    function syncOptions(id, $el) {
        var el = findElement(id);
        if (!el) return;
        var opts = [];
        $el.find('.fm-option-input').each(function() {
            opts.push($(this).val());
        });
        el.options = opts;
        syncAllData();
    }

    function toggleOptionsUI($el, inputType) {
        var $area = $el.find('.fm-options-area');
        if (NEEDS_OPTIONS.indexOf(inputType) !== -1) {
            $area.slideDown(200);
        } else {
            $area.slideUp(200);
        }
    }

    // --- Media ---

    function openMediaUploader(id, $el) {
        var frame = wp.media({
            title: formularios.i18n.select_image,
            multiple: false,
            library: { type: 'image' }
        });

        frame.on('select', function() {
            var attachment = frame.state().get('selection').first().toJSON();
            var el = findElement(id);
            if (el) {
                el.image_url = attachment.url;
                el.image_id = attachment.id;
                // Re-render body
                $el.find('.fm-element-body').html(buildElementBody(el));
                syncAllData();
            }
        });

        frame.open();
    }

    // --- Video ---

    function parseVideoUrl(id, $el) {
        var url = $el.find('.fm-video-url-input').val().trim();
        if (!url) return;

        var vid = extractYouTubeId(url);
        if (vid) {
            var el = findElement(id);
            if (el) {
                el.video_url = url;
                $el.find('.fm-video-preview').remove();
                $el.find('.fm-video-input-row').after(
                    '<div class="fm-video-preview"><iframe src="https://www.youtube.com/embed/' + vid + '" allowfullscreen></iframe></div>'
                );
                syncAllData();
            }
        } else {
            alert('Please enter a valid YouTube URL');
        }
    }

    function extractYouTubeId(url) {
        var match = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([a-zA-Z0-9_-]{11})/);
        return match ? match[1] : null;
    }

    // --- Sync ---

    function syncAllData() {
        // Sync all input values to elements array
        $('#formularios-elements-list .fm-element').each(function() {
            var id = $(this).data('id');
            var el = findElement(id);
            if (!el) return;

            $(this).find('.fm-data-input').each(function() {
                var field = $(this).data('field');
                if (!field) return;
                if ($(this).is(':checkbox')) {
                    el[field] = $(this).is(':checked');
                } else {
                    el[field] = $(this).val();
                }
            });
        });

        $('#formularios-data').val(JSON.stringify(elements));
    }

    function updateEmptyState() {
        if (elements.length > 0) {
            $('#formularios-empty-state').hide();
            $('#formularios-canvas').addClass('has-elements');
        } else {
            $('#formularios-empty-state').show();
            $('#formularios-canvas').removeClass('has-elements');
        }
    }

    // --- Helpers ---

    function escAttr(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/'/g,'&#39;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function escHtml(str) {
        if (!str) return '';
        return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

})(jQuery);
