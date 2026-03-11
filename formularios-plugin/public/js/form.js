(function($) {
    'use strict';

    $(document).ready(function() {
        $('.formularios-form-wrap').each(function() {
            initForm($(this));
        });
    });

    function initForm($wrap) {
        var $form = $wrap.find('.formularios-form');
        var $sections = $form.find('.fm-section');
        var hasSections = $sections.length > 0;
        var currentSection = 1;
        var totalSections = $sections.length;

        if (hasSections) {
            updateProgress();
            updateNavButtons();
        }

        // Next button
        $wrap.on('click', '.fm-btn-next', function() {
            if (!validateSection(currentSection)) return;
            currentSection++;
            showSection(currentSection);
            updateProgress();
            updateNavButtons();
            scrollToTop();
        });

        // Previous button
        $wrap.on('click', '.fm-btn-prev', function() {
            currentSection--;
            showSection(currentSection);
            updateProgress();
            updateNavButtons();
            scrollToTop();
        });

        // Form submit
        $form.on('submit', function(e) {
            e.preventDefault();

            if (hasSections && !validateSection(currentSection)) return;
            if (!hasSections && !validateAll()) return;

            var $submitBtn = $form.find('.fm-btn-submit');
            $submitBtn.prop('disabled', true).text('Sending...');

            var formData = $form.serialize();
            formData += '&action=formularios_submit';

            $.ajax({
                url: formulariosFront.ajax_url,
                type: 'POST',
                data: formData,
                success: function(response) {
                    if (response.success) {
                        $form.fadeOut(300, function() {
                            $wrap.find('.fm-success-message').fadeIn(300);
                        });
                    } else {
                        if (response.data && response.data.validation) {
                            showValidationErrors(response.data.validation);
                        }
                        $submitBtn.prop('disabled', false).text($submitBtn.data('original-text') || 'Submit');
                    }
                },
                error: function() {
                    alert('An error occurred. Please try again.');
                    $submitBtn.prop('disabled', false).text($submitBtn.data('original-text') || 'Submit');
                }
            });
        });

        // Store original button text
        var $submitBtn = $form.find('.fm-btn-submit');
        $submitBtn.data('original-text', $submitBtn.text());

        // Clear error on input
        $form.on('input change', '.fm-control, .fm-choice input', function() {
            $(this).closest('.fm-field').removeClass('has-error')
                   .find('.fm-error-msg').hide().text('');
        });

        // --- Section Navigation ---

        function showSection(num) {
            $sections.removeClass('active');
            $sections.filter('[data-section="' + num + '"]').addClass('active');
        }

        function updateProgress() {
            var pct = (currentSection / totalSections) * 100;
            $form.find('.fm-progress-fill').css('width', pct + '%');
        }

        function updateNavButtons() {
            var $prev = $wrap.find('.fm-btn-prev');
            var $next = $wrap.find('.fm-btn-next');
            var $submit = $wrap.find('.fm-btn-submit');

            $prev.toggle(currentSection > 1);
            $next.toggle(currentSection < totalSections);
            $submit.toggle(currentSection === totalSections);
        }

        // --- Validation ---

        function validateSection(num) {
            var $section = $sections.filter('[data-section="' + num + '"]');
            return validateFields($section.find('.fm-field'));
        }

        function validateAll() {
            return validateFields($form.find('.fm-field'));
        }

        function validateFields($fields) {
            var valid = true;
            $fields.each(function() {
                var $field = $(this);
                var $control = $field.find('.fm-control');
                var $errorMsg = $field.find('.fm-error-msg');
                var isRequired = $control.is('[required]') || $field.find('input[required]').length > 0;

                $field.removeClass('has-error');
                $errorMsg.hide().text('');

                if (!isRequired) return;

                var value = '';
                var type = $field.data('type');

                if (type === 'radio') {
                    value = $field.find('input[type="radio"]:checked').val() || '';
                } else if (type === 'checkbox') {
                    value = $field.find('input[type="checkbox"]:checked').length > 0 ? 'yes' : '';
                } else {
                    value = $control.val() || '';
                }

                if (value.trim() === '') {
                    $field.addClass('has-error');
                    $errorMsg.text('This field is required.').show();
                    valid = false;
                    return;
                }

                // Email validation
                if (type === 'email' && value) {
                    var emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
                    if (!emailRegex.test(value)) {
                        $field.addClass('has-error');
                        $errorMsg.text('Please enter a valid email.').show();
                        valid = false;
                    }
                }
            });
            return valid;
        }

        function showValidationErrors(errors) {
            for (var name in errors) {
                var $input = $form.find('[name="' + name + '"], [name="' + name + '[]"]').first();
                var $field = $input.closest('.fm-field');
                $field.addClass('has-error');
                $field.find('.fm-error-msg').text(errors[name]).show();
            }
        }

        function scrollToTop() {
            $('html, body').animate({
                scrollTop: $wrap.offset().top - 40
            }, 300);
        }
    }

})(jQuery);
