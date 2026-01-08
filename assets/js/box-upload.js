jQuery(function ($) {
    $('#edbx_save_link').click(function () {
        var filename = $(this).data('edbx-fn');
        var path = $(this).data('edbx-path');
        // Box paths are IDs, but our system expects prefix
        var fileurl = edbx_url_prefix + path;

        if (parent.window && parent.window.edbx_current_name_input && parent.window.edbx_current_url_input) {
            parent.window.edbx_current_name_input.val(filename);
            parent.window.edbx_current_url_input.val(fileurl);

            if (parent.EDBXModal) {
                parent.EDBXModal.close();
            }
        }
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
