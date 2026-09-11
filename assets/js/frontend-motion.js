(function() {
    'use strict';

    if (window.HucrSectionMotion) {
        return;
    }

    var media = window.matchMedia('(prefers-reduced-motion: reduce)');
    var observer = null;

    function reveal(element) {
        element.classList.remove('is-hucr-motion-pending');
        element.classList.add('is-hucr-motion-visible');
        element.style.removeProperty('will-change');
        if (observer) {
            observer.unobserve(element);
        }
    }

    function visibleNow(element) {
        var rect = element.getBoundingClientRect();
        return rect.top <= window.innerHeight && rect.bottom >= 0;
    }

    function targets(section) {
        if (section.dataset.hucrMotionStagger !== '1') {
            return [section];
        }
        var items = Array.prototype.slice.call(section.querySelectorAll('[data-hucr-motion-item]'));
        return items.length > 0 && items.length <= 7 ? items : [section];
    }

    function observeSection(section) {
        if (section.dataset.hucrMotionInitialized === '1') {
            return;
        }
        section.dataset.hucrMotionInitialized = '1';

        targets(section).forEach(function(target, index) {
            if (visibleNow(target)) {
                reveal(target);
                return;
            }
            if (index > 0) {
                target.classList.add('hucr-motion-stagger-' + Math.min(index, 6));
            }
            try {
                observer.observe(target);
                target.classList.add('is-hucr-motion-pending');
            }
            catch (error) {
                reveal(target);
            }
        });
    }

    function init(root) {
        root = root || document;
        var sections = Array.prototype.slice.call(root.querySelectorAll('[data-hucr-motion]'));
        if (!('IntersectionObserver' in window) || media.matches) {
            sections.forEach(function(section) {
                targets(section).forEach(reveal);
            });
            return;
        }
        if (!observer) {
            observer = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    if (entry.isIntersecting) {
                        reveal(entry.target);
                    }
                });
            }, { threshold: 0.12, rootMargin: '0px 0px -5% 0px' });
        }
        sections.forEach(observeSection);
    }

    window.HucrSectionMotion = { init: init, reveal: reveal };
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() { init(document); }, { once: true });
    }
    else {
        init(document);
    }
    document.addEventListener('ajaxUpdateComplete', function() { init(document); });
    document.addEventListener('focusin', function(event) {
        var target = event.target.closest('.is-hucr-motion-pending');
        if (target) {
            reveal(target);
        }
    });
    function onPreferenceChange(event) {
        if (event.matches) {
            document.querySelectorAll('.is-hucr-motion-pending').forEach(reveal);
        }
    }
    if (media.addEventListener) {
        media.addEventListener('change', onPreferenceChange);
    }
    else if (media.addListener) {
        media.addListener(onPreferenceChange);
    }
})();
