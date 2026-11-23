// Repeatable lists of the editor: add, remove, reorder. Each new item gets a unique index so the server keeps order.
(function () {
    'use strict';

    function uniqueIndex() {
        return Date.now().toString(36) + Math.random().toString(36).slice(2, 7);
    }

    function refreshCount(collection) {
        var count = collection.querySelector(':scope > .collection-head [data-count]');
        if (count) {
            count.textContent = collection.querySelectorAll(':scope > [data-items] > [data-item]').length;
        }
    }

    function renumber(items) {
        items.querySelectorAll(':scope > [data-item] > .item-bar > .item-no').forEach(function (label, i) {
            label.textContent = '#' + (i + 1);
        });
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('button');
        if (!button) {
            return;
        }
        var collection = button.closest('[data-collection]');
        if (button.hasAttribute('data-add') && collection) {
            var template = collection.querySelector(':scope > [data-template]');
            var html = template.innerHTML.replace(/__INDEX__/g, uniqueIndex());
            var holder = document.createElement('div');
            holder.innerHTML = html;
            var item = holder.firstElementChild;
            collection.querySelector(':scope > [data-items]').appendChild(item);
            if (window.TARichText) {
                window.TARichText.initIn(item);
            }
            if (window.TADiagram) {
                window.TADiagram.initIn(item);
            }
            if (window.TADefaults) {
                window.TADefaults.fill(item);
            }
            if (window.TACollapse) {
                window.TACollapse.initIn(item);
                window.TACollapse.expandTo(item); // the new block is shown, with the blocks around it
            }
            renumber(collection.querySelector(':scope > [data-items]'));
            refreshCount(collection);
            var firstField = item.querySelector('input, textarea, select');
            if (firstField) {
                firstField.focus();
            }
            return;
        }
        if (button.hasAttribute('data-remove')) {
            var item = button.closest('[data-item]');
            var items = item.parentElement;
            if (window.confirm('Remove this item and everything inside it?')) {
                item.remove();
                renumber(items);
                refreshCount(collection);
            }
            return;
        }
        if (button.hasAttribute('data-move')) {
            var moved = button.closest('[data-item]');
            var list = moved.parentElement;
            if (button.getAttribute('data-move') === 'up' && moved.previousElementSibling) {
                list.insertBefore(moved, moved.previousElementSibling);
            } else if (button.getAttribute('data-move') === 'down' && moved.nextElementSibling) {
                list.insertBefore(moved.nextElementSibling, moved);
            }
            renumber(list);
        }
    });

    // avoid losing typed content with an accidental navigation
    var editor = document.getElementById('editor');
    if (editor) {
        var dirty = false;
        editor.addEventListener('input', function () { dirty = true; });
        editor.addEventListener('submit', function () { dirty = false; });
        // an autosave that stored everything typed so far: the page no longer holds unsaved changes
        editor.addEventListener('ta:saved', function () { dirty = false; });
        window.addEventListener('beforeunload', function (event) {
            if (dirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        });
    }
})();
