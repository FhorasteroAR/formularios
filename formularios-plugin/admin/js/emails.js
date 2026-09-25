(function($) {
    'use strict';

    var $lastInput = null;

    $(document).ready(function() {
        var $wrap = $('.fm-emails');
        if (!$wrap.length) return;

        // --- Solapas ---
        $('.fm-tabs').on('click', '.fm-tab', function() {
            var tab = $(this).data('tab');
            $('.fm-tab').removeClass('is-active');
            $(this).addClass('is-active');
            $('.fm-tab-panel').removeClass('is-active').filter('[data-panel="' + tab + '"]').addClass('is-active');
            if (tab === 'emails') renderFieldChips();
        });

        // --- Variables ---
        $wrap.on('focus', 'input[type="text"].fm-email-input, textarea.fm-email-input', function() {
            $lastInput = $(this);
        });

        $wrap.on('mousedown', '.fm-chip', function(e) {
            // Evita que el campo pierda el foco antes de insertar.
            e.preventDefault();
        });

        $wrap.on('click', '.fm-chip', function() {
            if (!$lastInput) return;
            insertAtCursor($lastInput[0], $(this).data('var'));
        });

        // --- Vista previa ---
        $wrap.on('click', '.fm-email-preview-btn', function() {
            var $card = $(this).closest('.fm-email-card');
            var $btn = $(this).prop('disabled', true);
            var config = {};

            $card.find('.fm-email-input').each(function() {
                var key = $(this).data('key');
                if ($(this).is(':checkbox')) {
                    if ($(this).is(':checked')) config[key] = '1';
                } else {
                    config[key] = $(this).val();
                }
            });

            $.post(formularios.ajax_url, {
                action: 'formularios_email_preview',
                nonce: formularios.nonce,
                form_id: $('#post_ID').val(),
                which: $card.data('email'),
                title: $('#title').val() || '',
                elements: $('#formularios-data').val(),
                config: config
            }).done(function(res) {
                if (!res || !res.success) {
                    alert((res && res.data) || 'No se pudo generar la vista previa.');
                    return;
                }
                var $modal = $wrap.find('.fm-email-modal');
                $modal.find('.fm-email-modal-subject').text(res.data.subject);
                $modal.find('.fm-email-modal-frame').attr('srcdoc', res.data.html);
                $modal.prop('hidden', false);
            }).fail(function() {
                alert('No se pudo generar la vista previa.');
            }).always(function() {
                $btn.prop('disabled', false);
            });
        });

        $wrap.on('click', '.fm-email-modal-close', closeModal);
        $wrap.on('click', '.fm-email-modal', function(e) {
            if (e.target === this) closeModal();
        });
        $(document).on('keydown', function(e) {
            if (e.key === 'Escape') closeModal();
        });

        function closeModal() {
            $wrap.find('.fm-email-modal').prop('hidden', true);
        }
    });

    /**
     * Un chip por cada pregunta del formulario (incluye las aun no guardadas).
     */
    function renderFieldChips() {
        var elements = [];
        try {
            elements = JSON.parse($('#formularios-data').val() || '[]');
        } catch (e) {}

        var $box = $('.fm-emails-field-chips').empty();
        (elements || []).forEach(function(el) {
            if (el.type !== 'question' || !el.id) return;
            $('<button type="button" class="fm-chip fm-chip-field"></button>')
                .attr('data-var', '{campo:' + el.id + '}')
                .attr('title', '{campo:' + el.id + '}')
                .text(el.label || 'Pregunta sin titulo')
                .appendTo($box);
        });
    }

    function insertAtCursor(input, text) {
        var start = input.selectionStart || 0;
        var end = input.selectionEnd || 0;
        input.value = input.value.slice(0, start) + text + input.value.slice(end);
        input.selectionStart = input.selectionEnd = start + text.length;
        input.focus();
    }

})(jQuery);
