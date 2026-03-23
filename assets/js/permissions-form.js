/**
 * Schoolbooth Permissions Form Handler
 * Handles form submission via AJAX with proper validation and feedback.
 */

(function($) {
    'use strict';
    $(document).ready(function() {
        const cfg = window.schoolbooth_form_vars || {};
        const $form = $('#schoolbooth-permissions-form');
        if ($form.length === 0) {
            return;
        }

        const $submitBtn = $('#submit-consent');
        const $statusDiv = $('.form-status');
        const $formErrors = $form.find('.form-error');

        $submitBtn.on('click', function() {
            if ($submitBtn.attr('disabled')) {
                return false;
            }
        });

        $form.find('input').on('change', function() {
            $(this).closest('.form-group').find('.form-error').hide();
        });

        $form.find('input[type="checkbox"]').on('change', function() {
            $(this).closest('.checkbox-group').find('.form-error').hide();
        });

        $form.on('submit', function(e) {
            e.preventDefault();

            $formErrors.hide();
            $statusDiv.hide().removeClass('success error loading');

            if (!validateForm()) {
                return false;
            }

            $submitBtn.attr('disabled', true);
            $statusDiv.show().addClass('loading').text(cfg.processing || 'Processing...');

            const formData = new FormData($form.get(0));

            $.ajax({
                url: cfg.ajaxurl || '/wp-admin/admin-ajax.php',
                type: 'POST',
                data: formData,
                processData: false,
                contentType: false,
                timeout: 30000,
                success: function(response) {
                    if (response && response.success) {
                        $statusDiv
                            .removeClass('loading')
                            .addClass('success')
                            .text((cfg.thanks || 'Thank you! Your consent has been recorded.') + ' ' + (cfg.redirecting || 'Redirecting to your photos...'));

                        $form.fadeOut(300, function() {
                            setTimeout(function() {
                                window.location.reload();
                            }, 800);
                        });
                    } else {
                        handleErrorResponse(response || {});
                    }
                },
                error: function(xhr, status) {
                    let message = cfg.generic_error || 'An error occurred while processing your form. Please try again.';
                    if (status === 'timeout') {
                        message = cfg.timeout_error || message;
                    } else if (xhr && xhr.status === 429) {
                        message = cfg.rate_limit_error || message;
                    }
                    $statusDiv.removeClass('loading').addClass('error').text(message);
                },
                complete: function() {
                    $submitBtn.attr('disabled', false);
                }
            });

            return false;
        });

        function validateForm() {
            let isValid = true;

            const $firstName = $form.find('#first_name');
            if (!$firstName.val() || $firstName.val().trim().length < 2) {
                showFieldError($firstName, cfg.first_name_error || 'First name is required and must be at least 2 characters');
                isValid = false;
            }

            const $lastName = $form.find('#last_name');
            if (!$lastName.val() || $lastName.val().trim().length < 2) {
                showFieldError($lastName, cfg.last_name_error || 'Last name is required and must be at least 2 characters');
                isValid = false;
            }

            const $email = $form.find('#email');
            const email = ($email.val() || '').trim();
            if (!email || !isValidEmail(email)) {
                showFieldError($email, cfg.email_error || 'A valid email address is required');
                isValid = false;
            }

            const $consent = $form.find('#consent_checkbox');
            if (!$consent.is(':checked')) {
                const $error = $consent.closest('.checkbox-group').find('.form-error');
                $error.text(cfg.consent_error || 'You must agree to the release form').show();
                isValid = false;
            }

            return isValid;
        }

        function showFieldError($field, message) {
            const $group = $field.closest('.form-group');
            $group.find('.form-error').text(message).show();
            if (document.activeElement !== $field.get(0)) {
                $field.trigger('focus');
            }
        }

        function isValidEmail(email) {
            const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return emailRegex.test(email);
        }

        function handleErrorResponse(response) {
            const data = response.data || {};

            if (data.errors && typeof data.errors === 'object') {
                $formErrors.hide();
                Object.keys(data.errors).forEach(function(field) {
                    const $field = $form.find('#' + field);
                    if ($field.length) {
                        showFieldError($field, data.errors[field]);
                    }
                });
            }

            const message = data.message || cfg.submit_error || 'Failed to submit form. Please try again.';
            $statusDiv.removeClass('loading').addClass('error').text(message);
        }
    });
})(jQuery);



