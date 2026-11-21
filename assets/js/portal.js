/**
 * WPForgeHub — Customer Portal Client Controller
 */
(function($) {
    'use strict';

    $(document).ready(function() {

        // 1. Passwordless Magic Link Login Handler
        $('#wpfh_cp_send_magic_btn').on('click', function(e) {
            e.preventDefault();

            var email = $('#wpfh_cp_magic_email').val().trim();
            var $feedback = $('#wpfh_cp_magic_feedback');
            var $btn = $(this);

            if (!email) {
                $feedback.css('color', '#ef4444').text('Please enter your account email address.').show();
                return;
            }

            $btn.prop('disabled', true).text('Sending link...');
            $feedback.hide();

            $.post(wpfhCpData.ajax_url, {
                action: 'wpfh_cp_send_magic_link',
                email: email,
                security: wpfhCpData.magic_nonce
            }, function(res) {
                $btn.prop('disabled', false).text('Send Magic Link');
                if (res && res.success) {
                    $feedback.css('color', '#10b981').text(res.data.message).show();
                    $('#wpfh_cp_magic_email').val('');
                } else {
                    var err = (res && res.data && res.data.message) ? res.data.message : 'Error sending link. Please try again.';
                    $feedback.css('color', '#ef4444').text(err).show();
                }
            }).fail(function() {
                $btn.prop('disabled', false).text('Send Magic Link');
                $feedback.css('color', '#ef4444').text('Network error. Please try again.').show();
            });
        });

        // 2. 1-Click License Key Copy
        $(document).on('click', '.wpfh-cp-copy-key-btn', function(e) {
            e.preventDefault();
            var key = $(this).data('key');
            var $btn = $(this);

            if (navigator.clipboard && key) {
                navigator.clipboard.writeText(key).then(function() {
                    var originalText = $btn.text();
                    $btn.text(wpfhCpData.i18n.copied || 'Copied!').css('background', '#10b981');
                    setTimeout(function() {
                        $btn.text(originalText).css('background', '#2563eb');
                    }, 2500);
                });
            } else if (key) {
                // Fallback prompt
                prompt('Copy your license key:', key);
            }
        });

    });
})(jQuery);
