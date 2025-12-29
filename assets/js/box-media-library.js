/**
 * Box Media Library JavaScript
 * Exactly matching working Dropbox/S3 plugin pattern
 */
jQuery(function ($) {
    // File selection handler
    $('.save-edbx-file').click(function () {
        var filename = $(this).data('edbx-filename');
        var fileurl = edbx_url_prefix + $(this).data('edbx-link');
        var success = false;

        // Try to use EDD's global variables set by the upload button
        if (parent.window && parent.window !== window) {
            if (parent.window.edd_filename && parent.window.edd_fileurl) {
                $(parent.window.edd_filename).val(filename);
                $(parent.window.edd_fileurl).val(fileurl);
                success = true;
                try { parent.window.tb_remove(); } catch (e) { parent.window.tb_remove(); }
            }
        } else {
            if (window.edd_filename && window.edd_fileurl) {
                $(window.edd_filename).val(filename);
                $(window.edd_fileurl).val(fileurl);
                success = true;
            }
        }

        // Fallback: try to find EDD file inputs directly
        if (!success) {
            var $filenameInput = $('input[name*="edd_download_files"][name*="[name]"]').last();
            var $fileurlInput = $('input[name*="edd_download_files"][name*="[file]"]').last();

            if ($filenameInput.length && $fileurlInput.length) {
                $filenameInput.val(filename);
                $fileurlInput.val(fileurl);
                success = true;
            }
        }

        if (success) {
            alert(edbx_i18n.file_selected_success);
        } else {
            alert(edbx_i18n.file_selected_error);
        }

        return false;
    });

    // Search functionality
    $('#edbx-file-search').on('input', function () {
        var searchTerm = $(this).val().toLowerCase();
        var $fileRows = $('.edbx-files-table tbody tr');
        var visibleCount = 0;

        $fileRows.each(function () {
            var $row = $(this);
            var fileName = $row.find('.file-name').text().toLowerCase();

            if (fileName.indexOf(searchTerm) !== -1) {
                $row.show();
                visibleCount++;
            } else {
                $row.hide();
            }
        });

        // Show/hide "no results" message
        var $noResults = $('.edbx-no-search-results');
        if (visibleCount === 0 && searchTerm.length > 0) {
            if ($noResults.length === 0) {
                $('.edbx-files-table').after('<div class="edbx-no-search-results" style="padding: 20px; text-align: center; color: #666; font-style: italic;">No files found matching your search.</div>');
            } else {
                $noResults.show();
            }
        } else {
            $noResults.hide();
        }
    });

    // Clear search
    $('#edbx-clear-search').click(function () {
        $('#edbx-file-search').val('').trigger('input');
    });

    // Keyboard shortcut for search
    $(document).keydown(function (e) {
        if ((e.ctrlKey || e.metaKey) && e.keyCode === 70) {
            e.preventDefault();
            $('#edbx-file-search').focus();
        }
    });

    // Toggle upload form
    $('#edbx-toggle-upload').click(function () {
        $('#edbx-upload-section').slideToggle(200);
    });
});
