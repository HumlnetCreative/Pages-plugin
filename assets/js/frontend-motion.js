(function() {
    'use strict';

    if (window.HucrSectionMotion) return;

    var reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
    var observer = null;
    var actions = new WeakMap();
    var counters = [];

    function query(root, selector) {
        var result = root.matches && root.matches(selector) ? [root] : [];
        return result.concat(Array.prototype.slice.call(root.querySelectorAll(selector)));
    }

    function visibleNow(element) {
        var rect = element.getBoundingClientRect();
        return rect.top <= window.innerHeight && rect.bottom >= 0;
    }

    function reveal(element) {
        element.classList.remove('is-hucr-motion-pending');
        element.classList.add('is-hucr-motion-visible');
    }

    function parseCounter(element) {
        var finalText = element.textContent.trim();
        var rawEnd = element.dataset.hucrCountUpEnd || finalText;
        var normalized = rawEnd.replace(/[ \u00a0\u202f]/g, '').replace('−', '-').replace(',', '.');
        var end = Number(normalized);
        var start = element.dataset.hucrCountUpStart === undefined ? 0 : Number(element.dataset.hucrCountUpStart);
        var decimals = element.dataset.hucrCountUpDecimals === undefined
            ? ((normalized.split('.')[1] || '').length)
            : Number(element.dataset.hucrCountUpDecimals);
        if (!Number.isFinite(start) || !Number.isFinite(end) || !Number.isInteger(decimals)
            || decimals < 0 || decimals > 3 || Math.abs(end) > 1e12) return null;

        var spaceMatch = finalText.match(/[ \u00a0\u202f]/);
        return {
            element: element,
            finalText: finalText,
            start: start,
            end: end,
            decimals: decimals,
            decimalSeparator: finalText.includes(',') ? ',' : '.',
            groupSeparator: spaceMatch ? spaceMatch[0] : '',
            hadAriaLabel: element.hasAttribute('aria-label'),
            ariaLabel: element.getAttribute('aria-label'),
            state: 'new',
            frame: null,
        };
    }

    function format(counter, value) {
        var fixed = Math.abs(value).toFixed(counter.decimals).split('.');
        if (counter.groupSeparator) fixed[0] = fixed[0].replace(/\B(?=(\d{3})+(?!\d))/g, counter.groupSeparator);
        return (value < 0 ? '−' : '') + fixed[0] + (counter.decimals ? counter.decimalSeparator + fixed[1] : '');
    }

    function prepare(counter) {
        if (counter.state !== 'new') return;
        var width = counter.element.getBoundingClientRect().width;
        counter.element.style.minWidth = width ? width + 'px' : '';
        counter.element.style.display = 'inline-block';
        if (!counter.hadAriaLabel) counter.element.setAttribute('aria-label', counter.finalText);
        counter.element.textContent = format(counter, counter.start);
        counter.state = 'prepared';
    }

    function finish(counter) {
        if (counter.state === 'finished') return;
        if (counter.frame) cancelAnimationFrame(counter.frame);
        counter.element.textContent = counter.finalText;
        counter.element.style.removeProperty('min-width');
        counter.element.style.removeProperty('display');
        if (!counter.hadAriaLabel) counter.element.removeAttribute('aria-label');
        else counter.element.setAttribute('aria-label', counter.ariaLabel);
        counter.state = 'finished';
    }

    function start(counter, duration, delay) {
        if (counter.state === 'finished' || counter.state === 'running') return;
        prepare(counter);
        counter.state = 'running';
        window.setTimeout(function() {
            if (counter.state !== 'running') return;
            var started = performance.now();
            function frame(now) {
                var progress = Math.min(1, (now - started) / duration);
                var eased = 1 - Math.pow(1 - progress, 3);
                counter.element.textContent = format(counter, counter.start + (counter.end - counter.start) * eased);
                if (progress < 1) counter.frame = requestAnimationFrame(frame);
                else finish(counter);
            }
            counter.frame = requestAnimationFrame(frame);
        }, delay || 0);
    }

    function sectionTargets(section) {
        if (!section.dataset.hucrMotion) return [];
        if (section.dataset.hucrMotionStagger !== '1') return [section];
        var items = Array.prototype.slice.call(section.querySelectorAll('[data-hucr-motion-item]'));
        return items.length > 0 && items.length <= 7 ? items : [section];
    }

    function activate(element) {
        var action = actions.get(element);
        if (action) action();
        if (observer) observer.unobserve(element);
    }

    function observe(element, ownedCounters) {
        if (visibleNow(element)) {
            activate(element);
            return;
        }
        try {
            observer.observe(element);
            ownedCounters.forEach(prepare);
            if (element.dataset.hucrMotion !== undefined || element.closest('[data-hucr-motion]')) {
                element.classList.add('is-hucr-motion-pending');
            }
        }
        catch (error) {
            ownedCounters.forEach(finish);
            reveal(element);
        }
    }

    function register(section) {
        if (section.dataset.hucrMotionInitialized === '1') return;
        section.dataset.hucrMotionInitialized = '1';
        var countUpEnabled = section.dataset.hucrMotionCountUp !== undefined;
        var duration = { fast: 1200, normal: 2000, slow: 4000 }[section.dataset.hucrMotionCountUp] || 2000;
        var parsed = countUpEnabled
            ? query(section, '[data-hucr-count-up]').map(parseCounter).filter(Boolean)
            : [];
        parsed.forEach(function(counter) { counters.push(counter); });
        var targets = sectionTargets(section);

        if (!targets.length) {
            parsed.forEach(function(counter) {
                actions.set(counter.element, function() { start(counter, duration, 0); });
                observe(counter.element, [counter]);
            });
            return;
        }

        targets.forEach(function(target, index) {
            var owned = parsed.filter(function(counter) { return target === section || target.contains(counter.element); });
            if (index === 0 && target !== section) {
                parsed.forEach(function(counter) {
                    if (!targets.some(function(candidate) { return candidate.contains(counter.element); })) owned.push(counter);
                });
            }
            var delay = Number(section.dataset.hucrMotionDelay || 0) + (index > 0 ? Math.min(index, 6) * 75 : 0);
            actions.set(target, function() {
                reveal(target);
                owned.forEach(function(counter) { start(counter, duration, delay); });
            });
            if (index > 0) target.classList.add('hucr-motion-stagger-' + Math.min(index, 6));
            observe(target, owned);
        });
    }

    function finishAll() {
        counters.forEach(finish);
        document.querySelectorAll('.is-hucr-motion-pending').forEach(reveal);
    }

    function init(root) {
        root = root || document;
        var sections = query(root, '[data-hucr-motion], [data-hucr-motion-count-up]');
        if (!('IntersectionObserver' in window) || reduced.matches) {
            sections.forEach(function(section) { sectionTargets(section).forEach(reveal); });
            return;
        }
        if (!observer) {
            observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) { if (entry.isIntersecting) activate(entry.target); });
            }, { threshold: 0.12, rootMargin: '0px 0px -5% 0px' });
        }
        sections.forEach(register);
    }

    window.HucrSectionMotion = { init: init, reveal: reveal, finishAll: finishAll };
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', function() { init(document); }, { once: true });
    else init(document);
    document.addEventListener('ajaxUpdateComplete', function() { init(document); });
    document.addEventListener('focusin', function(event) {
        var target = event.target.closest('.is-hucr-motion-pending');
        if (target) activate(target);
    });
    window.addEventListener('beforeprint', finishAll);
    function onPreferenceChange(event) { if (event.matches) finishAll(); }
    if (reduced.addEventListener) reduced.addEventListener('change', onPreferenceChange);
    else if (reduced.addListener) reduced.addListener(onPreferenceChange);
})();
