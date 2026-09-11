(function() {
    'use strict';

    var numberPattern = /^[+\-−]?(?:\d{1,3}(?:[ \u00a0\u202f]\d{3})+|\d+)(?:[,.]\d{1,3})?$/;

    function init(root) {
        (root || document).querySelectorAll('[data-hucr-count-up-text]:not([data-hucr-initialized])').forEach(function(widget) {
            widget.dataset.hucrInitialized = '1';
            var editor = widget.querySelector('[data-hucr-count-up-editor]');
            var value = widget.querySelector('[data-hucr-count-up-value]');
            var popover = widget.querySelector('[data-hucr-count-up-popover]');
            var active = null;

            function sync() {
                value.value = editor.innerHTML;
                value.dispatchEvent(new Event('input', { bubbles: true }));
            }
            function close() {
                active = null;
                if (popover) popover.hidden = true;
            }
            function open(marker) {
                active = marker;
                if (!popover) return;
                popover.querySelector('[data-hucr-count-up-label]').textContent = marker.textContent;
                popover.hidden = false;
            }

            editor.addEventListener('input', sync);
            editor.addEventListener('keydown', function(event) {
                if (event.key === 'Enter') event.preventDefault();
            });
            editor.addEventListener('paste', function(event) {
                event.preventDefault();
                document.execCommand('insertText', false, event.clipboardData.getData('text/plain').replace(/[\r\n]+/g, ' '));
            });
            editor.addEventListener('click', function(event) {
                var marker = event.target.closest('[data-hucr-count-up]');
                marker ? open(marker) : close();
            });
            widget.querySelector('[data-hucr-count-up-wrap]')?.addEventListener('mousedown', function(event) {
                event.preventDefault();
                var selection = window.getSelection();
                if (!selection || selection.rangeCount !== 1 || selection.isCollapsed) return;
                var range = selection.getRangeAt(0);
                if (!editor.contains(range.commonAncestorContainer)) return;
                var text = selection.toString().trim();
                if (!numberPattern.test(text) || range.cloneContents().querySelector?.('*')) {
                    window.oc?.flashMsg({ text: 'Označte přesně jednu číselnou hodnotu.', class: 'error' });
                    return;
                }
                var marker = document.createElement('span');
                marker.setAttribute('data-hucr-count-up', '');
                marker.textContent = text;
                range.deleteContents();
                range.insertNode(marker);
                selection.removeAllRanges();
                sync();
                open(marker);
            });
            widget.querySelector('[data-hucr-count-up-remove]')?.addEventListener('click', function() {
                if (!active) return;
                active.replaceWith(document.createTextNode(active.textContent));
                sync();
                close();
            });
            widget.querySelector('[data-hucr-count-up-preview]')?.addEventListener('click', function() {
                if (active) preview(active, 1200);
            });
            widget.querySelector('[data-hucr-count-up-close]')?.addEventListener('click', close);
        });
    }

    function preview(element, duration) {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        var finalText = element.textContent;
        var normalized = finalText.replace(/[ \u00a0\u202f]/g, '').replace('−', '-').replace(',', '.');
        var end = Number(normalized);
        if (!Number.isFinite(end)) return;
        var decimals = (normalized.split('.')[1] || '').length;
        var separator = finalText.includes(',') ? ',' : '.';
        var startTime = performance.now();
        function frame(now) {
            var progress = Math.min(1, (now - startTime) / duration);
            var value = end * (1 - Math.pow(1 - progress, 3));
            element.textContent = value.toFixed(decimals).replace('.', separator);
            if (progress < 1) requestAnimationFrame(frame);
            else element.textContent = finalText;
        }
        requestAnimationFrame(frame);
    }

    document.addEventListener('DOMContentLoaded', function() { init(document); });
    document.addEventListener('ajaxUpdateComplete', function() { init(document); });
})();
