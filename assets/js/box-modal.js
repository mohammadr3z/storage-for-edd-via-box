/**
 * EDBX Modal JS
 */
var EDBXModal = (function ($) {
    var $modal, $overlay, $container, $closeBtn, $skeleton, $title;

    // Skeleton rows - shared with EDBXMediaLibrary
    var skeletonRowsHtml =
        '<tr><td><div class="edbx-skeleton-cell" style="width: 70%;"></div></td><td><div class="edbx-skeleton-cell" style="width: 60%;"></div></td><td><div class="edbx-skeleton-cell" style="width: 80%;"></div></td><td><div class="edbx-skeleton-cell" style="width: 70%;"></div></td></tr>' +
        '<tr><td><div class="edbx-skeleton-cell" style="width: 55%;"></div></td><td><div class="edbx-skeleton-cell" style="width: 50%;"></div></td><td><div class="edbx-skeleton-cell" style="width: 75%;"></div></td><td><div class="edbx-skeleton-cell" style="width: 70%;"></div></td></tr>' +
        '<tr><td><div class="edbx-skeleton-cell" style="width: 80%;"></div></td><td><div class="edbx-skeleton-cell" style="width: 45%;"></div></td><td><div class="edbx-skeleton-cell" style="width: 70%;"></div></td><td><div class="edbx-skeleton-cell" style="width: 70%;"></div></td></tr>' +
        '<tr><td><div class="edbx-skeleton-cell" style="width: 65%;"></div></td><td><div class="edbx-skeleton-cell" style="width: 55%;"></div></td><td><div class="edbx-skeleton-cell" style="width: 85%;"></div></td><td><div class="edbx-skeleton-cell" style="width: 70%;"></div></td></tr>';

    function init() {
        if ($('#edbx-modal-overlay').length) {
            return;
        }

        // Skeleton HTML structure - uses real table with skeleton rows
        var skeletonHtml =
            '<div class="edbx-skeleton-loader">' +
            '<div class="edbx-header-row">' +
            '<h3 class="media-title">' + (typeof edbx_browse_button !== 'undefined' && edbx_browse_button.i18n_select_file || 'Select a file from Box') + '</h3>' +
            '<div class="edbx-header-buttons">' +
            '<button type="button" class="button button-primary" id="edbx-toggle-upload">' + (typeof edbx_browse_button !== 'undefined' && edbx_browse_button.i18n_upload || 'Upload File') + '</button>' +
            '</div>' +
            '</div>' +
            '<div class="edbx-breadcrumb-nav edbx-skeleton-breadcrumb">' +
            '<div class="edbx-nav-group">' +
            '<span class="edbx-nav-back disabled"><span class="dashicons dashicons-arrow-left-alt2"></span></span>' +
            '<div class="edbx-breadcrumbs"><div class="edbx-skeleton-cell" style="width: 120px; height: 18px;"></div></div>' +
            '</div>' +
            '<div class="edbx-search-inline"><input type="search" class="edbx-search-input" placeholder="' + (typeof edbx_browse_button !== 'undefined' && edbx_browse_button.i18n_search || 'Search files...') + '" disabled></div>' +
            '</div>' +
            '<table class="wp-list-table widefat fixed edbx-files-table">' +
            '<thead><tr>' +
            '<th class="column-primary" style="width: 40%;">' + (typeof edbx_browse_button !== 'undefined' && edbx_browse_button.i18n_file_name || 'File Name') + '</th>' +
            '<th class="column-size" style="width: 20%;">' + (typeof edbx_browse_button !== 'undefined' && edbx_browse_button.i18n_file_size || 'File Size') + '</th>' +
            '<th class="column-date" style="width: 25%;">' + (typeof edbx_browse_button !== 'undefined' && edbx_browse_button.i18n_last_modified || 'Last Modified') + '</th>' +
            '<th class="column-actions" style="width: 15%;">' + (typeof edbx_browse_button !== 'undefined' && edbx_browse_button.i18n_actions || 'Actions') + '</th>' +
            '</tr></thead>' +
            '<tbody>' + skeletonRowsHtml + '</tbody></table>' +
            '</div>';

        // Create DOM structure with skeleton
        var html =
            '<div id="edbx-modal-overlay" class="edbx-modal-overlay">' +
            '<div class="edbx-modal">' +
            '<div class="edbx-modal-header">' +
            '<h1 class="edbx-modal-title"></h1>' +
            '<button type="button" class="edbx-modal-close">' +
            '<span class="dashicons dashicons-no-alt"></span>' +
            '</button>' +
            '</div>' +
            '<div class="edbx-modal-content">' +
            skeletonHtml +
            '<div id="edbx-modal-container" class="edbx-modal-container hidden"></div>' +
            '</div>' +
            '</div>' +
            '</div>';

        $('body').append(html);

        $overlay = $('#edbx-modal-overlay');
        $modal = $overlay.find('.edbx-modal');
        $container = $overlay.find('#edbx-modal-container');
        $title = $overlay.find('.edbx-modal-title');
        $closeBtn = $overlay.find('.edbx-modal-close');
        $skeleton = $overlay.find('.edbx-skeleton-loader');

        // Event listeners
        $closeBtn.on('click', close);
        $overlay.on('click', function (e) {
            if ($(e.target).is($overlay)) {
                close();
            }
        });

        // Close on Escape key
        $(document).on('keydown', function (e) {
            if (e.keyCode === 27 && $overlay.hasClass('open')) {
                close();
            }
        });

        // Global event for content loaded
        $(document).on('edbx_content_loaded', function () {
            $skeleton.addClass('hidden');
            $container.removeClass('hidden');
        });
    }

    function open(url, title) {
        init();
        $title.text(title || 'Select File');

        // Reset state: show skeleton, hide container
        $skeleton.removeClass('hidden');
        $container.addClass('hidden');

        $overlay.addClass('open');
        $('body').css('overflow', 'hidden');

        // Trigger library load
        if (window.EDBXMediaLibrary) {
            window.EDBXMediaLibrary.load(url || '');
        }
    }

    function close() {
        if ($overlay) {
            $overlay.removeClass('open');
            $container.empty().addClass('hidden');
            $skeleton.removeClass('hidden');
            $('body').css('overflow', '');
        }
    }

    return {
        open: open,
        close: close,
        getSkeletonRows: function () { return skeletonRowsHtml; }
    };

})(jQuery);
