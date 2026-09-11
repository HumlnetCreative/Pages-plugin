(function($) {
    'use strict';

    if (!$.FroalaEditor || $.FroalaEditor.COMMANDS.insertCountUp) {
        return;
    }

    var numberPattern = /^[+\-−]?(?:\d{1,3}(?:[ \u00a0\u202f]\d{3})+|\d+)(?:[,.]\d{1,3})?$/;
    var popover = null;
    var activeMarker = null;
    var activeEditor = null;

    function flash(text) {
        if (window.oc && window.oc.flashMsg) window.oc.flashMsg({ text: text, class: 'error' });
    }

    function editorFor(marker) {
        return ($.FroalaEditor.INSTANCES || []).find(function(editor) {
            return editor.$el && editor.$el[0] && editor.$el[0].contains(marker);
        }) || null;
    }

    function preview(marker, duration) {
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;
        var finalText = marker.textContent.trim();
        var normalized = finalText.replace(/[ \u00a0\u202f]/g, '').replace('−', '-').replace(',', '.');
        var end = Number(normalized);
        if (!Number.isFinite(end)) return;
        var decimals = (normalized.split('.')[1] || '').length;
        var separator = finalText.indexOf(',') >= 0 ? ',' : '.';
        var groupMatch = finalText.match(/[ \u00a0\u202f]/);
        var group = groupMatch ? groupMatch[0] : '';
        var started = performance.now();
        function frame(now) {
            var progress = Math.min(1, (now - started) / duration);
            var parts = Math.abs(end * (1 - Math.pow(1 - progress, 3))).toFixed(decimals).split('.');
            if (group) parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, group);
            marker.textContent = (end < 0 ? '−' : '') + parts[0] + (decimals ? separator + parts[1] : '');
            if (progress < 1) requestAnimationFrame(frame);
            else marker.textContent = finalText;
        }
        requestAnimationFrame(frame);
    }

    function closePopover() {
        if (popover) popover.hidden = true;
        activeMarker = null;
        activeEditor = null;
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
            if (event.target.closest('[data-hucr-rich-count-up-remove]') && activeMarker && activeEditor) {
                var text = document.createTextNode(activeMarker.textContent);
                activeEditor.undo.saveStep();
                activeMarker.replaceWith(text);
                activeEditor.undo.saveStep();
                activeEditor.events.trigger('contentChanged');
                closePopover();
            }
            if (event.target.closest('[data-hucr-rich-count-up-close]')) closePopover();
        });
        return popover;
    }

    document.addEventListener('click', function(event) {
        var marker = event.target.closest('.fr-view [data-hucr-count-up]');
        if (!marker) {
            if (!event.target.closest('.hucr-rich-count-up-popover')) closePopover();
            return;
        }
        var editor = editorFor(marker);
        if (!editor) return;
        activeMarker = marker;
        activeEditor = editor;
        var panel = ensurePopover();
        panel.querySelector('[data-hucr-rich-count-up-label]').textContent = marker.textContent;
        var rect = marker.getBoundingClientRect();
        panel.style.left = Math.max(8, Math.min(rect.left, window.innerWidth - 270)) + window.scrollX + 'px';
        panel.style.top = rect.bottom + 6 + window.scrollY + 'px';
        panel.hidden = false;
    });

    $.FroalaEditor.DefineIcon('insertCountUp', { NAME: 'sort-numeric-asc' });
    $.FroalaEditor.RegisterCommand('insertCountUp', {
        title: 'Počítadlo',
        undo: true,
        focus: true,
        callback: function() {
            var current = $(this.selection.element()).closest('[data-hucr-count-up]', this.$el);
            if (current.length) {
                current.replaceWith(document.createTextNode(current.text()));
                this.events.trigger('contentChanged');
                return;
            }
            var text = this.selection.text().trim();
            if (!numberPattern.test(text)) {
                flash('Označte přesně jednu číselnou hodnotu.');
                return;
            }
            this.html.insert('<span data-hucr-count-up>'+$('<div>').text(text).html()+'</span>');
            this.undo.saveStep();
            this.events.trigger('contentChanged');
        },
    });
})(jQuery);
