// The reference of a new block: the next number of its kind. Epics, user stories and technical stories are numbered
// across the whole document (their references are unique and the dependencies point to them); subtasks are numbered
// inside their technical story. A reference already typed is never changed.
(function () {
    'use strict';

    var RULES = {
        epics: { label: 'E', pattern: /^E(\d+)$/ },
        userStories: { label: 'US', pattern: /^US(\d+)$/ },
        technicalStories: { label: 'TS', pattern: /^TS(\d+)$/ },
        subtasks: { label: 'Subtask#', pattern: /^Subtask#(\d+)$/ }
    };

    // the field of this item only: the fields of the items nested in it belong to them
    function ownField(item, name) {
        var fields = item.querySelectorAll('[name$="[' + name + ']"]');
        for (var i = 0; i < fields.length; i++) {
            if (fields[i].closest('[data-item]') === item) {
                return fields[i];
            }
        }

        return null;
    }

    function listOf(kind, scope) {
        return Array.prototype.slice.call(scope.querySelectorAll('[data-collection][data-nav="' + kind + '"] > [data-items] > [data-item]'));
    }

    function highestNumber(items, pattern, item) {
        var highest = 0;
        items.forEach(function (other) {
            if (other === item) {
                return;
            }
            var field = ownField(other, 'reference');
            var match = field ? field.value.trim().match(pattern) : null;
            if (match) {
                highest = Math.max(highest, parseInt(match[1], 10));
            }
        });

        return highest;
    }

    function fill(item) {
        var collection = item.closest('[data-collection]');
        var kind = collection && collection.getAttribute('data-nav');
        var rule = RULES[kind];
        if (!rule) {
            return;
        }
        var field = ownField(item, 'reference');
        if (!field || field.value.trim() !== '') {
            return;
        }
        var scope = kind === 'subtasks' ? collection.querySelector(':scope > [data-items]') : document.getElementById('editor');
        var items = kind === 'subtasks'
            ? Array.prototype.slice.call(scope.querySelectorAll(':scope > [data-item]'))
            : listOf(kind, scope);
        field.value = rule.label + (highestNumber(items, rule.pattern, item) + 1);
        // like a typed value: the outline, the summary and the preview follow it
        field.dispatchEvent(new Event('input', { bubbles: true }));
    }

    window.TADefaults = { fill: fill };
})();
