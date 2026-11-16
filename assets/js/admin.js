/* jshint esversion:6 */
(function ($) {
    'use strict';

    $(document).ready(function () {

        // Duplicate content into a new post (no translation)
        $(document).on('click', '.zwh-create-btn', function () {
            var $btn     = $(this);
            var lang     = $btn.data('lang');
            var langName = $btn.data('lang-name');
            var postId   = $('#zwh-mlp-box').data('post-id');

            if (!confirm('Duplicate this post for ' + langName + '? The content will be copied as-is for manual editing.')) {
                return;
            }

            setLoading($btn, 'Creating…');
            showStatus('Creating ' + langName + ' copy…', 'info');

            $.post(zwh_mlp.ajaxUrl, {
                action:         'zwh_mlp_create_translation',
                nonce:           zwh_mlp.nonce,
                post_id:         postId,
                lang:            lang,
                auto_translate:  0
            })
            .done(function (res) {
                if (res.success) {
                    showStatus('&#10003; Created! <a href="' + res.data.edit_url + '" target="_blank">Edit ' + langName + ' draft &rarr;</a>', 'success');
                    updateRow(lang, res.data.edit_url, 'Draft');
                } else {
                    showStatus('Error: ' + (res.data || 'Unknown error'), 'error');
                    resetBtn($btn, 'Duplicate');
                }
            })
            .fail(function () {
                showStatus('Request failed.', 'error');
                resetBtn($btn, 'Duplicate');
            });
        });

        // Auto-translate via AI/API
        $(document).on('click', '.zwh-translate-btn', function () {
            var $btn     = $(this);
            var lang     = $btn.data('lang');
            var langName = $btn.data('lang-name');
            var postId   = $('#zwh-mlp-box').data('post-id');

            setLoading($btn, 'Translating…');
            $btn.closest('tr').find('.zwh-create-btn').prop('disabled', true);
            showStatus('&#8987; Auto-translating to ' + langName + '… (this may take a moment)', 'info');

            $.post(zwh_mlp.ajaxUrl, {
                action:         'zwh_mlp_create_translation',
                nonce:           zwh_mlp.nonce,
                post_id:         postId,
                lang:            lang,
                auto_translate:  1
            })
            .done(function (res) {
                if (res.success) {
                    showStatus('&#10024; Translated! <a href="' + res.data.edit_url + '" target="_blank">Review ' + langName + ' draft &rarr;</a>', 'success');
                    updateRow(lang, res.data.edit_url, 'Draft');
                } else {
                    showStatus('Error: ' + (res.data || 'Unknown error'), 'error');
                    resetBtn($btn, '&#10024; AI translate');
                    $btn.closest('tr').find('.zwh-create-btn').prop('disabled', false);
                }
            })
            .fail(function () {
                showStatus('Request failed.', 'error');
                resetBtn($btn, '&#10024; AI translate');
                $btn.closest('tr').find('.zwh-create-btn').prop('disabled', false);
            });
        });

        // ---------------------------------------------------------------
        // "Translate this content" button (inside the editor meta box)
        // ---------------------------------------------------------------

        $(document).on('click', '#zwh-translate-content-btn', function () {
            var $btn       = $(this);
            var sourceId   = $btn.data('source-id');
            var sourceLang = $btn.data('source-lang');
            var targetLang = $btn.data('target-lang');

            if (!confirm('Replace the current title and content with an AI translation?\nThis will overwrite what is in the editor now.')) {
                return;
            }

            $btn.prop('disabled', true).html('&#8987; Translating…');
            showStatus('Translating content… this may take a moment.', 'info');

            $.post(zwh_mlp.ajaxUrl, {
                action:      'zwh_mlp_translate_content',
                nonce:        zwh_mlp.nonce,
                post_id:      $('#zwh-mlp-box').data('post-id'),
                source_id:    sourceId,
                source_lang:  sourceLang,
                target_lang:  targetLang
            })
            .done(function (res) {
                if (res.success) {
                    var title   = res.data.title;
                    var content = res.data.content;
                    var excerpt = res.data.excerpt;
                    var saved   = res.data.saved;

                    if (saved) {
                        // Content saved server-side — reload so the editor shows
                        // the persisted state and avoids "unsaved changes" warnings.
                        showStatus('&#10024; Translation saved! Reloading…', 'success');
                        setTimeout(function () { window.location.reload(); }, 1200);
                        return;
                    }

                    // Fallback: apply translation in the editor and ask user to save.

                    // ── Block editor (Gutenberg) ──
                    if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
                        wp.data.dispatch('core/editor').editPost({ title: title });
                        if (wp.blocks && wp.blocks.rawHandler) {
                            var blocks = wp.blocks.rawHandler({ HTML: content });
                            wp.data.dispatch('core/editor').resetBlocks(blocks);
                        }
                        if (excerpt) {
                            wp.data.dispatch('core/editor').editPost({ excerpt: excerpt });
                        }
                        wp.data.dispatch('core/editor').savePost();

                    // ── Classic editor (TinyMCE / plain textarea) ──
                    } else {
                        $('#title').val(title);
                        if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
                            tinymce.get('content').setContent(content);
                        } else {
                            $('#content').val(content);
                        }
                        if (excerpt) { $('#excerpt').val(excerpt); }
                    }

                    showStatus('&#10024; Translation applied! Click <strong>Update</strong> to save.', 'success');
                    $btn.html('&#x2728; Re-translate content');
                    $btn.prop('disabled', false);
                } else {
                    showStatus('Error: ' + (res.data || 'Unknown error'), 'error');
                    $btn.prop('disabled', false).html('&#x2728; AI Translate content');
                }
            })
            .fail(function () {
                showStatus('Request failed.', 'error');
                $btn.prop('disabled', false).html('&#x2728; AI Translate content');
            });
        });

        // ---------------------------------------------------------------
        // Post-list "Translate" row action → modal
        // ---------------------------------------------------------------

        var $modal       = $('#zwh-translate-modal');
        var $langSelect  = $('#zwh-translate-lang');
        var $subtitle    = $('#zwh-translate-subtitle');
        var $modalStatus = $('#zwh-translate-modal-status');
        var $dupBtn      = $('#zwh-translate-duplicate');
        var $aiBtn       = $('#zwh-translate-ai');
        var modalPostId  = null;

        function openModal(postId, postTitle, postLang, hasApi) {
            modalPostId = postId;

            // Remove the current post's language from the dropdown.
            $langSelect.find('option').each(function () {
                $(this).toggle($(this).val() !== postLang);
            });
            $langSelect.find('option:visible:first').prop('selected', true);

            $subtitle.text('"' + postTitle + '"');
            $aiBtn.toggle(hasApi === '1');
            $modalStatus.hide().prop('hidden', true);
            $dupBtn.prop('disabled', false).text('Duplicate');
            $aiBtn.prop('disabled', false).html('&#x2728; AI Translate');
            $modal.show();
        }

        function closeModal() {
            $modal.hide();
            modalPostId = null;
        }

        $(document).on('click', '.zwh-row-translate', function (e) {
            e.preventDefault();
            var $a = $(this);
            openModal(
                $a.data('post-id'),
                $a.data('post-title'),
                $a.data('post-lang'),
                $a.data('has-api') + ''
            );
        });

        $('#zwh-translate-close, #zwh-translate-backdrop').on('click', closeModal);

        $(document).on('keydown', function (e) {
            if (e.key === 'Escape') { closeModal(); }
        });

        function runModalTranslation(autoTranslate) {
            if (!modalPostId) { return; }
            var lang     = $langSelect.val();
            var langName = $langSelect.find('option:selected').data('name');

            $dupBtn.prop('disabled', true);
            $aiBtn.prop('disabled', true);
            showModalStatus(autoTranslate ? '&#8987; Translating to ' + langName + '…' : 'Creating ' + langName + ' copy…', 'info');

            $.post(zwh_mlp.ajaxUrl, {
                action:        'zwh_mlp_create_translation',
                nonce:          zwh_mlp.nonce,
                post_id:        modalPostId,
                lang:           lang,
                auto_translate: autoTranslate ? 1 : 0
            })
            .done(function (res) {
                if (res.success) {
                    showModalStatus(
                        '&#10003; Done! <a href="' + res.data.edit_url + '" target="_blank">Edit ' + langName + ' draft &rarr;</a>',
                        'success'
                    );
                    $dupBtn.hide();
                    $aiBtn.hide();
                    $('#zwh-translate-close').text('Close');
                } else {
                    showModalStatus('Error: ' + (res.data || 'Unknown error'), 'error');
                    $dupBtn.prop('disabled', false);
                    $aiBtn.prop('disabled', false);
                }
            })
            .fail(function () {
                showModalStatus('Request failed.', 'error');
                $dupBtn.prop('disabled', false);
                $aiBtn.prop('disabled', false);
            });
        }

        $dupBtn.on('click', function () { runModalTranslation(false); });
        $aiBtn.on('click',  function () { runModalTranslation(true);  });

        function showModalStatus(msg, type) {
            $modalStatus
                .show()
                .prop('hidden', false)
                .removeClass('zwh-status-info zwh-status-success zwh-status-error')
                .addClass('zwh-status-' + type)
                .html(msg);
        }

        function setLoading($btn, label) {
            $btn.prop('disabled', true).text(label);
        }

        function resetBtn($btn, label) {
            $btn.prop('disabled', false).html(label);
        }

        function updateRow(lang, editUrl, statusLabel) {
            var $row = $('.zwh-mlp-lang-row[data-lang="' + lang + '"]');
            $row.find('.zwh-col-status').html('<span class="zwh-badge zwh-badge-done">' + statusLabel + '</span>');
            $row.find('.zwh-col-actions').html('<a href="' + editUrl + '" class="button button-small" target="_blank">Edit</a>');
        }

        function showStatus(msg, type) {
            $('#zwh-mlp-status')
                .show()
                .prop('hidden', false)
                .removeClass('zwh-status-info zwh-status-success zwh-status-error')
                .addClass('zwh-status-' + type)
                .html(msg);
        }
    });

}(jQuery));
