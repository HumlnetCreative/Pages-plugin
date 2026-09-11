(function() {
    'use strict';

    let activeInspectorTab = 'edit';
    let canvasFullscreen = false;

    function revisionStatus() {
        return document.querySelector('[data-hucr-revision-status]');
    }

    function showDraftState(status) {
        const hasPublished = status.dataset.hasPublished === 'true';
        status.dataset.hasDraft = 'true';
        const draftTitle = status.querySelector('[data-hucr-draft-title]');
        const publicStatus = status.querySelector('[data-hucr-public-status]');
        if (draftTitle) {
            draftTitle.textContent = hasPublished ? 'Nepublikované změny' : 'Nový koncept';
        }
        if (publicStatus) {
            publicStatus.textContent = publicStatus.dataset.publishedDescription
                + (hasPublished ? ' Koncept obsahuje nepublikované změny.' : '');
        }
    }

    function showCleanState(status) {
        const hasPublished = status.dataset.hasPublished === 'true';
        status.dataset.hasDraft = 'false';
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
        const selectedCanvasCard = document.querySelector('[data-hucr-canvas] [data-hucr-section-card].is-selected');
        if (selectedCanvasCard) {
            context.options.data.selected_canvas_uuid = selectedCanvasCard.dataset.hucrSelectSection;
        }
        const canvas = document.querySelector('[data-hucr-canvas]');
        if (canvas?.dataset.catalogPosition) {
            context.options.data.builder_position = Number(canvas.dataset.catalogPosition);
        }
        if (canvas?.dataset.catalogContainer) {
            context.options.data.target_container_uuid = canvas.dataset.catalogContainer;
        }
        context.options.data.active_inspector_tab = activeInspectorTab;
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
        status.querySelectorAll('[data-hucr-requires-draft-enabled]').forEach(function(action) {
            action.disabled = !event.detail.hasDraft;
        });
        const undo = status.querySelector('[data-hucr-undo]');
        const redo = status.querySelector('[data-hucr-redo]');
        if (undo && typeof event.detail.canUndo === 'boolean') {
            undo.disabled = !event.detail.canUndo;
        }
        if (redo && typeof event.detail.canRedo === 'boolean') {
            redo.disabled = !event.detail.canRedo;
        }
        if (event.detail.commandHistory) {
            renderCommandHistory(event.detail.commandHistory);
        }
    });

    document.addEventListener('hucr:publication-preflight-refreshed', function(event) {
        const status = revisionStatus();
        if (!status || Number(status.dataset.pageId) !== Number(event.detail?.pageId)) {
            return;
        }
        const fullslug = document.querySelector('input[name="BuilderPage[fullslug]"]');
        if (fullslug && typeof event.detail.fullslug === 'string') {
            fullslug.value = event.detail.fullslug;
        }
        const fullslugProxy = document.querySelector('[data-hucr-page-proxy="fullslug"]');
        if (fullslugProxy && typeof event.detail.fullslug === 'string') {
            fullslugProxy.value = event.detail.fullslug;
        }
    });

    function renderCommandHistory(timeline) {
        document.querySelectorAll('[data-hucr-command-history]').forEach(function(history) {
            history.dataset.currentPosition = String(timeline.position);
            const summary = history.querySelector('.hucr-command-history__summary strong');
            const list = history.querySelector('[data-hucr-history-list]');
            if (summary) {
                summary.textContent = 'Krok ' + timeline.position + ' z ' + timeline.total;
            }
            if (!list) {
                return;
            }
            list.replaceChildren();
            const entries = [{ position: 0, label: 'Začátek relace', time: '', user: '', applied: true }]
                .concat(timeline.entries || []);
            entries.forEach(function(entry) {
                const item = document.createElement('li');
                const current = Number(entry.position) === Number(timeline.position);
                item.classList.toggle('is-current', current);
                item.classList.toggle('is-future', Number(entry.position) > Number(timeline.position));
                const button = document.createElement('button');
                button.type = 'button';
                button.disabled = current;
                button.setAttribute('data-request', 'onJumpCommandHistory');
                button.setAttribute('data-request-data', 'target_position: ' + Number(entry.position));
                const icon = document.createElement('i');
                icon.className = entry.position === 0 ? 'icon-flag' : (entry.applied ? 'icon-check' : 'icon-repeat');
                icon.setAttribute('aria-hidden', 'true');
                const content = document.createElement('span');
                const label = document.createElement('strong');
                label.textContent = entry.label;
                content.appendChild(label);
                if (entry.time || entry.user) {
                    const meta = document.createElement('small');
                    meta.textContent = [entry.time, entry.user].filter(Boolean).join(' · ');
                    content.appendChild(meta);
                }
                button.append(icon, content);
                item.appendChild(button);
                list.appendChild(item);
            });
        });
    }

    document.addEventListener('click', function(event) {
        const button = event.target.closest('[data-hucr-editor-view]');
        const editor = button?.closest('[data-hucr-builder-editor]');
        if (!button || !editor) {
            return;
        }

        const view = button.dataset.hucrEditorView;
        const canvas = editor.querySelector('[data-hucr-canvas]');
        if (view !== 'canvas' && canvas?.classList.contains('is-fullscreen')) {
            setCanvasFullscreen(canvas, false);
        }
        editor.dataset.editorView = view;
        const viewLabel = editor.querySelector('[data-hucr-view-label]');
        if (viewLabel) {
            viewLabel.textContent = view === 'canvas' ? 'Canvas' : 'Tabulka';
        }
        button.closest('details')?.removeAttribute('open');
        editor.querySelectorAll('[data-hucr-editor-view]').forEach(function(candidate) {
            const active = candidate.dataset.hucrEditorView === view;
            candidate.classList.toggle('active', active);
            candidate.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        editor.querySelectorAll('[data-hucr-editor-panel]').forEach(function(panel) {
            panel.hidden = panel.dataset.hucrEditorPanel !== view;
        });
        if (view === 'canvas') {
            initializeCanvases(editor);
        }
    });

    function selectCanvasSection(canvas, uuid, focusCard, preserveQuickFields) {
        const card = Array.from(canvas.querySelectorAll('[data-hucr-section-card]'))
            .find(function(candidate) { return candidate.dataset.hucrSelectSection === uuid; });
        if (!card) {
            return;
        }

        canvas.querySelectorAll('[data-hucr-select-section]').forEach(function(control) {
            const selected = control.dataset.hucrSelectSection === uuid;
            control.classList.toggle('is-selected', selected);
            if (control.matches('[role="option"]')) {
                control.setAttribute('aria-selected', selected ? 'true' : 'false');
            }
            if (control.matches('[data-hucr-section-card]')) {
                control.setAttribute('aria-pressed', selected ? 'true' : 'false');
            }
        });

        ['type', 'title', 'heading', 'text', 'count', 'source', 'status', 'width', 'spacing', 'scheme', 'motion']
            .forEach(function(field) {
                canvas.querySelectorAll('[data-hucr-selection-field="' + field + '"]').forEach(function(target) {
                    target.textContent = card.dataset['section' + field.charAt(0).toUpperCase() + field.slice(1)] || '';
                });
            });
        ['count', 'source'].forEach(function(field) {
            const row = canvas.querySelector('[data-hucr-selection-row="' + field + '"]');
            if (row) {
                row.hidden = !card.dataset['section' + field.charAt(0).toUpperCase() + field.slice(1)];
            }
        });
        const details = canvas.querySelector('[data-hucr-selection-details]');
        const empty = canvas.querySelector('[data-hucr-selection-empty]');
        if (details) {
            details.hidden = false;
        }
        if (empty) {
            empty.hidden = true;
        }
        const quickUuid = canvas.querySelector('[data-hucr-quick-field="uuid"]');
        const quickTitle = canvas.querySelector('[data-hucr-quick-field="title"]');
        const quickHeading = canvas.querySelector('[data-hucr-quick-field="heading"]');
        const quickVisible = canvas.querySelector('[data-hucr-quick-field="visible"]');
        const quickWidth = canvas.querySelector('[data-hucr-quick-field="width"]');
        const quickSpacing = canvas.querySelector('[data-hucr-quick-field="spacing"]');
        const quickSchemes = Array.from(canvas.querySelectorAll('[data-hucr-quick-field="color_scheme"]'));
        const quickFillHeight = canvas.querySelector('[data-hucr-quick-field="fill_height"]');
        const quickHeadingRow = canvas.querySelector('[data-hucr-quick-heading-row]');
        const quickFillHeightRow = canvas.querySelector('[data-hucr-quick-fill-height-row]');
        if (quickUuid) {
            quickUuid.value = uuid;
        }
        if (quickTitle && !preserveQuickFields) {
            const value = card.dataset.sectionTitle || '';
            if (quickTitle.value !== value) {
                quickTitle.value = value;
            }
        }
        if (quickHeading && !preserveQuickFields) {
            const value = card.dataset.sectionHeadingValue || '';
            if (quickHeading.value !== value) {
                quickHeading.value = value;
            }
        }
        if (quickVisible && !preserveQuickFields) {
            quickVisible.checked = card.dataset.sectionVisible === 'true';
        }
        if (quickWidth && !preserveQuickFields) {
            quickWidth.value = card.dataset.sectionWidthValue || 'contained';
        }
        if (quickSpacing && !preserveQuickFields) {
            quickSpacing.value = card.dataset.sectionSpacingValue || 'standard';
        }
        if (quickSchemes.length && !preserveQuickFields) {
            const schemeValue = card.dataset.sectionSchemeValue || '__inherit__';
            quickSchemes.forEach(function(control) {
                control.value = schemeValue;
                syncSchemePicker(control);
            });
        }
        if (quickFillHeight && !preserveQuickFields) {
            quickFillHeight.checked = card.dataset.sectionFillHeight === 'true';
        }
        if (quickHeadingRow) {
            quickHeadingRow.hidden = card.dataset.sectionHeadingEditable !== 'true';
        }
        if (quickFillHeightRow) {
            quickFillHeightRow.hidden = card.dataset.sectionFillHeightSupported !== 'true';
        }
        const motionPreviewRow = canvas.querySelector('[data-hucr-motion-preview-row]');
        const motionPreview = canvas.querySelector('[data-hucr-preview-motion]');
        if (motionPreviewRow) {
            motionPreviewRow.hidden = card.dataset.sectionMotionSupported !== 'true';
        }
        if (motionPreview) {
            motionPreview.disabled = card.dataset.sectionMotionEffect === 'none';
        }
        renderCanvasChecks(canvas, card.dataset.sectionChecks);
        if (focusCard) {
            card.focus({ preventScroll: true });
        }
    }

    function renderCanvasChecks(canvas, encodedChecks) {
        const container = canvas.querySelector('[data-hucr-selection-checks]');
        if (!container) {
            return;
        }
        let checks = [];
        try {
            checks = JSON.parse(encodedChecks || '[]');
        }
        catch (error) {
            checks = [{ message: 'Kontroly sekce se nepodařilo načíst.', severity: 'error' }];
        }
        checks = checks.map(function(check) {
            return typeof check === 'string' ? { message: check, severity: 'warning' } : check;
        });
        const severity = checks.some(function(check) { return check.severity === 'error'; })
            ? 'error'
            : (checks.length ? 'warning' : 'valid');
        const badge = canvas.querySelector('[data-hucr-checks-badge]');
        if (badge) {
            badge.hidden = checks.length === 0;
            badge.textContent = String(checks.length);
            badge.classList.remove('is-valid', 'is-warning', 'is-error');
            badge.classList.add('is-' + severity);
        }
        container.replaceChildren();
        if (!checks.length) {
            const valid = document.createElement('p');
            valid.className = 'hucr-canvas-checks__valid';
            valid.innerHTML = '<i class="icon-check" aria-hidden="true"></i> Nebyl nalezen žádný problém.';
            container.appendChild(valid);
            return;
        }
        const list = document.createElement('ul');
        list.className = 'hucr-canvas-checks';
        checks.forEach(function(check) {
            const item = document.createElement('li');
            item.className = 'is-' + (check.severity || 'warning');
            const icon = document.createElement('i');
            icon.className = check.severity === 'error' ? 'icon-ban' : 'icon-warning';
            icon.setAttribute('aria-hidden', 'true');
            const text = document.createElement('span');
            text.textContent = check.message || '';
            item.append(icon, text);
            list.appendChild(item);
        });
        container.appendChild(list);
    }

    document.addEventListener('click', function(event) {
        const preview = event.target.closest('[data-hucr-preview-motion]');
        const canvas = preview?.closest('[data-hucr-canvas]');
        const card = canvas?.querySelector('[data-hucr-section-card].is-selected');
        if (!preview || !card || preview.disabled || !card.animate
            || window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            return;
        }
        const keyframes = {
            fade: [{ opacity: 0 }, { opacity: 1 }],
            'fade-up': [{ opacity: 0, transform: 'translateY(16px)' }, { opacity: 1, transform: 'none' }],
            'fade-left': [{ opacity: 0, transform: 'translateX(-12px)' }, { opacity: 1, transform: 'none' }],
            'fade-right': [{ opacity: 0, transform: 'translateX(12px)' }, { opacity: 1, transform: 'none' }],
            'scale-in': [{ opacity: 0, transform: 'scale(.98)' }, { opacity: 1, transform: 'none' }],
        };
        const frames = keyframes[card.dataset.sectionMotionEffect];
        if (frames) {
            card.getAnimations().forEach(function(animation) { animation.cancel(); });
            card.animate(frames, {
                duration: Number(card.dataset.sectionMotionDurationMs) || 500,
                delay: Number(card.dataset.sectionMotionDelayMs) || 0,
                easing: 'cubic-bezier(.2, .7, .2, 1)',
                fill: 'backwards',
            });
        }
    });

    document.addEventListener('click', function(event) {
        const control = event.target.closest('[data-hucr-select-section]');
        const canvas = control?.closest('[data-hucr-canvas]');
        if (control && canvas) {
            canvas.hucrFlushQuickSave?.();
            selectCanvasSection(canvas, control.dataset.hucrSelectSection, false);
        }
    });

    document.addEventListener('dblclick', function(event) {
        const card = event.target.closest('[data-hucr-section-card]');
        const canvas = card?.closest('[data-hucr-canvas]');
        if (!card || !canvas || event.target.closest('[data-hucr-drag-section], [data-hucr-section-row] details')) {
            return;
        }
        selectCanvasSection(canvas, card.dataset.hucrSelectSection, false);
        card.closest('[data-hucr-section-row]')?.querySelector('[data-hucr-card-edit]')?.click();
    });

    document.addEventListener('click', function(event) {
        const badge = event.target.closest('[data-hucr-open-checks]');
        const row = badge?.closest('[data-hucr-section-row]');
        const card = row?.querySelector('[data-hucr-section-card]');
        const canvas = row?.closest('[data-hucr-canvas]');
        if (!badge || !card || !canvas) {
            return;
        }
        selectCanvasSection(canvas, card.dataset.hucrSelectSection, false);
        canvas.querySelector('[data-hucr-inspector-tab="checks"]')?.click();
    });

    document.addEventListener('click', function(event) {
        const clickedMenu = event.target.closest('.hucr-canvas-card__menu');
        const action = event.target.closest('.hucr-canvas-card__menu button');
        if (action) {
            action.closest('details')?.removeAttribute('open');
            return;
        }
        document.querySelectorAll('.hucr-canvas-card__menu[open]').forEach(function(menu) {
            if (!clickedMenu || menu !== clickedMenu) {
                menu.removeAttribute('open');
            }
        });
    });

    document.addEventListener('keydown', function(event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.hucr-canvas-card__menu[open]').forEach(function(menu) {
                menu.removeAttribute('open');
            });
        }
    });

    function syncSchemePicker(select) {
        if (!select?.id) {
            return;
        }
        document.querySelectorAll('[data-hucr-scheme-target="' + select.id + '"]').forEach(function(option) {
            option.checked = option.value === select.value;
        });
    }

    function applyCardScheme(card, background, foreground, label) {
        const indicator = card?.querySelector('.hucr-canvas-card__scheme');
        if (!indicator) {
            return;
        }
        indicator.style.removeProperty('--hucr-scheme-background');
        indicator.style.removeProperty('--hucr-scheme-foreground');
        if (background) {
            indicator.style.setProperty('--hucr-scheme-background', background);
        }
        if (foreground) {
            indicator.style.setProperty('--hucr-scheme-foreground', foreground);
        }
        indicator.classList.toggle('is-inherited', !background);
        indicator.title = 'Barevné schéma: ' + label;
        indicator.setAttribute('aria-label', indicator.title);
    }

    document.addEventListener('change', function(event) {
        const option = event.target.closest('[data-hucr-scheme-target]');
        if (!option) {
            return;
        }
        const select = document.getElementById(option.dataset.hucrSchemeTarget);
        if (!select || select.value === option.value) {
            return;
        }
        select.value = option.value;
        const canvas = select.closest('[data-hucr-canvas]');
        if (select.matches('[data-hucr-page-proxy="style[color_scheme]"]') && canvas) {
            const background = option.dataset.schemeBackground || '';
            const foreground = option.dataset.schemeForeground || '';
            const label = option.dataset.schemeTitle || option.value;
            canvas.querySelectorAll('[data-hucr-section-card]').forEach(function(card) {
                const inheritsFromPage = card.dataset.sectionSchemeInherited === 'true'
                    && ['page', 'default'].includes(card.dataset.sectionSchemeSource || 'default');
                if (inheritsFromPage) {
                    card.dataset.sectionScheme = label + (option.value === '__inherit__' ? '' : ' (zděděné)');
                    card.dataset.sectionSchemeSource = option.value === '__inherit__' ? 'default' : 'page';
                    applyCardScheme(card, background, foreground, card.dataset.sectionScheme);
                }
            });
        }
        select.dispatchEvent(new Event('input', { bubbles: true }));
        select.dispatchEvent(new Event('change', { bubbles: true }));
        if (select.matches('[data-hucr-quick-field]')) {
            canvas?.querySelector('[data-hucr-quick-save]')?.click();
        }
    });

    function hideCanvasRailTooltips(canvas) {
        if (!window.jQuery?.fn?.tooltip) {
            return;
        }
        window.jQuery(canvas).find('.hucr-canvas__panel-rail [title]').tooltip('hide');
    }

    function initializeCanvasRailTooltips(canvas) {
        if (!window.jQuery?.fn?.tooltip) {
            return;
        }
        window.jQuery(canvas).find('.hucr-canvas__panel-rail [title]').each(function() {
            const button = window.jQuery(this);
            button.off('.hucrCanvasTooltip');
            try {
                button.tooltip('dispose');
            }
            catch (error) {
                // The element may not have been initialized by October yet.
            }
            button.tooltip({
                container: 'body',
                delay: { show: 0, hide: 0 },
                placement: this.closest('.hucr-canvas__panel-rail--right') ? 'left' : 'right',
                trigger: 'manual',
            });
            button.on('mouseenter.hucrCanvasTooltip', function() {
                button.tooltip('show');
            });
            button.on('mouseleave.hucrCanvasTooltip mousedown.hucrCanvasTooltip blur.hucrCanvasTooltip', function() {
                button.tooltip('hide');
            });
        });
    }

    document.addEventListener('click', function(event) {
        if (!event.target.closest('[data-hucr-adaptive-popup]')) {
            return;
        }
        const knownDialogs = new Set(document.querySelectorAll('.control-popup .modal-dialog'));
        const observer = new MutationObserver(function() {
            const dialog = Array.from(document.querySelectorAll('.control-popup .modal-dialog'))
                .find(function(candidate) { return !knownDialogs.has(candidate); });
            if (!dialog) {
                return;
            }
            dialog.classList.remove('size-small', 'size-large', 'size-huge', 'size-giant');
            dialog.classList.add('size-adaptive');
            observer.disconnect();
        });
        observer.observe(document.body, { childList: true, subtree: true });
        window.setTimeout(function() { observer.disconnect(); }, 5000);
    });

    function setCanvasPanelCollapsed(canvas, panel, collapsed) {
        const className = panel === 'navigator' ? 'is-navigator-collapsed' : 'is-inspector-collapsed';
        canvas.classList.toggle(className, collapsed);
        canvas.classList.toggle('is-panels-collapsed',
            canvas.classList.contains('is-navigator-collapsed') && canvas.classList.contains('is-inspector-collapsed'));
        const editor = canvas.closest('[data-hucr-builder-editor]') || canvas;
        editor.querySelectorAll('[data-hucr-toggle-canvas-panel="' + panel + '"]').forEach(function(button) {
            button.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            const label = panel === 'navigator'
                ? (collapsed ? 'Rozbalit levý panel' : 'Sbalit levý panel')
                : (collapsed ? 'Rozbalit pravý panel' : 'Sbalit pravý panel');
            button.setAttribute('title', label);
            button.setAttribute('data-original-title', label);
            const icon = button.querySelector('i');
            if (icon) {
                const pointsRight = panel === 'navigator' ? collapsed : !collapsed;
                icon.className = pointsRight ? 'icon-chevron-right' : 'icon-chevron-left';
            }
        });
    }

    document.addEventListener('click', function(event) {
        const button = event.target.closest('[data-hucr-toggle-canvas-panel]');
        const canvas = button?.closest('[data-hucr-builder-editor]')?.querySelector('[data-hucr-canvas]');
        if (!button || !canvas) {
            return;
        }
        hideCanvasRailTooltips(canvas);
        const panel = button.dataset.hucrToggleCanvasPanel;
        const className = panel === 'navigator' ? 'is-navigator-collapsed' : 'is-inspector-collapsed';
        const collapsed = !canvas.classList.contains(className);
        setCanvasPanelCollapsed(canvas, panel, collapsed);
        button.closest('details')?.removeAttribute('open');
    });

    document.addEventListener('click', function(event) {
        const button = event.target.closest('[data-hucr-navigator-tool]');
        const canvas = button?.closest('[data-hucr-canvas]');
        if (!button || !canvas) {
            return;
        }
        hideCanvasRailTooltips(canvas);
        const name = button.dataset.hucrNavigatorTool;
        const section = canvas.querySelector('[data-hucr-left-section="' + name + '"]');
        const navigatorWasCollapsed = canvas.classList.contains('is-navigator-collapsed');
        if (navigatorWasCollapsed) {
            setCanvasPanelCollapsed(canvas, 'navigator', false);
        }
        canvas.querySelectorAll('[data-hucr-left-section]').forEach(function(candidate) {
            candidate.classList.toggle('is-collapsed', candidate !== section);
        });
        canvas.querySelectorAll('[data-hucr-navigator-tool]').forEach(function(candidate) {
            const active = candidate === button;
            candidate.classList.toggle('is-active', active);
            candidate.setAttribute('aria-pressed', active ? 'true' : 'false');
        });
    }, true);

    function setCanvasFullscreen(canvas, enabled) {
        canvasFullscreen = enabled;
        canvas.classList.toggle('is-fullscreen', enabled);
        document.body.classList.toggle('hucr-canvas-fullscreen-open', enabled);
        const editor = canvas.closest('[data-hucr-fullscreen-host]') || canvas.closest('[data-hucr-revision-editor]') || canvas.closest('[data-hucr-builder-editor]') || canvas;
        editor.classList.toggle('is-canvas-fullscreen', enabled);
        if (enabled) {
            const status = editor.querySelector('[data-hucr-revision-status]');
            editor.style.setProperty('--hucr-fullscreen-status-height', Math.ceil(status?.getBoundingClientRect().height || 0) + 'px');
        }
        else {
            editor.style.removeProperty('--hucr-fullscreen-status-height');
        }
        editor.querySelectorAll('[data-hucr-toggle-fullscreen]').forEach(function(button) {
            button.setAttribute('aria-pressed', enabled ? 'true' : 'false');
            button.querySelector('i').className = enabled ? 'icon-compress' : 'icon-expand';
            button.querySelector('span').textContent = enabled ? 'Ukončit celou obrazovku' : 'Celá obrazovka';
        });
    }

    document.addEventListener('click', function(event) {
        const button = event.target.closest('[data-hucr-toggle-fullscreen]');
        const canvas = button?.closest('[data-hucr-canvas]')
            || button?.closest('[data-hucr-fullscreen-host]')?.querySelector('[data-hucr-canvas]')
            || button?.closest('[data-hucr-revision-editor]')?.querySelector('[data-hucr-canvas]')
            || button?.closest('[data-hucr-builder-editor]')?.querySelector('[data-hucr-canvas]');
        if (button && canvas) {
            setCanvasFullscreen(canvas, !canvas.classList.contains('is-fullscreen'));
        }
    });

    window.addEventListener('resize', function() {
        const canvas = document.querySelector('[data-hucr-canvas].is-fullscreen');
        if (!canvas) {
            return;
        }
        const editor = canvas.closest('[data-hucr-fullscreen-host]') || canvas.closest('[data-hucr-revision-editor]') || canvas.closest('[data-hucr-builder-editor]');
        const status = editor?.querySelector('[data-hucr-revision-status]');
        if (editor && status) {
            editor.style.setProperty('--hucr-fullscreen-status-height', Math.ceil(status.getBoundingClientRect().height) + 'px');
        }
    });

    function openCatalog(canvas, position, containerUuid, widthUnits) {
        const tools = canvas.querySelector('[data-hucr-workspace-tools]');
        const catalog = canvas.querySelector('[data-hucr-catalog]');
        if (!tools || !catalog) {
            return;
        }
        canvas.dataset.catalogPosition = String(position || 1);
        canvas.dataset.catalogContainer = containerUuid || '';
        canvas.dataset.catalogWidthUnits = String(widthUnits || 4);
        canvas.classList.add('is-catalog-open');
        canvas.querySelectorAll('.hucr-canvas__insert').forEach(function(insert) {
            const selected = Number(insert.dataset.position) === Number(canvas.dataset.catalogPosition)
                && (insert.dataset.containerUuid || '') === canvas.dataset.catalogContainer;
            insert.classList.toggle('is-selected', selected);
            insert.setAttribute('aria-pressed', selected ? 'true' : 'false');
        });
        tools.hidden = true;
        catalog.hidden = false;
        const label = catalog.querySelector('[data-hucr-catalog-position]');
        if (label) {
            label.textContent = canvas.dataset.catalogPosition;
        }
        const search = catalog.querySelector('[data-hucr-catalog-search]');
        if (search) {
            search.value = '';
            catalog.querySelectorAll('[data-hucr-catalog-item]').forEach(function(item) {
                const inZone = Number(canvas.dataset.catalogWidthUnits) < 4;
                const allowed = !inZone || (item.dataset.allowedInColumns === 'true'
                    && Number(item.dataset.minimumWidthUnits || 1) <= Number(canvas.dataset.catalogWidthUnits));
                item.dataset.contextAllowed = allowed ? 'true' : 'false';
                item.hidden = !allowed;
            });
            search.focus();
        }
    }

    function closeCatalog(canvas) {
        const tools = canvas.querySelector('[data-hucr-workspace-tools]');
        const catalog = canvas.querySelector('[data-hucr-catalog]');
        canvas.classList.remove('is-catalog-open');
        delete canvas.dataset.catalogContainer;
        delete canvas.dataset.catalogWidthUnits;
        canvas.querySelectorAll('.hucr-canvas__insert').forEach(function(insert) {
            insert.classList.remove('is-selected');
            insert.setAttribute('aria-pressed', 'false');
        });
        if (tools) {
            tools.hidden = false;
        }
        if (catalog) {
            catalog.hidden = true;
        }
    }

    document.addEventListener('click', function(event) {
        const opener = event.target.closest('[data-hucr-open-catalog]');
        const canvas = opener?.closest('[data-hucr-canvas]');
        if (opener && canvas) {
            openCatalog(canvas, Number(opener.dataset.position), opener.dataset.containerUuid, Number(opener.dataset.widthUnits || 4));
            return;
        }
        const closer = event.target.closest('[data-hucr-close-catalog]');
        if (closer) {
            closeCatalog(closer.closest('[data-hucr-canvas]'));
        }
    });

    document.addEventListener('input', function(event) {
        if (event.target.matches('[data-hucr-page-proxy="title"]')) {
            event.target.closest('[data-hucr-canvas]')?.querySelectorAll('[data-hucr-page-context-title]').forEach(function(title) {
                title.textContent = event.target.value;
            });
        }
        if (event.target.matches('[data-hucr-page-search]')) {
            const query = event.target.value.trim().toLocaleLowerCase('cs');
            event.target.closest('.hucr-page-tree').querySelectorAll('.hucr-page-tree__level > li').forEach(function(item) {
                item.hidden = item.dataset.contextAllowed === 'false'
                    || (query !== '' && !item.textContent.toLocaleLowerCase('cs').includes(query));
            });
        }
        if (event.target.matches('[data-hucr-catalog-search]')) {
            const query = event.target.value.trim().toLocaleLowerCase('cs');
            event.target.closest('[data-hucr-catalog]').querySelectorAll('[data-hucr-catalog-item]').forEach(function(item) {
                item.hidden = query !== '' && !item.textContent.toLocaleLowerCase('cs').includes(query);
            });
        }
    });

    function selectInspectorTab(inspector, name) {
        inspector.querySelectorAll('[data-hucr-inspector-tab]').forEach(function(candidate) {
            const active = candidate.dataset.hucrInspectorTab === name;
            candidate.classList.toggle('is-active', active);
            candidate.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        inspector.querySelectorAll('[data-hucr-inspector-panel]').forEach(function(panel) {
            panel.hidden = panel.dataset.hucrInspectorPanel !== name;
        });
    }

    document.addEventListener('click', function(event) {
        const tab = event.target.closest('[data-hucr-inspector-tab]');
        const inspector = tab?.closest('.hucr-canvas__inspector');
        if (!tab || !inspector) {
            return;
        }
        hideCanvasRailTooltips(inspector.closest('[data-hucr-canvas]'));
        activeInspectorTab = tab.dataset.hucrInspectorTab;
        setCanvasPanelCollapsed(inspector.closest('[data-hucr-canvas]'), 'inspector', false);
        selectInspectorTab(inspector, activeInspectorTab);
    });

    document.addEventListener('keydown', function(event) {
        const tab = event.target.closest('[data-hucr-inspector-tab]');
        if (!tab || !['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            return;
        }
        const tabs = Array.from(tab.parentElement.querySelectorAll('[data-hucr-inspector-tab]'));
        let index = tabs.indexOf(tab);
        if (event.key === 'Home') {
            index = 0;
        }
        else if (event.key === 'End') {
            index = tabs.length - 1;
        }
        else {
            index = (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        }
        event.preventDefault();
        tabs[index].focus();
        tabs[index].click();
    });

    document.addEventListener('keydown', function(event) {
        const editable = event.target.matches('input, textarea, select, [contenteditable="true"]');
        const canvas = document.querySelector('[data-hucr-canvas]');
        if (!canvas) {
            return;
        }
        if (event.key === 'Escape' && canvas.classList.contains('is-catalog-open')) {
            event.preventDefault();
            closeCatalog(canvas);
        }
        else if (event.key === 'Escape' && canvas.classList.contains('is-fullscreen')) {
            event.preventDefault();
            setCanvasFullscreen(canvas, false);
        }
        else if (event.key === '/' && !editable) {
            event.preventDefault();
            openCatalog(canvas, canvas.querySelectorAll('[data-hucr-section-card]').length + 1);
        }
        else if (event.key.toLocaleLowerCase('cs') === 'f' && !editable && !event.metaKey && !event.ctrlKey && !event.altKey) {
            event.preventDefault();
            setCanvasFullscreen(canvas, !canvas.classList.contains('is-fullscreen'));
        }
        else if (!editable && (event.metaKey || event.ctrlKey) && event.key.toLocaleLowerCase('cs') === 'z') {
            const action = event.shiftKey
                ? revisionStatus()?.querySelector('[data-hucr-redo]')
                : revisionStatus()?.querySelector('[data-hucr-undo]');
            if (action && !action.disabled) {
                event.preventDefault();
                action.click();
            }
        }
        else if (!editable && event.ctrlKey && event.key.toLocaleLowerCase('cs') === 'y') {
            const action = revisionStatus()?.querySelector('[data-hucr-redo]');
            if (action && !action.disabled) {
                event.preventDefault();
                action.click();
            }
        }
    });

    let draggedSectionUuid = null;

    function submitCanvasOrder(canvas, ordered) {
        const input = canvas.querySelector('[data-hucr-canvas-order]');
        const submit = canvas.querySelector('[data-hucr-canvas-reorder-submit]');
        if (input && submit) {
            input.value = JSON.stringify(ordered);
            submit.click();
        }
    }

    function submitCanvasMove(canvas, uuid, containerUuid, position) {
        const uuidInput = canvas.querySelector('[data-hucr-canvas-move-uuid]');
        const containerInput = canvas.querySelector('[data-hucr-canvas-move-container]');
        const positionInput = canvas.querySelector('[data-hucr-canvas-move-position]');
        const submit = canvas.querySelector('[data-hucr-canvas-move-submit]');
        if (!uuidInput || !containerInput || !positionInput || !submit) {
            return;
        }
        uuidInput.value = uuid;
        containerInput.value = containerUuid;
        positionInput.value = String(position);
        submit.click();
    }

    document.addEventListener('dragstart', function(event) {
        const handle = event.target.closest('[data-hucr-drag-section]');
        const canvas = handle?.closest('[data-hucr-canvas]');
        if (!handle || !canvas) {
            return;
        }
        canvas.hucrFlushQuickSave?.();
        draggedSectionUuid = handle.dataset.hucrDragSection;
        handle.closest('[data-hucr-section-row]')?.querySelector('[data-hucr-section-card]')?.classList.add('is-dragging');
        event.dataTransfer.effectAllowed = 'move';
        event.dataTransfer.setData('text/plain', draggedSectionUuid);
    });

    document.addEventListener('dragover', function(event) {
        const target = event.target.closest('.hucr-canvas__insert[data-position]');
        const canvas = target?.closest('[data-hucr-canvas]');
        if (!target || !canvas || !draggedSectionUuid) {
            return;
        }
        event.preventDefault();
        event.dataTransfer.dropEffect = 'move';
        canvas.querySelectorAll('.hucr-canvas__insert.is-drop-target').forEach(function(candidate) {
            if (candidate !== target) {
                candidate.classList.remove('is-drop-target');
            }
        });
        target.classList.add('is-drop-target');
    });

    document.addEventListener('dragleave', function(event) {
        const target = event.target.closest('.hucr-canvas__insert[data-position]');
        if (target && !target.contains(event.relatedTarget)) {
            target.classList.remove('is-drop-target');
        }
    });

    document.addEventListener('drop', function(event) {
        const target = event.target.closest('.hucr-canvas__insert[data-position]');
        const canvas = target?.closest('[data-hucr-canvas]');
        if (!target || !canvas || !draggedSectionUuid) {
            return;
        }
        event.preventDefault();
        target.classList.remove('is-drop-target');
        const sourceCard = canvas.querySelector('[data-hucr-section-card][data-hucr-select-section="' + CSS.escape(draggedSectionUuid) + '"]');
        const targetContainer = target.dataset.containerUuid;
        let targetPosition = Math.max(1, Number(target.dataset.position));
        if (!sourceCard || !targetContainer) {
            return;
        }
        if (sourceCard.dataset.sectionContainer === targetContainer) {
            const siblings = Array.from(canvas.querySelectorAll('[data-hucr-section-card]')).filter(function(card) {
                return card.dataset.sectionContainer === targetContainer;
            });
            const sourcePosition = siblings.indexOf(sourceCard) + 1;
            if (sourcePosition < targetPosition) {
                targetPosition--;
            }
            if (sourcePosition === targetPosition) {
                return;
            }
        }
        submitCanvasMove(canvas, draggedSectionUuid, targetContainer, targetPosition);
    });

    document.addEventListener('dragend', function() {
        document.querySelectorAll('[data-hucr-section-card].is-dragging').forEach(function(card) {
            card.classList.remove('is-dragging');
        });
        document.querySelectorAll('.hucr-canvas__insert.is-drop-target').forEach(function(target) {
            target.classList.remove('is-drop-target');
        });
        draggedSectionUuid = null;
    });

    document.addEventListener('keydown', function(event) {
        const handle = event.target.closest('[data-hucr-drag-section]');
        if (handle && event.altKey && ['ArrowUp', 'ArrowDown'].includes(event.key)) {
            const canvas = handle.closest('[data-hucr-canvas]');
            const card = handle.closest('[data-hucr-section-row]')?.querySelector('[data-hucr-section-card]');
            const containerUuid = card?.dataset.sectionContainer;
            const siblings = Array.from(canvas.querySelectorAll('[data-hucr-section-card]')).filter(function(candidate) {
                return candidate.dataset.sectionContainer === containerUuid;
            });
            const index = siblings.indexOf(card);
            const target = event.key === 'ArrowUp' ? index - 1 : index + 1;
            if (index >= 0 && target >= 0 && target < siblings.length) {
                event.preventDefault();
                submitCanvasMove(canvas, handle.dataset.hucrDragSection, containerUuid, target + 1);
            }
            return;
        }
        const item = event.target.closest('.hucr-canvas__navigator-item');
        if (!item || !['ArrowUp', 'ArrowDown'].includes(event.key)) {
            return;
        }
        const items = Array.from(item.parentElement.querySelectorAll('.hucr-canvas__navigator-item'));
        const direction = event.key === 'ArrowDown' ? 1 : -1;
        const next = items[(items.indexOf(item) + direction + items.length) % items.length];
        event.preventDefault();
        next.focus();
        selectCanvasSection(item.closest('[data-hucr-canvas]'), next.dataset.hucrSelectSection, false);
    });

    function initializePageSettingProxies(canvas) {
        const form = canvas.closest('form[data-hucr-revision-editor]');
        if (!form) {
            return;
        }
        const fieldNames = ['title', 'slug', 'parent', 'is_home', 'is_published', 'fullslug', 'style[color_scheme]', 'meta_title', 'meta_description'];
        let ready = true;
        fieldNames.forEach(function(fieldName) {
            const proxy = canvas.querySelector('[data-hucr-page-proxy="' + fieldName + '"]');
            const sourceGroup = Array.from(form.querySelectorAll('[data-field-name]'))
                .find(function(group) { return group.dataset.fieldName === fieldName; });
            if (!proxy || !sourceGroup) {
                ready = false;
                return;
            }
            const namedControls = Array.from(sourceGroup.querySelectorAll('[name]'));
            const checkbox = sourceGroup.querySelector('input[type="checkbox"]');
            const select = sourceGroup.querySelector('select');
            const radios = Array.from(sourceGroup.querySelectorAll('input[type="radio"]'));
            const textControl = sourceGroup.querySelector('textarea, input:not([type="hidden"]):not([type="checkbox"]):not([type="radio"])');
            const sourceName = (checkbox || select || radios[0] || textControl || namedControls[0])?.name;

            if (radios.length && proxy.matches('select')) {
                proxy.replaceChildren();
                radios.forEach(function(radio) {
                    const option = document.createElement('option');
                    option.value = radio.value;
                    option.textContent = radio.closest('.form-check')?.querySelector('.name')?.textContent?.trim() || radio.value;
                    option.selected = radio.checked;
                    proxy.appendChild(option);
                });
                syncSchemePicker(proxy);
            }
            else if (select && proxy.matches('select')) {
                proxy.innerHTML = select.innerHTML;
                proxy.value = select.value;
            }
            else if (checkbox && proxy.matches('input[type="checkbox"]')) {
                proxy.checked = checkbox.checked;
            }
            else if (textControl) {
                proxy.value = textControl.value;
            }

            if (fieldName !== 'fullslug' && sourceName) {
                proxy.name = sourceName;
                const hiddenProxy = canvas.querySelector('[data-hucr-page-proxy-hidden="' + fieldName + '"]');
                if (hiddenProxy) {
                    hiddenProxy.name = sourceName;
                }
            }
            namedControls.forEach(function(control) { control.disabled = true; });

            function syncSource() {
                if (radios.length) {
                    radios.forEach(function(radio) { radio.checked = radio.value === proxy.value; });
                }
                else if (select) {
                    select.value = proxy.value;
                }
                else if (checkbox) {
                    checkbox.checked = proxy.checked;
                }
                else if (textControl) {
                    textControl.value = proxy.value;
                }
            }
            proxy.addEventListener('input', syncSource);
            proxy.addEventListener('change', syncSource);
        });
        form.classList.toggle('hucr-page-inspector-ready', ready);
    }

    function initializeCanvases(root) {
        root.querySelectorAll('[data-hucr-canvas]').forEach(function(canvas) {
            if (canvas.dataset.hucrCanvasReady === 'true') {
                return;
            }
            canvas.dataset.hucrCanvasReady = 'true';
            initializeCanvasRailTooltips(canvas);
            initializePageSettingProxies(canvas);
            if (canvasFullscreen) {
                setCanvasFullscreen(canvas, true);
            }
            const selected = canvas.querySelector('.hucr-canvas__navigator-item.is-selected')
                || canvas.querySelector('.hucr-canvas__navigator-item');
            if (selected) {
                selectCanvasSection(canvas, selected.dataset.hucrSelectSection, false);
            }
            const inspector = canvas.querySelector('.hucr-canvas__inspector');
            if (inspector) {
                selectInspectorTab(inspector, activeInspectorTab);
            }
            const quickSave = canvas.querySelector('[data-hucr-quick-save]');
            let quickSaveTimer = null;
            let quickSavePending = false;
            function flushQuickSave() {
                if (!quickSavePending || !quickSave) {
                    return;
                }
                window.clearTimeout(quickSaveTimer);
                quickSaveTimer = null;
                quickSavePending = false;
                quickSave.click();
            }
            canvas.hucrFlushQuickSave = flushQuickSave;
            quickSave?.addEventListener('click', function() {
                window.clearTimeout(quickSaveTimer);
                quickSaveTimer = null;
                quickSavePending = false;
            });
            function scheduleQuickSave(event) {
                if (!event.isTrusted || !quickSave) {
                    return;
                }
                window.clearTimeout(quickSaveTimer);
                const title = canvas.querySelector('[data-hucr-quick-field="title"]');
                if (!title || !title.value.trim()) {
                    return;
                }
                const saveStatus = revisionStatus()?.querySelector('[data-hucr-save-status]');
                if (saveStatus) {
                    saveStatus.textContent = 'Čeká na automatické uložení sekce…';
                }
                quickSavePending = true;
                quickSaveTimer = window.setTimeout(flushQuickSave, 2500);
            }
            canvas.querySelectorAll('[data-hucr-quick-field]').forEach(function(field) {
                field.addEventListener('input', scheduleQuickSave);
                field.addEventListener('change', scheduleQuickSave);
            });
        });
    }

    document.addEventListener('hucr:canvas-refresh', function() {
        initializeCanvases(document);
    });

    document.addEventListener('hucr:canvas-section-updated', function(event) {
        const section = event.detail?.section;
        if (!section?.uuid) {
            return;
        }
        document.querySelectorAll('[data-hucr-canvas]').forEach(function(canvas) {
            const card = Array.from(canvas.querySelectorAll('[data-hucr-section-card]'))
                .find(function(candidate) { return candidate.dataset.hucrSelectSection === section.uuid; });
            const nav = Array.from(canvas.querySelectorAll('.hucr-canvas__navigator-item'))
                .find(function(candidate) { return candidate.dataset.hucrSelectSection === section.uuid; });
            if (!card || !nav) {
                return;
            }

            card.dataset.sectionTitle = section.title || '';
            card.dataset.sectionHeading = section.heading || '';
            card.dataset.sectionHeadingValue = section.heading_value || '';
            card.dataset.sectionText = section.text || '';
            card.dataset.sectionStatus = section.visible ? 'Viditelná' : 'Skrytá';
            card.dataset.sectionVisible = section.visible ? 'true' : 'false';
            card.dataset.sectionWidth = section.width || '';
            card.dataset.sectionWidthValue = section.width_value || 'contained';
            card.dataset.sectionSpacing = section.spacing || '';
            card.dataset.sectionSpacingValue = section.spacing_value || 'standard';
            card.dataset.sectionScheme = section.color_scheme || '';
            card.dataset.sectionSchemeValue = section.color_scheme_value || '';
            card.dataset.sectionSchemeInherited = section.color_scheme_inherited ? 'true' : 'false';
            card.dataset.sectionSchemeSource = section.color_scheme_source || 'default';
            card.dataset.sectionFillHeight = section.fill_height ? 'true' : 'false';
            card.dataset.sectionFillHeightSupported = section.supports_fill_height && section.nested ? 'true' : 'false';
            card.dataset.sectionMotion = section.motion_label || 'Bez efektu';
            card.dataset.sectionMotionSupported = section.motion_supported ? 'true' : 'false';
            card.dataset.sectionMotionEffect = section.motion_effect || 'none';
            card.dataset.sectionMotionDurationMs = section.motion_duration_ms || 500;
            card.dataset.sectionMotionDelayMs = section.motion_delay_ms || 0;
            card.dataset.sectionSource = section.shared_source || '';
            card.dataset.sectionCount = section.item_count ?? '';
            card.dataset.sectionHeadingEditable = section.heading_editable ? 'true' : 'false';
            card.dataset.sectionChecks = JSON.stringify(section.checks || []);
            card.classList.toggle('is-hidden', !section.visible);

            const cardHeading = card.querySelector('[data-hucr-card-heading]');
            const cardHidden = card.querySelector('[data-hucr-card-hidden]');
            const navTitle = nav.querySelector('[data-hucr-nav-title]');
            const navHidden = nav.querySelector('[data-hucr-nav-hidden]');
            const warning = card.closest('[data-hucr-section-row]')?.querySelector('[data-hucr-card-warning]');
            applyCardScheme(
                card,
                section.color_scheme_background || '',
                section.color_scheme_foreground || '',
                section.color_scheme || ''
            );
            if (cardHeading) {
                cardHeading.textContent = section.heading || '';
            }
            if (cardHidden) {
                cardHidden.hidden = Boolean(section.visible);
            }
            if (navTitle) {
                navTitle.textContent = section.title || '';
            }
            if (navHidden) {
                navHidden.hidden = Boolean(section.visible);
            }
            if (warning) {
                warning.hidden = !(section.checks || []).length;
                warning.querySelector('span').textContent = String((section.checks || []).length);
                warning.setAttribute('aria-label', 'Otevřít ' + String((section.checks || []).length) + ' nalezených problémů v kontrolách sekce');
                const severity = section.checks_severity || 'valid';
                warning.dataset.severity = severity;
                warning.classList.remove('is-valid', 'is-warning', 'is-error');
                warning.classList.add('is-' + severity);
            }
            const selected = canvas.querySelector('[data-hucr-section-card].is-selected');
            if (selected?.dataset.hucrSelectSection === section.uuid) {
                selectCanvasSection(canvas, section.uuid, false, true);
            }
        });
    });

    if (window.jQuery) {
        window.jQuery(document).on('ajax:done.hucrCanvas', function() {
            window.setTimeout(function() { initializeCanvases(document); }, 0);
        });
    }

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
        const draftEnabledActions = form.querySelectorAll('[data-hucr-requires-draft-enabled]');
        let autosaveTimer = null;
        let saving = false;

        function markAsDraft() {
            showDraftState(revisionStatus);
            draftActions.forEach(function(action) { action.hidden = false; });
            draftEnabledActions.forEach(function(action) { action.disabled = false; });
        }

        function scheduleAutosave(event) {
            // October widgets emit synthetic change events while hydrating after a reload.
            // They must never create a phantom draft immediately after publication.
            const canvasField = event.target.closest('[data-hucr-canvas]');
            const pageSetting = event.target.closest('[data-hucr-page-settings]');
            if (!event.isTrusted || !saveButton || saving || event.target.closest('[data-control="popup"]') || (canvasField && !pageSetting)) {
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
                        window.setTimeout(function() {
                            form.querySelector('[data-hucr-refresh-publication-preflight]')?.click();
                        }, 0);
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
        initializeCanvases(document);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan, { once: true });
    }
    else {
        scan();
    }
})();
