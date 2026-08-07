(function() {
    'use strict';

    if (window.hucrPagesSaveHotkeyRegistered) {
        return;
    }

    window.hucrPagesSaveHotkeyRegistered = true;

    const popupSelector = '.control-popup, .modal.show, [data-control="popup"]';

    function isVisible(element) {
        return element instanceof HTMLElement
            && !element.hidden
            && element.getClientRects().length > 0;
    }

    function saveButtonFor(form) {
        const explicitButton = form.querySelector(
            '[data-hucr-save-primary], button[data-request="onSave"]:not([data-request-data*="close"])'
        );

        if (explicitButton && isVisible(explicitButton)) {
            return explicitButton;
        }

        const relationRequest = form.getAttribute('data-request');
        if (relationRequest === 'onRelationManageUpdate' || relationRequest === 'onRelationManageCreate') {
            const relationButton = form.querySelector('.modal-footer button[type="submit"].btn-primary');
            return isVisible(relationButton) ? relationButton : null;
        }

        return null;
    }

    function saveAndCloseButtonFor(form) {
        const closeButton = form.querySelector(
            '[data-hucr-save-close], button[data-request="onSave"][data-request-data*="close"]'
        );

        return closeButton && isVisible(closeButton) ? closeButton : null;
    }

    function enhanceRelationUpdateForm(form) {
        if (form.dataset.hucrSaveActionsReady === 'true') {
            return;
        }

        const primaryButton = form.querySelector('.modal-footer button[type="submit"].btn-primary');
        if (!primaryButton) {
            return;
        }

        form.dataset.hucrSaveActionsReady = 'true';
        primaryButton.dataset.hucrSavePrimary = '';
        primaryButton.textContent = 'Uložit';
        primaryButton.title = '⌘S / Ctrl+S';

        const closeButton = document.createElement('button');
        closeButton.type = 'submit';
        closeButton.className = 'btn btn-secondary ms-2';
        closeButton.dataset.hucrSaveClose = '';
        closeButton.textContent = 'Uložit a zavřít';
        closeButton.title = '⌘Shift+S / Ctrl+Shift+S';
        primaryButton.insertAdjacentElement('afterend', closeButton);

        primaryButton.addEventListener('click', function() {
            form.dataset.hucrSubmitMode = 'stay';
        });
        closeButton.addEventListener('click', function() {
            form.dataset.hucrSubmitMode = 'close';
        });

        form.addEventListener('submit', function(event) {
            const shouldClose = event.submitter === closeButton || form.dataset.hucrSubmitMode === 'close';
            form.dataset.hucrSubmitMode = 'stay';

            if (shouldClose) {
                form.setAttribute('data-popup-load-indicator', 'true');
            }
            else {
                form.removeAttribute('data-popup-load-indicator');
            }
        });

        if (window.jQuery) {
            window.jQuery(form)
                .on('ajax:promise.hucrSaveActions', function(event) {
                    if (event.detail?.context?.handler !== form.getAttribute('data-request')) {
                        return;
                    }
                    primaryButton.disabled = true;
                    closeButton.disabled = true;
                })
                .on('ajax:done.hucrSaveActions ajax:fail.hucrSaveActions', function(event) {
                    if (event.detail?.context?.handler !== form.getAttribute('data-request')) {
                        return;
                    }
                    primaryButton.disabled = false;
                    closeButton.disabled = false;
                });
        }
    }

    function enhanceRelationUpdateForms() {
        document.querySelectorAll('form[data-request="onRelationManageUpdate"]')
            .forEach(enhanceRelationUpdateForm);
    }

    let enhanceScheduled = false;
    function scheduleEnhancement() {
        if (enhanceScheduled) {
            return;
        }

        enhanceScheduled = true;
        window.requestAnimationFrame(function() {
            enhanceScheduled = false;
            enhanceRelationUpdateForms();
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scheduleEnhancement, { once: true });
    }
    else {
        scheduleEnhancement();
    }

    new MutationObserver(scheduleEnhancement).observe(document.documentElement, {
        childList: true,
        subtree: true
    });

    function formInTopPopup() {
        const popups = Array.from(document.querySelectorAll(popupSelector)).filter(isVisible);

        for (let index = popups.length - 1; index >= 0; index--) {
            const forms = Array.from(popups[index].querySelectorAll('form')).filter(isVisible);
            for (let formIndex = forms.length - 1; formIndex >= 0; formIndex--) {
                if (saveButtonFor(forms[formIndex])) {
                    return forms[formIndex];
                }
            }
        }

        return null;
    }

    function activeSaveForm() {
        const activeElement = document.activeElement;
        const activeForm = activeElement instanceof Element ? activeElement.closest('form') : null;

        if (activeForm && isVisible(activeForm) && saveButtonFor(activeForm)) {
            return activeForm;
        }

        const popupForm = formInTopPopup();
        if (popupForm) {
            return popupForm;
        }

        const forms = Array.from(document.querySelectorAll('form')).filter(isVisible);
        return forms.find((form) => saveButtonFor(form)) || null;
    }

    document.addEventListener('keydown', function(event) {
        const isSaveShortcut = (event.metaKey || event.ctrlKey)
            && !event.altKey
            && event.key.toLowerCase() === 's';

        if (!isSaveShortcut || event.repeat) {
            return;
        }

        const form = activeSaveForm();
        const button = form
            ? (event.shiftKey ? saveAndCloseButtonFor(form) : saveButtonFor(form))
            : null;
        if (!button) {
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();

        if (button.disabled || button.classList.contains('disabled') || button.getAttribute('aria-disabled') === 'true') {
            return;
        }

        button.click();
    }, true);
})();
