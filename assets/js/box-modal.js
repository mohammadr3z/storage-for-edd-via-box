/**
 * EDBX Modal JS
 */
var EDBXModal = (function ($) {
    var $modal, $overlay, $iframe, $closeBtn, $skeleton;

    function init() {
        if ($('#edbx-modal-overlay').length) {
            return;
        }

        // Skeleton HTML structure
        var skeletonHtml =
            '<div class="edbx-skeleton-loader">' +
            '<div class="edbx-skeleton-header">' +
            '<div class="edbx-skeleton-title"></div>' +
            '<div class="edbx-skeleton-button"></div>' +
            '</div>' +
            '<div class="edbx-skeleton-breadcrumb">' +
            '<div class="edbx-skeleton-back-btn"></div>' +
            '<div class="edbx-skeleton-path"></div>' +
            '<div class="edbx-skeleton-search"></div>' +
            '</div>' +
            '<div class="edbx-skeleton-table">' +
            '<div class="edbx-skeleton-thead">' +
            '<div class="edbx-skeleton-row">' +
            '<div class="edbx-skeleton-cell name"></div>' +
            '<div class="edbx-skeleton-cell size"></div>' +
            '<div class="edbx-skeleton-cell date"></div>' +
            '<div class="edbx-skeleton-cell action"></div>' +
            '</div>' +
            '</div>' +
            '<div class="edbx-skeleton-row">' +
            '<div class="edbx-skeleton-cell name"></div>' +
            '<div class="edbx-skeleton-cell size"></div>' +
            '<div class="edbx-skeleton-cell date"></div>' +
            '<div class="edbx-skeleton-cell action"></div>' +
            '</div>' +
            '</div>' +
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
            '<iframe class="edbx-modal-frame loading" src=""></iframe>' +
            '</div>' +
            '</div>' +
            '</div>';

        $('body').append(html);

        $overlay = $('#edbx-modal-overlay');
        $modal = $overlay.find('.edbx-modal');
        $iframe = $overlay.find('.edbx-modal-frame');
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

        // Handle iframe load event
        $iframe.on('load', function () {
            $skeleton.addClass('hidden');
            $iframe.removeClass('loading').addClass('loaded');
        });
    }

    function open(url, title) {
        init();
        $title.text(title || 'Select File');

        // Reset state: show skeleton, hide iframe
        $skeleton.removeClass('hidden');
        $iframe.removeClass('loaded').addClass('loading');

        $iframe.attr('src', url);
        $overlay.addClass('open');
        $('body').css('overflow', 'hidden');
    }

    function close() {
        if ($overlay) {
            $overlay.removeClass('open');
            $iframe.attr('src', '');
            $iframe.removeClass('loaded').addClass('loading');
            $skeleton.removeClass('hidden');
            $('body').css('overflow', '');
        }
    }

    return {
        open: open,
        close: close
    };

})(jQuery);
