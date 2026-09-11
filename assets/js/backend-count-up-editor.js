(function() {
    'use strict';

    if (window.HucrRichCountUpEditor) return;

    var numberPattern = /^[+\-−]?(?:\d{1,3}(?:[ \u00a0\u202f]\d{3})+|\d+)(?:[,.]\d{1,3})?$/;
    var bindings = new WeakMap();
    var pending = new WeakSet();
    var scanScheduled = false;
    var popover = null;
    var activeMarker = null;
    var activeBinding = null;
    var previewClone = null;
    var previewMarker = null;

    function flash(text) {
        if (window.oc && typeof window.oc.flashMsg === 'function') {
            window.oc.flashMsg({ text: text, class: 'error' });
        }
    }

    function editorFor(binding) {
        return binding && binding.control && typeof binding.control.getEditor === 'function'
            ? binding.control.getEditor()
            : null;
    }

    function ensureButton(toolbar) {
        var matches = [];
        toolbar.forEach(function(item, index) {
            if (item && item.command === 'insertCountUp') matches.push(index);
        });
        for (var index = matches.length - 1; index > 0; index--) toolbar.splice(matches[index], 1);
        if (matches.length) return;
        toolbar.push({
            type: 'button',
            icon: 'icon-sort-numeric-asc',
            command: 'insertCountUp',
            tooltip: 'Počítadlo',
            uniqueKey: 'hucr-insert-count-up',
        });
    }

    function bind(field) {
        if (!field.isConnected || pending.has(field)) return;
        pending.add(field);

        function attempt(remaining) {
            pending.delete(field);
            if (!field.isConnected) return;
            var controlElement = field.querySelector('[data-control~="richeditor"]');
            var api = window.oc;
            var control = api && typeof api.fetchControl === 'function' && controlElement
                ? api.fetchControl(controlElement, 'richeditor')
                : null;
            var connector = control && control.vueWidget && control.vueWidget.connectorInstance;
            var editor = control && typeof control.getEditor === 'function' ? control.getEditor() : null;
            var toolbar = connector && connector.toolbarExtensionPoint;
            if (!editor || !Array.isArray(toolbar)) {
                if (remaining > 0) {
                    pending.add(field);
                    window.setTimeout(function() { attempt(remaining - 1); }, 50);
                }
                return;
            }

            ensureButton(toolbar);
            bindings.set(field, { control: control, connector: connector });
        }

        attempt(80);
    }

    function scan(root) {
        root = root || document;
        if (root.matches && root.matches('[data-hucr-count-up-rich-editor]')) bind(root);
        root.querySelectorAll('[data-hucr-count-up-rich-editor]').forEach(bind);
    }

    function scheduleFullScan() {
        if (scanScheduled) return;
        scanScheduled = true;
        window.setTimeout(function() {
            scanScheduled = false;
            scan(document);
        }, 0);
    }

    function closestMarker(node, editor) {
        var element = node && node.nodeType === Node.ELEMENT_NODE ? node : node && node.parentElement;
        var marker = element && element.closest('[data-hucr-count-up]');
        return marker && editor.el && editor.el.contains(marker) ? marker : null;
    }

    function insertMarker(binding) {
        var editor = editorFor(binding);
        if (!editor) return;
        var current = closestMarker(editor.selection.element(), editor);
        if (current) {
            openPopover(current, binding);
            return;
        }

        var selected = editor.selection.text();
        if (typeof selected !== 'string' || selected !== selected.trim() || !numberPattern.test(selected)) {
            flash('Označte přesně jednu číselnou hodnotu.');
            return;
        }

        editor.undo.saveStep();
        editor.html.insert('<span data-hucr-count-up>'+escapeHtml(selected)+'</span>');
        editor.undo.saveStep();
        editor.events.trigger('contentChanged');
    }

    function escapeHtml(value) {
        var element = document.createElement('div');
        element.textContent = value;
        return element.innerHTML;
    }

    function removeMarker() {
        var editor = editorFor(activeBinding);
        if (!activeMarker || !editor || !editor.el.contains(activeMarker)) {
            closePopover();
            return;
        }
        editor.undo.saveStep();
        activeMarker.replaceWith(document.createTextNode(activeMarker.textContent));
        editor.undo.saveStep();
        editor.events.trigger('contentChanged');
        closePopover();
    }

    function finishPreview() {
        if (previewClone) previewClone.remove();
        previewClone = null;
        if (previewMarker) previewMarker.removeAttribute('data-hucr-preview-hidden');
        previewMarker = null;
    }

    function preview(marker, duration) {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        finishPreview();
        var finalText = marker.textContent.trim();
        var normalized = finalText.replace(/[ \u00a0\u202f]/g, '').replace('−', '-').replace(',', '.');
        var end = Number(normalized);
        if (!Number.isFinite(end)) return;
        var decimals = (normalized.split('.')[1] || '').length;
        var separator = finalText.indexOf(',') >= 0 ? ',' : '.';
        var groupMatch = finalText.match(/[ \u00a0\u202f]/);
        var group = groupMatch ? groupMatch[0] : '';
        var rect = marker.getBoundingClientRect();
        var clone = marker.cloneNode(true);
        clone.classList.add('hucr-rich-count-up-preview-clone');
        clone.style.left = rect.left + window.scrollX + 'px';
        clone.style.top = rect.top + window.scrollY + 'px';
        clone.style.width = rect.width + 'px';
        clone.style.height = rect.height + 'px';
        previewMarker = marker;
        marker.setAttribute('data-hucr-preview-hidden', '');
        document.body.appendChild(clone);
        previewClone = clone;
        var started = performance.now();
        function frame(now) {
            if (previewClone !== clone) return;
            var progress = Math.min(1, (now - started) / duration);
            var parts = Math.abs(end * (1 - Math.pow(1 - progress, 3))).toFixed(decimals).split('.');
            if (group) parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, group);
            clone.textContent = (end < 0 ? '−' : '') + parts[0] + (decimals ? separator + parts[1] : '');
            if (progress < 1) requestAnimationFrame(frame);
            else finishPreview();
        }
        requestAnimationFrame(frame);
    }

    function closePopover() {
        finishPreview();
        if (popover) popover.hidden = true;
        activeMarker = null;
        activeBinding = null;
    }

    function ensurePopover() {
        if (popover) return popover;
        popover = document.createElement('div');
        popover.className = 'hucr-rich-count-up-popover';
        popover.hidden = true;
        popover.innerHTML = '<strong data-hucr-rich-count-up-label></strong>'
            + '<button type="button" class="btn btn-default btn-sm" data-hucr-rich-count-up-preview>Přehrát</button>'
            + '<button type="button" class="btn btn-danger btn-sm" data-hucr-rich-count-up-remove>Odebrat</button>'
            + '<button type="button" class="btn btn-link btn-sm" data-hucr-rich-count-up-close aria-label="Zavřít">×</button>';
        document.body.appendChild(popover);
        popover.addEventListener('click', function(event) {
            if (event.target.closest('[data-hucr-rich-count-up-preview]') && activeMarker) preview(activeMarker, 1200);
            if (event.target.closest('[data-hucr-rich-count-up-remove]')) removeMarker();
            if (event.target.closest('[data-hucr-rich-count-up-close]')) closePopover();
        });
        return popover;
    }

    function openPopover(marker, binding) {
        activeMarker = marker;
        activeBinding = binding;
        var panel = ensurePopover();
        panel.querySelector('[data-hucr-rich-count-up-label]').textContent = marker.textContent;
        var rect = marker.getBoundingClientRect();
        panel.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 270)) + window.scrollX + 'px';
        panel.style.top = rect.bottom + 6 + window.scrollY + 'px';
        panel.hidden = false;
    }

    document.addEventListener('mousedown', function(event) {
        if (event.target.closest('[data-cmd="insertCountUp"]')) event.preventDefault();
    }, true);

    document.addEventListener('click', function(event) {
        var button = event.target.closest('[data-hucr-count-up-rich-editor] [data-cmd="insertCountUp"]');
        if (button) {
            event.preventDefault();
            event.stopImmediatePropagation();
            var field = button.closest('[data-hucr-count-up-rich-editor]');
            insertMarker(bindings.get(field));
            return;
        }

        var marker = event.target.closest('[data-hucr-count-up-rich-editor] .fr-view [data-hucr-count-up]');
        if (marker) {
            var markerField = marker.closest('[data-hucr-count-up-rich-editor]');
            openPopover(marker, bindings.get(markerField));
            return;
        }
        if (!event.target.closest('.hucr-rich-count-up-popover')) closePopover();
    }, true);

    window.HucrRichCountUpEditor = { scan: scan };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function() { scan(document); }, { once: true });
    else scan(document);
    document.addEventListener('ajax:update-complete', scheduleFullScan);
    document.addEventListener('ajaxUpdateComplete', scheduleFullScan);
    new MutationObserver(function() {
        scheduleFullScan();
    }).observe(document.documentElement, { childList: true, subtree: true });
})();
