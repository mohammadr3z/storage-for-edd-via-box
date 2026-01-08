/**
 * EDBX Modal JS
 */
var EDBXModal = (function ($) {
    var $modal, $overlay, $iframe, $closeBtn;

    function init() {
        if ($('#edbx-modal-overlay').length) {
            return;
        }

        // Create DOM structure
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
            '<iframe class="edbx-modal-frame" src=""></iframe>' +
            '</div>' +
            '</div>' +
            '</div>';

        $('body').append(html);

        $overlay = $('#edbx-modal-overlay');
        $modal = $overlay.find('.edbx-modal');
        $iframe = $overlay.find('.edbx-modal-frame');
        $title = $overlay.find('.edbx-modal-title');
        $closeBtn = $overlay.find('.edbx-modal-close');

        // Event listeners
        $closeBtn.on('click', close);
        $overlay.on('click', function (e) {
            if ($(e.target).is($overlay)) {
                close();
            }
        });

        // Close on Escape key
        $(document).on('keydown', function (e) {
            if (e.keyCode === 27 && $overlay.hasClass('open')) { // ESC
                close();
            }
        });
    }

    function open(url, title) {
        init();
        $title.text(title || 'Select File');
        $iframe.attr('src', url);
        $overlay.addClass('open');
        $('body').css('overflow', 'hidden'); // Prevent body scroll
    }

    function close() {
        if ($overlay) {
            $overlay.removeClass('open');
            $iframe.attr('src', ''); // Stop loading
            $('body').css('overflow', '');
        }
    }

    return {
        open: open,
        close: close
    };

})(jQuery);
