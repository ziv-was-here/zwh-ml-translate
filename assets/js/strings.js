/* jshint esversion:6 */
(function ($) {
    'use strict';

    var rowIndex = $('#zwh-strings-body tr').length;

    // Add new row
    $('#zwh-add-string').on('click', function () {
        var tpl = $('#zwh-string-row-template').html()
            .replace(/__INDEX__/g, rowIndex++);
        $('#zwh-strings-body').append(tpl);
        $('#zwh-strings-body tr:last-child .zwh-string-source').focus();
    });

    // Remove row
    $(document).on('click', '.zwh-remove-string', function () {
        $(this).closest('tr').remove();
    });

    // AI translate single string
    $(document).on('click', '.zwh-ai-string-btn', function () {
        var $btn  = $(this);
        var $row  = $btn.closest('tr');
        var src   = $row.find('.zwh-string-source').val().trim();
        var lang  = $btn.data('lang');
        var $inp  = $row.find('.zwh-string-trans[data-lang="' + lang + '"]');

        if (!src) {
            alert('Enter a source string first.');
            return;
        }

        $btn.prop('disabled', true).text('…');

        $.post(zwh_mlp_strings.ajaxUrl, {
            action:      'zwh_mlp_ai_translate_string',
            nonce:        zwh_mlp_strings.nonce,
            source:       src,
            target_lang:  lang
        })
        .done(function (res) {
            if (res.success) {
                $inp.val(res.data.translation);
                $btn.text('✓');
                setTimeout(function () { $btn.prop('disabled', false).text('✨'); }, 1500);
            } else {
                alert('Error: ' + (res.data || 'Unknown'));
                $btn.prop('disabled', false).text('✨');
            }
        })
        .fail(function () {
            alert('Request failed.');
            $btn.prop('disabled', false).text('✨');
        });
    });

    // Auto-fill all translations for a row when source loses focus
    $(document).on('blur', '.zwh-string-source', function () {
        if (!zwh_mlp_strings.hasApi) { return; }
        var $row = $(this).closest('tr');
        var src  = $(this).val().trim();
        if (!src) { return; }

        // Only auto-fill empty translation fields
        $row.find('.zwh-string-trans').each(function () {
            var $inp = $(this);
            if ($inp.val().trim() !== '') { return; }
            var lang = $inp.data('lang');
            var $btn = $row.find('.zwh-ai-string-btn[data-lang="' + lang + '"]');
            $btn.trigger('click');
        });
    });

}(jQuery));
