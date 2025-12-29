/**
 * Admin Upload Buttons Handler for Box
 * 
 * Extends EDD's default upload button behavior to work with Box.
 * This sets up the edd_filename and edd_fileurl variables when
 * the upload button is clicked, so the Box library can populate them.
 */
jQuery(function ($) {
    // When EDD upload button is clicked, store references to the input fields
    $('body').on('click', '.edd_upload_file_button', function () {
        window.edd_fileurl = $(this).parent().prev().find('input');
        window.edd_filename = $(this).parent().parent().parent().prev().find('input');
    });



    $('#edbx_save_link').click(function () {
        if (window.edd_filename && window.edd_fileurl) {
            $(window.edd_filename).val($(this).data('edbx-fn'));
            $(window.edd_fileurl).val(edbx_url_prefix + $(this).data('edbx-path'));
            try { parent.window.tb_remove(); } catch (e) { window.tb_remove(); }
        }
        return false;
    });
});
