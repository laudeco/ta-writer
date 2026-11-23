// Collapsing of the editor's blocks: an item of a list (epic, story, subtask, resource, diagram...), a list itself, or a
// group (Project, Author, SQL impact). A collapsed item shows a one-line summary (reference and title). The state is
// kept per browser and per analysis; it never changes the data: collapsed fields stay in the form and are saved as they are.
(function () {
    'use strict';

    var KINDS = { epics: true, userStories: true, technicalStories: true, subtasks: true };
    var form = null;
    var blocks = [];                 // every block that has a toggle, in document order
    var toggles = new WeakMap();     // block -> its toggle button
    var store = null;                // keys of the collapsed blocks

    function storageKey() {
        return 'ta-collapse:' + location.pathname;
    }

    function loadStore() {
        try {
            return new Set(JSON.parse(localStorage.getItem(storageKey()) || '[]'));
        } catch (error) {
            return new Set();
        }
    }

    function save() {
        try {
            localStorage.setItem(storageKey(), JSON.stringify(Array.from(store)));
        } catch (error) {
            // private window or blocked storage: the state simply is not kept
        }
    }

    // "epics.0.technicalStories.2": the position of each item from the top of the document
    function itemKey(item) {
        var segments = [];
        var node = item;
        while (node && node !== form) {
            if (node.hasAttribute && node.hasAttribute('data-item')) {
                var collection = node.closest('[data-collection]');
                var kind = collection && collection.getAttribute('data-nav');
                if (KINDS[kind]) {
                    var list = collection.querySelector(':scope > [data-items]');
                    var items = Array.prototype.filter.call(list.children, function (c) { return c.hasAttribute('data-item'); });
                    segments.unshift(kind, items.indexOf(node));
                }
            }
            node = node.parentElement;
        }

        return segments.length ? segments.join('.') : 'root';
    }

    function parentKey(block) {
        var parent = block.parentElement && block.parentElement.closest('[data-item]');

        return parent ? itemKey(parent) : 'root';
    }

    function keyOf(block) {
        if (block.hasAttribute('data-item')) {
            return itemKey(block);
        }
        if (block.hasAttribute('data-collection')) {
            return parentKey(block) + '/' + block.getAttribute('data-nav');
        }
        var legend = block.querySelector(':scope > legend');

        return parentKey(block) + '/' + (legend ? legend.textContent.trim() : '?');
    }

    // the label of a collapsed item: "reference — title", or the first value typed in it
    function summaryOf(item) {
        var fields = item.querySelectorAll('input[name], textarea[name]');
        var reference = '';
        var title = '';
        var fallback = '';
        Array.prototype.forEach.call(fields, function (field) {
            if (field.closest('[data-item]') !== item) {
                return;
            }
            var value = (field.value || '').trim();
            if (/\[reference\]$/.test(field.name)) {
                reference = value;
            } else if (/\[title\]$/.test(field.name)) {
                title = value;
            } else if (fallback === '' && value !== '' && field.type !== 'hidden') {
                fallback = value;
            }
        });
        if (reference === '' && title === '') {
            return fallback !== '' ? fallback : '(empty)';
        }

        return [reference, title].filter(function (part) { return part !== ''; }).join(' — ');
    }

    function updateSummary(item) {
        var summary = item.querySelector(':scope > .item-bar > .item-summary');
        if (summary) {
            summary.textContent = summaryOf(item);
        }
    }

    function setCollapsed(block, collapsed, persist) {
        block.classList.toggle('is-collapsed', collapsed);
        var toggle = toggles.get(block);
        if (toggle) {
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            toggle.setAttribute('aria-label', collapsed ? 'Expand this block' : 'Collapse this block');
            toggle.title = collapsed ? 'Expand' : 'Collapse';
        }
        var key = keyOf(block);
        if (collapsed) {
            store.add(key);
        } else {
            store.delete(key);
        }
        if (persist !== false) {
            save();
        }
    }

    // a toggle for a block, and for an item a summary shown when it is collapsed
    function attach(block) {
        if (toggles.has(block)) {
            return;
        }
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'collapse-toggle';
        toggle.setAttribute('aria-expanded', 'true');

        if (block.hasAttribute('data-item')) {
            var bar = block.querySelector(':scope > .item-bar');
            var no = bar.querySelector('.item-no');
            bar.insertBefore(toggle, bar.firstChild);
            var summary = document.createElement('span');
            summary.className = 'item-summary';
            if (no) {
                no.insertAdjacentElement('afterend', summary);
            } else {
                bar.insertBefore(summary, toggle.nextSibling);
            }
        } else if (block.hasAttribute('data-collection')) {
            block.querySelector(':scope > .collection-head').appendChild(toggle);
        } else {
            block.querySelector(':scope > legend').insertBefore(toggle, block.querySelector(':scope > legend').firstChild);
        }

        toggles.set(block, toggle);
        blocks.push(block);
        toggle.addEventListener('click', function () {
            setCollapsed(block, !block.classList.contains('is-collapsed'));
        });
        if (block.hasAttribute('data-item')) {
            updateSummary(block);
        }
        if (store.has(keyOf(block))) {
            setCollapsed(block, true, false);
        }
    }

    // the blocks of a root (the form, or an item just added), itself included
    function blocksIn(root) {
        var found = [];
        if (root.nodeType === 1 && (root.hasAttribute('data-item') || root.hasAttribute('data-collection') || isGroup(root))) {
            found.push(root);
        }
        Array.prototype.forEach.call(root.querySelectorAll('[data-item], [data-collection], fieldset.object'), function (el) {
            if (el.hasAttribute('data-item') || el.hasAttribute('data-collection') || isGroup(el)) {
                found.push(el);
            }
        });

        return found;
    }

    // a group is a named object: not the root, and not the object that holds the fields of a list item
    function isGroup(fieldset) {
        return fieldset.matches('fieldset.object:not(.root)') && !(fieldset.parentElement && fieldset.parentElement.hasAttribute('data-item')) && !!fieldset.querySelector(':scope > legend');
    }

    function initIn(root) {
        if (!store) {
            return;
        }
        // items first: a group or list is attached inside its own item
        blocksIn(root || form).forEach(attach);
    }

    function expandTo(el) {
        var node = el;
        while (node && node !== form) {
            if (toggles.has(node) && node.classList.contains('is-collapsed')) {
                setCollapsed(node, false, false);
            }
            node = node.parentElement;
        }
        save();
    }

    function collapseAll() {
        blocks.forEach(function (block) { setCollapsed(block, true, false); });
        save();
    }

    function expandAll() {
        blocks.forEach(function (block) { setCollapsed(block, false, false); });
        save();
    }

    function init() {
        form = document.getElementById('editor');
        if (!form) {
            return;
        }
        store = loadStore();
        initIn(form);
        form.addEventListener('input', function (event) {
            if (/\[(reference|title)\]$/.test(event.target.name || '')) {
                var item = event.target.closest('[data-item]');
                if (item) {
                    updateSummary(item);
                }
            }
        });
        document.addEventListener('click', function (event) {
            if (event.target.closest('[data-collapse-all]')) {
                collapseAll();
            } else if (event.target.closest('[data-expand-all]')) {
                expandAll();
            }
        });
    }

    window.TACollapse = { initIn: initIn, expandTo: expandTo, collapseAll: collapseAll, expandAll: expandAll };
    document.addEventListener('DOMContentLoaded', init);
})();
