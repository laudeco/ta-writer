// "+ Add" menu in the header of an item that has sub-lists (an epic, a user story, a technical story, a subtask): choosing
// a kind clicks the "+ Add" button of that list, so the adding path is the one of the list (template, defaults, collapse,
// focus). Nothing is sent to the server by opening the menu.
(function () {
    'use strict';

    var LABELS = {
        epics: 'Epic',
        userStories: 'User story',
        technicalStories: 'Technical story',
        subtasks: 'Subtask',
        resources: 'Resource',
        diagrams: 'Diagram',
        acceptanceCriteria: 'Criterion',
        acceptanceCriteriaCovered: 'Covered criterion'
    };

    var openMenu = null;

    // structure first, then criteria, resources and diagrams: the same order in every menu
    var ORDER = ['userStories', 'technicalStories', 'subtasks', 'acceptanceCriteriaCovered', 'acceptanceCriteria', 'resources', 'diagrams'];

    // the sub-lists of an item: the collections whose nearest item is this one, and that can take an item
    function subLists(item) {
        var lists = Array.prototype.filter.call(item.querySelectorAll('[data-collection]'), function (collection) {
            return collection.closest('[data-item]') === item && collection.querySelector(':scope > [data-add]');
        });

        return lists.sort(function (a, b) {
            var ia = ORDER.indexOf(a.getAttribute('data-nav'));
            var ib = ORDER.indexOf(b.getAttribute('data-nav'));

            return (ia === -1 ? ORDER.length : ia) - (ib === -1 ? ORDER.length : ib);
        });
    }

    function countOf(collection) {
        var count = collection.querySelector(':scope > .collection-head [data-count]');

        return count ? count.textContent.trim() : '';
    }

    function close(menu) {
        var list = menu.querySelector('.child-menu-list');
        var toggle = menu.querySelector('.child-menu-toggle');
        list.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
        if (openMenu === menu) {
            openMenu = null;
        }
    }

    // the entries are rebuilt on each opening: the counts and the lists are the current ones
    function fill(menu, item) {
        var list = menu.querySelector('.child-menu-list');
        list.innerHTML = '';
        subLists(item).forEach(function (collection) {
            var kind = collection.getAttribute('data-nav');
            var entry = document.createElement('button');
            entry.type = 'button';
            entry.setAttribute('role', 'menuitem');
            entry.className = 'child-menu-item';
            entry.setAttribute('data-child-kind', kind);
            entry.textContent = (LABELS[kind] || kind) + ' ';
            var count = document.createElement('span');
            count.className = 'child-menu-count';
            count.textContent = countOf(collection);
            entry.appendChild(count);
            list.appendChild(entry);
        });
    }

    function place(menu) {
        var list = menu.querySelector('.child-menu-list');
        var rect = menu.getBoundingClientRect();
        var needed = list.offsetHeight || 200;
        // not enough room under the button: the menu opens upwards
        list.classList.toggle('is-up', rect.bottom + needed > window.innerHeight && rect.top > needed);
    }

    function open(menu, item, focusFirst) {
        if (openMenu && openMenu !== menu) {
            close(openMenu);
        }
        fill(menu, item);
        var list = menu.querySelector('.child-menu-list');
        list.hidden = false;
        menu.querySelector('.child-menu-toggle').setAttribute('aria-expanded', 'true');
        openMenu = menu;
        place(menu);
        var first = list.querySelector('[role="menuitem"]');
        if (focusFirst && first) {
            first.focus();
        }
    }

    // choosing a kind: the "+ Add" button of its list does the work, and the new item gets the focus
    function choose(menu, item, kind) {
        close(menu);
        var collection = subLists(item).filter(function (c) { return c.getAttribute('data-nav') === kind; })[0];
        if (!collection) {
            return;
        }
        var addButton = collection.querySelector(':scope > [data-add]');
        if (addButton) {
            // the "+ Add" of the list gives the focus to the title of the new item: it is not moved back here
            addButton.click();
        }
    }

    function buildMenu(item) {
        var bar = item.querySelector(':scope > .item-bar');
        if (!bar || bar.querySelector('.child-menu')) {
            return;
        }
        if (subLists(item).length === 0) {
            return;
        }
        var menu = document.createElement('div');
        menu.className = 'child-menu';
        menu.innerHTML = '<button type="button" class="child-menu-toggle" aria-haspopup="menu" aria-expanded="false">+ Add &#9662;</button>' +
            '<div class="child-menu-list" role="menu" hidden></div>';
        var before = bar.querySelector('[data-move="up"]');
        bar.insertBefore(menu, before || null);

        var toggle = menu.querySelector('.child-menu-toggle');
        toggle.addEventListener('click', function () {
            if (menu.querySelector('.child-menu-list').hidden) {
                open(menu, item, false);
            } else {
                close(menu);
            }
        });
        toggle.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                open(menu, item, true);
            } else if (event.key === 'Escape') {
                close(menu);
            }
        });
        menu.querySelector('.child-menu-list').addEventListener('click', function (event) {
            var entry = event.target.closest('[data-child-kind]');
            if (entry) {
                choose(menu, item, entry.getAttribute('data-child-kind'));
            }
        });
        menu.querySelector('.child-menu-list').addEventListener('keydown', function (event) {
            var entries = Array.prototype.slice.call(menu.querySelectorAll('[role="menuitem"]'));
            var index = entries.indexOf(document.activeElement);
            if (event.key === 'ArrowDown') {
                event.preventDefault();
                entries[(index + 1) % entries.length].focus();
            } else if (event.key === 'ArrowUp') {
                event.preventDefault();
                entries[(index - 1 + entries.length) % entries.length].focus();
            } else if (event.key === 'Home') {
                event.preventDefault();
                entries[0].focus();
            } else if (event.key === 'End') {
                event.preventDefault();
                entries[entries.length - 1].focus();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                close(menu);
                toggle.focus();
            } else if (event.key === 'Tab') {
                close(menu);
            }
        });
    }

    function initIn(root) {
        var base = root || document;
        var items = Array.prototype.slice.call(base.querySelectorAll('[data-item]'));
        if (base.hasAttribute && base.hasAttribute('data-item')) {
            items.unshift(base);
        }
        items.forEach(buildMenu);
    }

    // a click elsewhere closes the open menu
    document.addEventListener('click', function (event) {
        if (openMenu && !openMenu.contains(event.target)) {
            close(openMenu);
        }
    });

    window.TAChildMenu = { initIn: initIn };
    document.addEventListener('DOMContentLoaded', function () {
        initIn(document);
    });
})();
