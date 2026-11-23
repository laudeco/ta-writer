// Outline of the analysis, on the left of the editor. Built from the form itself, so it follows the items added, removed
// or moved, and the labels typed in `reference` and `title`. The entry of the section in view is highlighted (scroll spy).
(function () {
    'use strict';

    var LEVEL = { epics: 1, userStories: 2, technicalStories: 2, subtasks: 3 };
    var SECTION_LABEL = { project: 'Project', author: 'Author', dependencies: 'Dependencies' };
    var nav, list, filterInput, form, observer, counter = 0, rebuildTimer = null, activeId = null;

    function own(item, name) {
        // the field of this item only: fields of the items nested in it belong to them
        var fields = item.querySelectorAll('[name$="[' + name + ']"]');
        for (var i = 0; i < fields.length; i++) {
            if (fields[i].closest('[data-item]') === item) {
                return fields[i].value.trim();
            }
        }
        return '';
    }

    function labelOf(item, kind) {
        var reference = own(item, 'reference');
        var title = own(item, 'title');
        if (kind === 'subtasks' && reference === '') {
            reference = 'Subtask';
        }
        var head = reference !== '' ? reference : '(no reference)';

        return title !== '' ? head + ' — ' + title : head;
    }

    function sectionsInOrder() {
        var out = [];
        form.querySelectorAll('[data-nav-section], [data-item]').forEach(function (el) {
            if (el.hasAttribute('data-item')) {
                var kind = el.closest('[data-collection]').getAttribute('data-nav');
                if (LEVEL[kind]) {
                    out.push(el);
                }
            } else {
                out.push(el);
            }
        });

        return out;
    }

    function ensureId(el) {
        if (!el.id) {
            counter += 1;
            el.id = 'nav-s' + counter;
        }
        return el.id;
    }

    function build() {
        var sections = sectionsInOrder();
        var rootList = document.createElement('ol');
        var stack = [{ level: 0, ol: rootList }];
        var entries = [];

        // a document-wide entry first, then each section in document order, nested by level
        var doc = document.createElement('li');
        doc.className = 'doc-nav-item level-0';
        doc.innerHTML = '<a href="#editor">Document</a>';
        rootList.appendChild(doc);

        sections.forEach(function (el) {
            var kind;
            var label;
            var level;
            if (el.hasAttribute('data-nav-section')) {
                kind = el.getAttribute('data-nav-section');
                label = SECTION_LABEL[kind] || kind;
                level = 1;
            } else {
                kind = el.closest('[data-collection]').getAttribute('data-nav');
                level = LEVEL[kind];
                label = labelOf(el, kind);
            }
            while (stack.length > 1 && stack[stack.length - 1].level >= level) {
                stack.pop();
            }
            var parent = stack[stack.length - 1];
            var li = document.createElement('li');
            li.className = 'doc-nav-item level-' + level;
            var a = document.createElement('a');
            a.href = '#' + ensureId(el);
            a.textContent = label;
            a.setAttribute('data-nav-target', el.id);
            li.appendChild(a);
            var ol = document.createElement('ol');
            li.appendChild(ol);
            parent.ol.appendChild(li);
            stack.push({ level: level, ol: ol });
            entries.push({ el: el, a: a, li: li });
        });

        // empty sub-lists stay out of the markup
        rootList.querySelectorAll('ol:empty').forEach(function (ol) { ol.remove(); });
        list.innerHTML = '';
        list.appendChild(rootList);
        entries.forEach(function (entry) { entry.li.setAttribute('data-nav-entry', ''); });
        applyFilter();
        observe(entries);
        if (activeId) {
            markActive(activeId);
        }
    }

    function applyFilter() {
        var q = filterInput ? filterInput.value.trim().toLowerCase() : '';
        list.querySelectorAll('[data-nav-entry]').forEach(function (li) {
            var text = li.querySelector('a').textContent.toLowerCase();
            li.hidden = q !== '' && text.indexOf(q) === -1;
        });
    }

    var listeners = [];

    function markActive(id) {
        var changed = id !== activeId;
        activeId = id;
        if (changed) {
            listeners.forEach(function (fn) { fn(id); });
        }
        list.querySelectorAll('a[aria-current]').forEach(function (a) { a.removeAttribute('aria-current'); });
        var link = list.querySelector('a[data-nav-target="' + id + '"]');
        if (!link) {
            return;
        }
        link.setAttribute('aria-current', 'true');
        // the active entry is brought into view of the outline itself
        var box = nav.getBoundingClientRect();
        var r = link.getBoundingClientRect();
        if (r.top < box.top || r.bottom > box.bottom) {
            nav.scrollTop += r.top - box.top - box.height / 3;
        }
    }

    // scroll spy: the first section, in document order, that is in the reading band of the page
    function observe(entries) {
        if (observer) {
            observer.disconnect();
        }
        if (!('IntersectionObserver' in window)) {
            return;
        }
        var visible = new Set();
        observer = new IntersectionObserver(function (changes) {
            changes.forEach(function (change) {
                if (change.isIntersecting) {
                    visible.add(change.target.id);
                } else {
                    visible.delete(change.target.id);
                }
            });
            // several sections can be in the band (a technical story is inside its epic): the one that starts lowest wins
            var best = null;
            var bestTop = -Infinity;
            entries.forEach(function (entry) {
                if (!visible.has(entry.el.id)) {
                    return;
                }
                var top = entry.el.getBoundingClientRect().top;
                if (top >= bestTop) {
                    best = entry;
                    bestTop = top;
                }
            });
            if (best) {
                markActive(best.el.id);
            }
        }, { rootMargin: '-15% 0px -70% 0px' });
        entries.forEach(function (entry) { observer.observe(entry.el); });
    }

    function scheduleBuild() {
        clearTimeout(rebuildTimer);
        rebuildTimer = setTimeout(build, 250);
    }

    function init() {
        nav = document.querySelector('[data-docnav]');
        form = document.getElementById('editor');
        if (!nav || !form) {
            return;
        }
        list = nav.querySelector('[data-docnav-list]');
        filterInput = nav.querySelector('[data-docnav-filter]');
        if (filterInput) {
            filterInput.addEventListener('input', applyFilter);
            document.addEventListener('keydown', function (event) {
                if (event.key === '/' && document.activeElement && !/input|textarea|select/i.test(document.activeElement.tagName)) {
                    event.preventDefault();
                    filterInput.focus();
                }
            });
        }
        list.addEventListener('click', function (event) {
            var a = event.target.closest('a[data-nav-target]');
            if (!a) {
                return;
            }
            event.preventDefault();
            var target = document.getElementById(a.getAttribute('data-nav-target'));
            if (target) {
                var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
                markActive(target.id);
            }
        });
        form.addEventListener('input', function (event) {
            if (event.target.getAttribute && /\[(reference|title)\]$/.test(event.target.name || '')) {
                scheduleBuild();
            }
        });
        new MutationObserver(scheduleBuild).observe(form, { childList: true, subtree: true });
        build();
    }

    // the section in view changes: the preview follows it
    window.TADocNav = {
        refresh: scheduleBuild,
        onActiveChange: function (fn) { listeners.push(fn); },
        active: function () { return activeId; }
    };
    document.addEventListener('DOMContentLoaded', init);
})();
