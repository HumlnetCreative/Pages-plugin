(function() {
    'use strict';

    function initialize(form) {
        if (form.dataset.hucrRevisionReady === 'true') {
            return;
        }
        form.dataset.hucrRevisionReady = 'true';

        const saveButton = form.querySelector('[data-hucr-save-primary]');
        const heartbeat = form.querySelector('[data-hucr-heartbeat]');
        const revisionStatus = form.querySelector('[data-hucr-revision-status]');
        const draftTitle = form.querySelector('[data-hucr-draft-title]');
        const publicStatus = form.querySelector('[data-hucr-public-status]');
        const saveStatus = form.querySelector('[data-hucr-save-status]');
        const draftActions = form.querySelectorAll('[data-hucr-requires-draft]');
        const hasPublished = revisionStatus?.dataset.hasPublished === 'true';
        let autosaveTimer = null;
        let saving = false;

        function markAsDraft() {
            if (draftTitle) {
                draftTitle.textContent = hasPublished ? 'Nepublikovaný koncept' : 'Nový koncept';
            }
            if (publicStatus) {
                publicStatus.textContent = publicStatus.dataset.publishedDescription
                    + (hasPublished ? ' Koncept obsahuje nepublikované změny.' : '');
            }
            draftActions.forEach(function(action) { action.hidden = false; });
        }

        function scheduleAutosave(event) {
            // October widgets emit synthetic change events while hydrating after a reload.
            // They must never create a phantom draft immediately after publication.
            if (!event.isTrusted || !saveButton || saving || event.target.closest('[data-control="popup"]')) {
                return;
            }
            window.clearTimeout(autosaveTimer);
            markAsDraft();
            if (saveStatus) {
                saveStatus.textContent = 'Čeká na automatické uložení…';
            }
            autosaveTimer = window.setTimeout(function() {
                if (!saveButton.disabled) {
                    saveButton.click();
                }
            }, 1800);
        }

        form.addEventListener('input', scheduleAutosave);
        form.addEventListener('change', scheduleAutosave);

        if (window.jQuery) {
            window.jQuery(form)
                .on('ajax:promise.hucrRevision', function(event) {
                    if (event.detail?.context?.handler === 'onSave') {
                        saving = true;
                        window.clearTimeout(autosaveTimer);
                        if (saveStatus) {
                            saveStatus.textContent = 'Ukládám koncept…';
                        }
                    }
                })
                .on('ajax:done.hucrRevision', function(event) {
                    if (event.detail?.context?.handler === 'onSave') {
                        saving = false;
                        markAsDraft();
                        if (saveStatus) {
                            saveStatus.textContent = saveStatus.dataset.savedLabel || 'Koncept je uložený.';
                        }
                    }
                })
                .on('ajax:fail.hucrRevision', function(event) {
                    const handler = event.detail?.context?.handler;
                    if (handler === 'onSave') {
                        saving = false;
                        if (saveStatus) {
                            saveStatus.textContent = 'Automatické uložení se nezdařilo.';
                        }
                    }
                    if (handler === 'onHeartbeat') {
                        form.querySelectorAll('.hucr-revision-editor__fields input, .hucr-revision-editor__fields textarea, .hucr-revision-editor__fields select, .hucr-revision-editor__fields button')
                            .forEach(function(element) { element.disabled = true; });
                        if (saveStatus) {
                            saveStatus.textContent = 'Zámek byl ztracen. Obnovte stránku.';
                        }
                    }
                });
        }

        if (heartbeat) {
            window.setInterval(function() {
                if (document.visibilityState === 'visible') {
                    heartbeat.click();
                }
            }, 60000);
        }
    }

    function scan() {
        document.querySelectorAll('form[data-hucr-revision-editor]').forEach(initialize);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan, { once: true });
    }
    else {
        scan();
    }
})();
