// Reference dropdowns (the `from` and `to` of a dependency). Each list follows the structure of the document: one group per
// epic, then its user stories and technical stories, and the sub-tasks under their technical story (indented). The lists
// follow the document as it is edited; a value that is no longer in the document stays selected and is marked.
(function () {
    'use strict';

    // levels of the hierarchy: an epic, its user stories and technical stories, the sub-tasks under their technical story
    var LEVEL = { epics: 0, userStories: 1, technicalStories: 1, subtasks: 2 };
    var INDENT = '\u00a0\u00a0\u00a0\u00a0';
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

    // one group per epic, in the order of the document: the epic, then its user stories and technical stories, and the
    // sub-tasks of each technical story under it (indented). A sub-task is named TS1/Subtask#1.
    function collect() {
        var groups = [];
        Array.prototype.forEach.call(form.querySelectorAll('[data-collection][data-nav="epics"] > [data-items] > [data-item]'), function (epic) {
            var entries = [];
            var epicRef = ownValue(epic, 'reference');
            if (epicRef !== '') {
                entries.push({ level: 0, ref: epicRef, title: ownValue(epic, 'title') });
            }
            Array.prototype.forEach.call(epic.querySelectorAll('[data-item]'), function (item) {
                // only the kinds that a dependency may name (resources and diagrams are not references)
                var collection = item.closest('[data-collection]');
                var nav = collection ? collection.getAttribute('data-nav') : '';
                if (!LEVEL.hasOwnProperty(nav) || nav === 'epics') {
                    return;
                }
                var ref = ownValue(item, 'reference');
                if (nav === 'subtasks') {
                    // a sub-task is named by its technical story: TS1/Subtask#1
                    var story = item.parentElement && item.parentElement.closest('[data-item]');
                    var storyRef = story ? ownValue(story, 'reference') : '';
                    ref = storyRef !== '' && ref !== '' ? storyRef + '/' + ref : '';
                }
                if (ref !== '') {
                    entries.push({ level: LEVEL[nav], ref: ref, title: ownValue(item, 'title') });
                }
            });
            if (entries.length > 0) {
                groups.push({ label: epicRef !== '' ? epicRef + (ownValue(epic, 'title') ? ' — ' + ownValue(epic, 'title') : '') : 'Epic', entries: entries });
            }
        });

        return groups;
    }

    function optionFor(entry) {
        var option = document.createElement('option');
        option.value = entry.ref;
        var text = entry.title !== '' ? entry.ref + ' — ' + entry.title : entry.ref;
        option.textContent = repeat(INDENT, entry.level) + text;

        return option;
    }

    function repeat(text, times) {
        return new Array(times + 1).join(text);
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
            var optgroup = document.createElement('optgroup');
            optgroup.label = group.label;
            group.entries.forEach(function (entry) {
                known[entry.ref] = true;
                optgroup.appendChild(optionFor(entry));
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
