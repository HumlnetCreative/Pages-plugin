(function() {
    'use strict';

    if (window.hucrPagesSliderEditorRegistered) {
        return;
    }

    window.hucrPagesSliderEditorRegistered = true;

    const embeddedStyles = `
        .left-side-menu-container,
        #layout-mainmenu,
        #layout-mainmenu-responsive-container,
        #layout-sidenav-responsive,
        .layout-sidenav-container {
            display: none !important;
        }

        #layout-body {
            width: 100% !important;
            max-width: none !important;
        }
    `;

    function prepareFrame(frame) {
        try {
            const document = frame.contentDocument;
            if (!document?.head || document.getElementById('hucr-tailor-embedded-styles')) {
                return;
            }

            const style = document.createElement('style');
            style.id = 'hucr-tailor-embedded-styles';
            style.textContent = embeddedStyles;
            document.head.appendChild(style);
            document.documentElement.classList.add('hucr-tailor-embedded');
        }
        catch (error) {
            // If the browser ever blocks same-origin frame access, Tailor
            // remains fully usable with its standard navigation visible.
        }
    }

    function bindFrame(frame) {
        if (frame.dataset.hucrTailorBound === 'true') {
            return;
        }

        frame.dataset.hucrTailorBound = 'true';
        frame.addEventListener('load', function() {
            prepareFrame(frame);
        });
        prepareFrame(frame);
    }

    function bindFrames(root) {
        (root || document).querySelectorAll('[data-hucr-tailor-frame]').forEach(bindFrame);
    }

    document.addEventListener('DOMContentLoaded', function() {
        bindFrames(document);
    });
    document.addEventListener('ajax:update-complete', function() {
        bindFrames(document);
    });

    new MutationObserver(function() {
        bindFrames(document);
    }).observe(document.documentElement, { childList: true, subtree: true });
})();
