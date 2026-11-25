// Reference dropdowns (the `from` and `to` of a dependency): each one lists every reference of the document that a
// dependency can point to: epics, user stories and technical stories. The lists follow the document as it is edited;
// a value that is no longer in the document stays selected and is marked, so nothing is lost silently.
(function () {
    'use strict';

    var GROUPS = [
        { nav: 'epics', label: 'Epics' },
        { nav: 'userStories', label: 'User stories' },
        { nav: 'technicalStories', label: 'Technical stories' }
    ];
    var form = null;
    var timer = null;

    // the field of this item only: the fields of the items nested in it belong to them
    function ownValue(item, name) {
        var fields = item.querySelectorAll('[name$="[' + name + ']"]');
        for (var i = 0; i < fields.length; i++) {
            if (fields[i].closest('[data-item]') === item) {
                return (fields[i].value || '').trim();
            }
        }

        return '';
    }

    // every reference a dependency may name, in document order, per group
    function collect() {
        return GROUPS.map(function (group) {
            var items = Array.prototype.slice.call(form.querySelectorAll('[data-collection][data-nav="' + group.nav + '"] > [data-items] > [data-item]'));
            var refs = [];
            items.forEach(function (item) {
                var ref = ownValue(item, 'reference');
                if (ref !== '') {
                    refs.push({ ref: ref, title: ownValue(item, 'title') });
                }
            });

            return { label: group.label, refs: refs };
        });
    }

    function optionFor(ref, title) {
        var option = document.createElement('option');
        option.value = ref;
        option.textContent = title !== '' ? ref + ' — ' + title : ref;

        return option;
    }

    // the list is rebuilt keeping the value chosen; a value that is gone is kept and marked
    function rebuild(select) {
        var value = select.value || select.getAttribute('data-value') || '';
        var groups = collect();
        var known = {};
        select.innerHTML = '';
        var empty = document.createElement('option');
        empty.value = '';
        empty.textContent = '—';
        select.appendChild(empty);
        groups.forEach(function (group) {
            if (group.refs.length === 0) {
                return;
            }
            var optgroup = document.createElement('optgroup');
            optgroup.label = group.label;
            group.refs.forEach(function (entry) {
                known[entry.ref] = true;
                optgroup.appendChild(optionFor(entry.ref, entry.title));
            });
            select.appendChild(optgroup);
        });
        if (value !== '' && !known[value]) {
            var orphan = document.createElement('option');
            orphan.value = value;
            orphan.textContent = value + ' (not in the document)';
            orphan.setAttribute('data-orphan', '');
            select.insertBefore(orphan, select.firstChild.nextSibling);
            select.classList.add('is-orphan');
        } else {
            select.classList.remove('is-orphan');
        }
        select.value = value;
        select.removeAttribute('data-value');
    }

    // a list the person is opening is not rebuilt under the mouse: it is refreshed when it loses the focus
    function rebuildAll() {
        document.querySelectorAll('[data-reference-select]').forEach(function (select) {
            if (document.activeElement === select) {
                select.setAttribute('data-stale', '');
            } else {
                rebuild(select);
            }
        });
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(rebuildAll, 150);
    }

    function initIn(root) {
        (root || document).querySelectorAll('[data-reference-select]').forEach(function (select) {
            if (select.hasAttribute('data-stale') || select.getAttribute('data-value') !== null || select.options.length <= 1) {
                rebuild(select);
            }
        });
    }

    function init() {
        form = document.getElementById('editor');
        if (!form) {
            return;
        }
        initIn(form);
        form.addEventListener('input', function (event) {
            if (/\[(reference|title)\]$/.test(event.target.name || '')) {
                schedule();
            }
        });
        form.addEventListener('focusout', function (event) {
            if (event.target.hasAttribute && event.target.hasAttribute('data-reference-select') && event.target.hasAttribute('data-stale')) {
                event.target.removeAttribute('data-stale');
                rebuild(event.target);
            }
        });
        // the lists themselves change their options when rebuilt: those changes must not start another rebuild
        new MutationObserver(function (records) {
            var relevant = records.some(function (record) {
                return !(record.target.closest && record.target.closest('[data-reference-select]'));
            });
            if (relevant) {
                schedule();
            }
        }).observe(form, { childList: true, subtree: true });
    }

    window.TAReferences = { initIn: initIn, refresh: schedule };
    document.addEventListener('DOMContentLoaded', init);
})();
