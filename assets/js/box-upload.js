/**
 * Box Upload Tab JavaScript
 * Matching Dropbox/S3 plugin pattern
 */
jQuery(function ($) {
    // Handler for "Use this file in your Download" button after upload
    $('#edbx_save_link').click(function () {
        $(parent.window.edd_filename).val($(this).data('edbx-fn'));
        $(parent.window.edd_fileurl).val(edbx_url_prefix + $(this).data('edbx-path'));
        parent.window.tb_remove();
    });

    // File size validation before upload
    $('input[name="edbx_file"]').on('change', function () {
        // Safe check if file selected
        if (!this.files || !this.files[0]) {
            return;
        }

        var fileSize = this.files[0].size;
        var maxSize = edbx_max_upload_size;

        // Box max size is typically large, but PHP upload limit matters too.
        // We use the passed WP max upload size.
        if (fileSize > maxSize) {
            var sizeInMB = (maxSize / 1024 / 1024).toFixed(2);
            alert(edbx_i18n.file_size_too_large + ' ' + sizeInMB + 'MB');
            this.value = '';
        }
    });
});
