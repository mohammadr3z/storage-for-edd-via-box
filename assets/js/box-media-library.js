/**
 * Box Media Library JavaScript
 */
jQuery(function ($) {
    // File selection handler
    $('.save-edbx-file').click(function () {
        var filename = $(this).data('edbx-filename');
        var fileurl = edbx_url_prefix + $(this).data('edbx-link');

        // Support for new modal Browse button
        if (parent.window && parent.window.edbx_current_name_input && parent.window.edbx_current_url_input) {
            parent.window.edbx_current_name_input.val(filename);
            parent.window.edbx_current_url_input.val(fileurl);

            // Close the modal
            if (parent.EDBXModal) {
                parent.EDBXModal.close();
            }
        }

        return false;
    });

    // Search functionality for Box files
    $('#edbx-file-search').on('input search', function () {
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
