(function() {
    'use strict';

    function revisionStatus() {
        return document.querySelector('[data-hucr-revision-status]');
    }

    function showDraftState(status) {
        const hasPublished = status.dataset.hasPublished === 'true';
        const draftTitle = status.querySelector('[data-hucr-draft-title]');
        const publicStatus = status.querySelector('[data-hucr-public-status]');
        if (draftTitle) {
            draftTitle.textContent = hasPublished ? 'Nepublikovaný koncept' : 'Nový koncept';
        }
        if (publicStatus) {
            publicStatus.textContent = publicStatus.dataset.publishedDescription
                + (hasPublished ? ' Koncept obsahuje nepublikované změny.' : '');
        }
    }

    function showCleanState(status) {
        const hasPublished = status.dataset.hasPublished === 'true';
        const draftTitle = status.querySelector('[data-hucr-draft-title]');
        const publicStatus = status.querySelector('[data-hucr-public-status]');
        const saveStatus = status.querySelector('[data-hucr-save-status]');
        if (draftTitle) {
            draftTitle.textContent = hasPublished ? 'Publikováno' : 'Nový koncept';
        }
        if (publicStatus) {
            publicStatus.textContent = publicStatus.dataset.publishedDescription
                + (hasPublished ? ' Pracovní kopie této verzi odpovídá.' : '');
        }
        if (saveStatus) {
            saveStatus.textContent = '';
        }
    }

    document.addEventListener('ajax:setup', function(event) {
        const status = revisionStatus();
        const context = event.detail?.context;
        if (!status || !context) {
            return;
        }
        context.options.data = Object.assign({}, context.options.data || {}, {
            page_id: Number(status.dataset.pageId),
            expected_draft_version: Number(status.dataset.draftVersion)
        });
    });

    document.addEventListener('hucr:draft-version', function(event) {
        const status = revisionStatus();
        if (!status || Number(status.dataset.pageId) !== Number(event.detail?.pageId)) {
            return;
        }
        status.dataset.draftVersion = String(event.detail.draftVersion);
        status.dataset.hasDraft = event.detail.hasDraft ? 'true' : 'false';
        if (event.detail.hasDraft) {
            showDraftState(status);
            const saveStatus = status.querySelector('[data-hucr-save-status]');
            if (saveStatus) {
                saveStatus.textContent = 'Koncept je uložený.';
            }
        }
        else {
            showCleanState(status);
        }
        status.querySelectorAll('[data-hucr-requires-draft]').forEach(function(action) {
            action.hidden = !event.detail.hasDraft;
        });
        const undo = status.querySelector('[data-hucr-undo]');
        const redo = status.querySelector('[data-hucr-redo]');
        if (undo && typeof event.detail.canUndo === 'boolean') {
            undo.disabled = !event.detail.canUndo;
        }
        if (redo && typeof event.detail.canRedo === 'boolean') {
            redo.disabled = !event.detail.canRedo;
        }
    });

    function initialize(form) {
        if (form.dataset.hucrRevisionReady === 'true') {
            return;
        }
        form.dataset.hucrRevisionReady = 'true';

        const saveButton = form.querySelector('[data-hucr-save-primary]');
        const heartbeat = form.querySelector('[data-hucr-heartbeat]');
        const revisionStatus = form.querySelector('[data-hucr-revision-status]');
        const saveStatus = form.querySelector('[data-hucr-save-status]');
        const draftActions = form.querySelectorAll('[data-hucr-requires-draft]');
        let autosaveTimer = null;
        let saving = false;

        function markAsDraft() {
            showDraftState(revisionStatus);
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
