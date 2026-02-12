/**
 * Box Media Library JavaScript (AJAX Version)
 */
window.EDBXMediaLibrary = (function ($) {
    var $container;

    // Initialize events using delegation (so they persist after content updates)
    function initEvents() {
        $container = $('#edbx-modal-container');

        // Folder Navigation (uses folder_id)
        $(document).on('click', '.edbx-folder-row a, .edbx-breadcrumb-nav a, .edbx-nav-back:not(.disabled)', function (e) {
            e.preventDefault();
            var folderId = $(this).data('folder-id');
            if (folderId !== undefined) {
                loadLibrary({ folder_id: folderId });
            }
        });

        // File Selection
        $(document).on('click', '.save-edbx-file', function (e) {
            e.preventDefault();
            var filename = $(this).data('edbx-filename');
            var fileurl = edbx_url_prefix + $(this).data('edbx-link');
            selectFile(filename, fileurl);
        });

        // Search
        $(document).on('input search', '#edbx-file-search', function () {
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
        $(document).on('keydown', function (e) {
            if ($('#edbx-modal-overlay').is(':visible') && (e.ctrlKey || e.metaKey) && e.keyCode === 70) {
                e.preventDefault();
                $('#edbx-file-search').focus();
            }
        });

        // Toggle upload form
        $(document).on('click', '#edbx-toggle-upload', function () {
            $('#edbx-upload-section').slideToggle(200);
        });
    }

    // Helper to show notice
    function showError(message) {
        $('.edbx-notice').remove();
        var errorHtml = '<div class="edbx-notice warning"><p>' + message + '</p></div>';
        if ($('.edbx-files-table').length) {
            $('.edbx-files-table').before(errorHtml);
        } else {
            $('#edbx-modal-container').prepend(errorHtml);
        }
    }

    /**
     * Load library content via AJAX
     * @param {object|string} params - Either an object with folder_id and/or path, or a string path
     */
    function loadLibrary(params) {
        $container = $('#edbx-modal-container'); // Refresh ref

        // Normalize params
        var ajaxData = {
            action: 'edbx_get_library',
            _wpnonce: edbx_nonce
        };

        if (typeof params === 'object') {
            if (params.folder_id !== undefined && params.folder_id !== '' && params.folder_id !== null) {
                ajaxData.folder_id = params.folder_id;
            }
            if (params.path !== undefined && params.path !== '' && params.path !== null) {
                ajaxData.path = params.path;
            }
        } else if (typeof params === 'string' && params !== '') {
            // String is treated as path for context-aware navigation
            ajaxData.path = params;
        }

        // Check if container is visible (navigation mode)
        if ($container.is(':visible')) {
            // Remove notices
            $container.find('.edbx-notice, .edbx-no-search-results').remove();

            // If table exists, just replace tbody content with skeleton
            var $table = $container.find('.edbx-files-table');
            if ($table.length && window.EDBXModal) {
                $table.addClass('edbx-skeleton-table');
                $table.find('tbody').html(EDBXModal.getSkeletonRows());
            }
        }

        $.ajax({
            url: edbx_ajax_url,
            type: 'POST',
            data: ajaxData,
            success: function (response) {
                if (response.success) {
                    $container.html(response.data.html);
                    // Update upload folder hidden input if folder_id is available
                    if (ajaxData.folder_id) {
                        $('input[name="edbx_folder"]').val(ajaxData.folder_id);
                    }

                    // Notify modal to hide skeleton if it was initial load
                    $(document).trigger('edbx_content_loaded');
                } else {
                    showError('Error: ' + (response.data || 'Unknown error'));
                }
            },
            error: function () {
                showError('Ajax connection error');
            }
        });
    }

    function selectFile(filename, fileurl) {
        if (window.edbx_current_name_input && window.edbx_current_url_input) {
            $(window.edbx_current_name_input).val(filename);
            $(window.edbx_current_url_input).val(fileurl);

            // Close modal
            if (window.EDBXModal) {
                window.EDBXModal.close();
            }
        } else {
            alert(edbx_i18n.file_selected_error);
        }
    }

    // Auto-init on script load
    $(document).ready(function () {
        initEvents();
    });

    return {
        load: loadLibrary,
        reload: function () {
            loadLibrary({ folder_id: '0' });
        }
    };

})(jQuery);
