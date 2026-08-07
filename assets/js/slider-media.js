(function($) {
    'use strict';
    $(document).on('input', '[data-video-position] input[type="range"]', function() {
        const editor = this.closest('[data-video-position]');
        const video = editor?.querySelector('video');
        const x = editor?.querySelector('[data-position-x]')?.value || 50;
        const y = editor?.querySelector('[data-position-y]')?.value || 50;
        if (video) video.style.objectPosition = x + '% ' + y + '%';
    });
    $(document).on('click', '[data-hucr-video-position-save]', function() {
        const button = this;
        const editor = this.closest('[data-video-position]');
        button.disabled = true;
        $(button).request(button.dataset.positionHandler, { data: {
            context_id: Number(button.dataset.contextId),
            media_use_id: Number(button.dataset.mediaUseId),
            viewport: button.dataset.viewport,
            position_x: editor.querySelector('[data-position-x]').value,
            position_y: editor.querySelector('[data-position-y]').value
        }}).always(function() { button.disabled = false; });
    });

    $(document).on('click', '[data-hucr-slider-update-metadata]', function() {
        const button = this;
        const container = button.closest('.hucr-slider-media__upload');
        const alt = container?.querySelector('[name="media_alt_text"]');
        const decorative = container?.querySelector('[name="media_decorative"]');
        if (!container || !alt || !decorative || button.disabled) return;

        button.disabled = true;
        $(button).request(button.dataset.handler, { data: {
            context_id: Number(button.dataset.contextId),
            media_use_id: Number(button.dataset.mediaUseId),
            media_alt_text: alt.value.trim(),
            media_decorative: decorative.checked ? 1 : 0
        }}).always(function() { button.disabled = false; });
    });
})(window.jQuery);
