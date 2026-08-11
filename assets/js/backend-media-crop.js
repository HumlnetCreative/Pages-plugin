(function($) {
    'use strict';

    if (window.hucrPagesMediaCropRegistered) {
        return;
    }

    window.hucrPagesMediaCropRegistered = true;

    function numberFrom(editor, name) {
        return Number(editor.dataset[name]);
    }

    function normalizeSelection(editor, selection) {
        const ratio = numberFrom(editor, 'aspectRatio');
        const imageWidth = numberFrom(editor, 'imageWidth');
        const imageHeight = numberFrom(editor, 'imageHeight');
        let width = Math.max(1, Math.round(selection.w || 0));
        let height = Math.max(1, Math.round(selection.h || 0));

        // Jcrop keeps the ratio in floating-point coordinates. Normalize the
        // independently rounded pixel dimensions before displaying/saving.
        if (width / height > ratio) {
            width = Math.max(1, Math.round(height * ratio));
        }
        else {
            height = Math.max(1, Math.round(width / ratio));
        }

        return {
            x: Math.max(0, Math.min(Math.round(selection.x || 0), imageWidth - width)),
            y: Math.max(0, Math.min(Math.round(selection.y || 0), imageHeight - height)),
            w: width,
            h: height
        };
    }

    function updateCoordinates(editor, selection) {
        selection = normalizeSelection(editor, selection);
        const values = {
            x: selection.x,
            y: selection.y,
            width: selection.w,
            height: selection.h
        };

        Object.entries(values).forEach(([key, value]) => {
            const input = editor.querySelector('[data-hucr-crop-' + key + ']');
            if (input) {
                input.value = value;
            }
        });
    }

    function initializeEditor(editor) {
        if (editor.dataset.hucrCropBound === 'true' || !editor.getClientRects().length) {
            return;
        }

        const image = editor.querySelector('[data-hucr-crop-image]');
        const canvas = editor.querySelector('[data-hucr-crop-canvas]');
        const error = editor.querySelector('[data-hucr-crop-error]');
        if (!image || !canvas || typeof $.Jcrop !== 'function') {
            return;
        }

        // MutationObserver and popup lifecycle events can reach this function
        // several times while the master image is still loading. Bind the
        // image and button listeners exactly once, independently of Jcrop's
        // later ready state.
        editor.dataset.hucrCropBound = 'true';

        const start = [
            numberFrom(editor, 'cropX'),
            numberFrom(editor, 'cropY'),
            numberFrom(editor, 'cropX') + numberFrom(editor, 'cropWidth'),
            numberFrom(editor, 'cropY') + numberFrom(editor, 'cropHeight')
        ];
        const initializeCropper = function() {
            if (editor.dataset.hucrCropReady === 'true') {
                return;
            }

            if (!image.naturalWidth || !image.naturalHeight) {
                showImageError();
                return;
            }

            editor.dataset.hucrCropReady = 'true';
            const boxWidth = Math.max(320, canvas.clientWidth - 32);
            const boxHeight = Math.max(320, window.innerHeight - 280);
            const cropper = $.Jcrop(image, {
                aspectRatio: numberFrom(editor, 'aspectRatio'),
                trueSize: [numberFrom(editor, 'imageWidth'), numberFrom(editor, 'imageHeight')],
                minSize: [numberFrom(editor, 'minWidth'), numberFrom(editor, 'minHeight')],
                boxWidth: boxWidth,
                boxHeight: boxHeight,
                setSelect: start,
                shade: true,
                bgOpacity: 0.55,
                onChange: function(selection) { updateCoordinates(editor, selection); },
                onSelect: function(selection) { updateCoordinates(editor, selection); }
            });
            editor.hucrCropper = cropper;
            updateCoordinates(editor, cropper.tellSelect());
        };

        const showImageError = function() {
            image.hidden = true;
            if (error) {
                error.hidden = false;
            }
        };

        image.addEventListener('error', showImageError, { once: true });

        if (image.complete && image.naturalWidth) {
            initializeCropper();
        }
        else {
            image.addEventListener('load', initializeCropper, { once: true });
        }

        const applyButton = editor.querySelector('[data-hucr-apply-crop]');
        applyButton.addEventListener('click', function() {
            if (!editor.hucrCropper || editor.dataset.hucrCropSaving === 'true') {
                return;
            }

            const selection = normalizeSelection(editor, editor.hucrCropper.tellSelect());
            editor.dataset.hucrCropSaving = 'true';
            applyButton.disabled = true;
            $(applyButton).request(editor.dataset.applyHandler || 'onApplyMediaCrop', {
                data: {
                    media_use_id: numberFrom(editor, 'mediaUseId'),
                    context_id: numberFrom(editor, 'contextId'),
                    crop_x: selection.x,
                    crop_y: selection.y,
                    crop_width: selection.w,
                    crop_height: selection.h
                }
            })
                .done(function() {
                    $(editor).closest('.control-popup').popup('hide');
                })
                .always(function() {
                    editor.dataset.hucrCropSaving = 'false';
                    applyButton.disabled = false;
                });
        });
    }

    function initializeVisibleEditors() {
        document.querySelectorAll('[data-hucr-media-crop]').forEach(initializeEditor);
    }

    $(document).on('popupShow.hucrMediaCrop shown.oc.popup.hucrMediaCrop complete.oc.popup.hucrMediaCrop', function() {
        window.requestAnimationFrame(initializeVisibleEditors);
        window.setTimeout(initializeVisibleEditors, 150);
        window.setTimeout(initializeVisibleEditors, 400);
    });

    const observer = new MutationObserver(function() {
        window.requestAnimationFrame(initializeVisibleEditors);
        window.setTimeout(initializeVisibleEditors, 150);
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
})(window.jQuery);
