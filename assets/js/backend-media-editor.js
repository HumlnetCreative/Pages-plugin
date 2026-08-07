(function($) {
    'use strict';

    if (window.hucrPagesMediaEditorRegistered) {
        return;
    }

    window.hucrPagesMediaEditorRegistered = true;

    function descriptionFields(container) {
        return {
            alt: container.querySelector('[data-hucr-media-alt]'),
            decorative: container.querySelector('[data-hucr-media-decorative]')
        };
    }

    function syncDescription(container, changedField) {
        const fields = descriptionFields(container);
        if (!fields.alt || !fields.decorative) {
            return;
        }

        if (changedField === fields.decorative && fields.decorative.checked) {
            fields.alt.value = '';
        }
        else if (changedField === fields.alt && fields.alt.value.trim() !== '') {
            fields.decorative.checked = false;
        }

        container.classList.toggle('is-decorative', fields.decorative.checked);
    }

    $(document).on('input change', '[data-hucr-media-description] [data-hucr-media-alt], [data-hucr-media-description] [data-hucr-media-decorative]', function() {
        const container = this.closest('[data-hucr-media-description]');
        if (container) {
            syncDescription(container, this);
        }
    });

    $(document).on('click', '[data-hucr-update-media-metadata]', function() {
        const button = this;
        const card = button.closest('[data-hucr-media-card]');
        const fields = card ? descriptionFields(card) : {};
        if (!card || !fields.alt || !fields.decorative || button.disabled) {
            return;
        }

        button.disabled = true;
        $(button).request('onUpdateMediaMetadata', {
            data: {
                media_use_id: Number(card.dataset.mediaUseId),
                media_alt_text: fields.alt.value.trim(),
                media_decorative: fields.decorative.checked ? 1 : 0
            }
        }).always(function() {
            button.disabled = false;
        });
    });

    $(document).on('click', '[data-hucr-reuse-media]', function() {
        const button = this;
        const card = button.closest('[data-hucr-media-card]');
        const select = card ? card.querySelector('[data-hucr-reuse-slot]') : null;
        if (!card || !select || button.disabled) {
            return;
        }

        button.disabled = true;
        $(button).request('onReuseMedia', {
            data: {
                media_use_id: Number(card.dataset.mediaUseId),
                media_owner_kind: card.dataset.mediaOwnerKind,
                media_owner_id: Number(card.dataset.mediaOwnerId),
                media_slot: select.value
            }
        }).always(function() {
            button.disabled = false;
        });
    });

    function initializeDescriptions(root) {
        (root || document).querySelectorAll('[data-hucr-media-description]').forEach(function(container) {
            syncDescription(container, null);
        });
    }

    $(document).on('render complete.oc.request shown.oc.popup', function() {
        initializeDescriptions(document);
    });

    initializeDescriptions(document);
})(window.jQuery);
