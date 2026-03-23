jQuery(document).ready(function($) {
    if (typeof window.ptasbDownloadRefreshScheduled === 'undefined') {
        window.ptasbDownloadRefreshScheduled = false;
    }

    // Refresh to update counters after download click.
    $(document)
        .off('click.ptasbDownload', '.download-btn')
        .on('click.ptasbDownload', '.download-btn', function() {
        var $btn = $(this);
        if ($btn.data('refresh-pending') || window.ptasbDownloadRefreshScheduled) {
            return;
        }

        window.ptasbDownloadRefreshScheduled = true;
        $btn.data('refresh-pending', true);
        setTimeout(function() {
            window.location.reload();
        }, 1800);
    });

    // Print the selected photo from a temporary window.
    $(document)
        .off('click.ptasbPrint', '.print-btn')
        .on('click.ptasbPrint', '.print-btn', function() {
        var imageUrl = $(this).data('image-url');
        var label = $(this).data('label') || 'Photo';
        if (!imageUrl) {
            return;
        }

        var w = window.open('', '_blank');
        if (!w) {
            alert('Unable to open print window. Please allow pop-ups and try again.');
            return;
        }

        w.document.write('<!doctype html><html><head><title>' + label + '</title><style>body{margin:0;padding:16px;font-family:Arial,sans-serif;text-align:center;}img{max-width:100%;height:auto;}</style></head><body>');
        w.document.write('<h3>' + label + '</h3>');
        w.document.write('<img src="' + imageUrl + '" alt="' + label + '">');
        w.document.write('<script>window.onload=function(){window.print();setTimeout(function(){window.close();},300);};<\/script>');
        w.document.write('</body></html>');
        w.document.close();
    });

    // Copy share URL to clipboard.
    $(document)
        .off('click.ptasbCopy', '.copy-link-btn')
        .on('click.ptasbCopy', '.copy-link-btn', function() {
        var shareUrl = $(this).data('share-url');
        if (!shareUrl) {
            return;
        }

        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(shareUrl).then(function() {
                alert('Share link copied.');
            }).catch(function() {
                prompt('Copy this link:', shareUrl);
            });
        } else {
            prompt('Copy this link:', shareUrl);
        }
    });

    // Delete single photo
    $(document)
        .off('click.ptasbDelete', '.delete-btn')
        .on('click.ptasbDelete', '.delete-btn', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $(this);
        if ($btn.data('deleting')) {
            return;
        }

        if (!confirm(schoolbooth_vars.delete_confirm)) return;
        
        var $card = $btn.closest('.photo-card');
        var file = $btn.data('file');
        var code = $btn.data('code');
        var deleteToken = $btn.data('delete-token');
        var deleteExpires = $btn.data('delete-expires');

        $btn.data('deleting', true).prop('disabled', true);
        
        $.post(schoolbooth_vars.ajaxurl, {
            action: 'schoolbooth_delete_photo',
            file: file,
            code: code,
            delete_token: deleteToken,
            delete_expires: deleteExpires,
            security: schoolbooth_vars.nonce
        }, function(response) {
            if (response.success) {
                $card.fadeOut(300, function() {
                    $(this).remove();
                    if ($('.photo-card').length === 0) {
                        $('.photo-grid').after('<p class="no-photos">' + schoolbooth_vars.no_photos + '</p>');
                    }
                });
            } else {
                alert(response.data);
            }
        }).fail(function() {
            alert(schoolbooth_vars.delete_error);
        }).always(function() {
            $btn.data('deleting', false).prop('disabled', false);
        });
    });
});

