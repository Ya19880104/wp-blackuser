jQuery(document).ready(function($) {
    $('input[name="user[]"]').on('change', function() {
        var userId = $(this).val();
        var isChecked = $(this).is(':checked');

        $.ajax({
            url: ys_black_user_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'ys_black_user_update',
                user_id: userId,
                is_blocked: isChecked,
                nonce: ys_black_user_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    show_notification(response.data);
                } else {
                    show_notification(response.data);
                }
            },
            error: function() {
                show_notification('發生錯誤');
            }
        });
    });

    function show_notification(message) {
        var notification = $('<div class="ys-notification">' + message + '</div>');
        $('body').append(notification);
        setTimeout(function() {
            notification.fadeOut('slow', function() {
                $(this).remove();
            });
        }, 3000);
    }
});
