// Validation errors of the editor, shown where they are: the panel above the form names each one (its place in the document
// and its message), and the field and the blocks around it turn red. An error goes when its field is changed.
// The errors come from the server (a save refused) through TAErrors.show(list), or from the page on a plain save.
(function () {
    'use strict';

    var KINDS = {
        epics: 'Epic', userStories: 'User story', technicalStories: 'Technical story', subtasks: 'Subtask',
        resources: 'Resource', diagrams: 'Diagram', dependencies: 'Dependency'
    };
    var form = null;
    var panel = null;
    var marked = [];        // { field, block, message, path, id }
    var counter = 0;

    function itemHeading(item) {
        var kind = item.closest('[data-collection]').getAttribute('data-nav');
        var ref = '';
        var title = '';
        Array.prototype.forEach.call(item.querySelectorAll('[name$="[reference]"], [name$="[title]"]'), function (el) {
            if (el.closest('[data-item]') !== item) {
                return;
            }
            if (/\[reference\]$/.test(el.name)) {
                ref = (el.value || '').trim();
            } else {
                title = (el.value || '').trim();
            }
        });
        var label = (KINDS[kind] || kind) + (ref ? ' ' + ref : '');

        return title ? label + ' — ' + title : label;
    }

    function fieldLabel(el) {
        var wrap = el.closest('.field');
        var span = wrap && wrap.querySelector(':scope > span');

        return span ? span.textContent.replace(/\*/g, '').trim() : el.name;
    }

    // walks a JSON path (/epics/0/technicalStories/1/title) through the form, one list or one field at a time
    function resolve(path) {
        var segments = String(path).split('/').filter(function (s) { return s !== ''; });
        var container = form;
        var list = null;
        var block = null;
        var field = null;
        var crumbs = [];
        var i = 0;
        while (i < segments.length) {
            var seg = segments[i];
            if (/^\d+$/.test(seg) && list) {
                var items = Array.prototype.filter.call(list.querySelector(':scope > [data-items]').children, function (c) { return c.hasAttribute('data-item'); });
                var item = items[parseInt(seg, 10)];
                list = null;
                if (!item) {
                    break;
                }
                container = item;
                block = item;
                crumbs.push(itemHeading(item));
            } else if (/^\d+$/.test(seg) === false) {
                var lists = Array.prototype.filter.call(container.querySelectorAll('[data-collection][data-nav="' + seg + '"]'), function (c) {
                    return c.closest('[data-item]') === (container === form ? null : container);
                });
                if (lists.length) {
                    list = lists[0];
                } else {
                    var fields = Array.prototype.filter.call(container.querySelectorAll('[name$="[' + seg + ']"], [name*="[' + seg + '["]'), function (el) {
                        return container === form || el.closest('[data-item]') === container;
                    });
                    if (fields.length) {
                        field = fields[0];
                    } else {
                        // a group of the document (project, author, sqlImpact): its fields are looked up inside it
                        var group = Array.prototype.filter.call(container.querySelectorAll('[name*="[' + seg + '["]'), function (el) {
                            return container === form || el.closest('[data-item]') === container;
                        });
                        if (group.length) {
                            container = group[0].closest('fieldset') || container;
                        }
                    }
                }
            }
            i += 1;
        }
        if (field === null && list === null && block === null && container !== form) {
            block = container;
        }
        if (field) {
            crumbs.push(fieldLabel(field));
        }
        return { field: field, block: block, crumbs: crumbs };
    }

    function closestItems(el) {
        var out = [];
        var node = el ? el.closest('[data-item]') : null;
        while (node && node !== form) {
            out.push(node);
            node = node.parentElement ? node.parentElement.closest('[data-item]') : null;
        }
        return out;
    }

    function ensureMessage(field, id, text) {
        var wrap = field.closest('.field') || field.parentElement;
        var msg = document.getElementById(id);
        if (!msg) {
            msg = document.createElement('div');
            msg.id = id;
            msg.className = 'field-error';
            wrap.appendChild(msg);
        }
        msg.textContent = text;
        field.setAttribute('aria-invalid', 'true');
        field.setAttribute('aria-describedby', id);
        (field.closest('.field') || field).classList.add('has-error');
    }

    function unmarkField(field) {
        var wrap = field.closest('.field') || field;
        wrap.classList.remove('has-error');
        field.removeAttribute('aria-invalid');
        var id = field.getAttribute('aria-describedby');
        field.removeAttribute('aria-describedby');
        var msg = id && document.getElementById(id);
        if (msg) {
            msg.remove();
        }
    }

    function countUp(item) {
        item.classList.add('has-error');
        var bar = item.querySelector(':scope > .item-bar');
        if (!bar) {
            return;
        }
        var badge = bar.querySelector(':scope > .error-count');
        var n = badge ? parseInt(badge.textContent, 10) + 1 : 1;
        if (!badge) {
            badge = document.createElement('span');
            badge.className = 'error-count';
            bar.insertBefore(badge, bar.firstChild);
        }
        badge.textContent = String(n);
        badge.title = n + (n === 1 ? ' error' : ' errors') + ' in this block';
        item.setAttribute('data-error-count', String(n));
    }

    function renderPanel() {
        if (!panel) {
            return;
        }
        var list = panel.querySelector('[data-error-list]');
        list.innerHTML = '';
        marked.forEach(function (entry) {
            var li = document.createElement('li');
            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'error-link';
            button.textContent = entry.crumbs + ' — ' + entry.message;
            button.addEventListener('click', function () { focusError(entry); });
            li.appendChild(button);
            list.appendChild(li);
        });
        panel.querySelector('[data-error-count]').textContent = String(marked.length);
        panel.hidden = marked.length === 0;
    }

    function focusError(entry) {
        var target = entry.field || entry.block;
        if (!target) {
            return;
        }
        if (window.TACollapse) {
            window.TACollapse.expandTo(target);
        }
        var reduce = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        target.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'center' });
        if (entry.field && typeof entry.field.focus === 'function') {
            entry.field.focus({ preventScroll: true });
        }
    }

    // the outline shows the sections that hold an error
    function markOutline() {
        if (window.TADocNav && window.TADocNav.refresh) {
            window.TADocNav.refresh();
        }
    }

    function show(errors) {
        clear();
        if (!form) {
            return;
        }
        (errors || []).forEach(function (error) {
            var r = resolve(error.path);
            var entry = {
                field: r.field,
                block: r.block,
                path: error.path,
                message: error.message,
                crumbs: r.crumbs.length ? r.crumbs.join(' › ') : String(error.path),
                id: 'field-error-' + (++counter)
            };
            if (entry.field) {
                ensureMessage(entry.field, entry.id, error.message);
            }
            var target = entry.field || entry.block;
            if (target) {
                closestItems(target).forEach(countUp);
                if (entry.block && entry.block.hasAttribute && entry.block.hasAttribute('data-item')) {
                    countUp(entry.block);
                }
            }
            marked.push(entry);
        });
        renderPanel();
        markOutline();
    }

    function clear() {
        marked.forEach(function (entry) {
            if (entry.field) {
                unmarkField(entry.field);
            }
        });
        if (form) {
            Array.prototype.forEach.call(form.querySelectorAll('.has-error, .has-error-within'), function (el) {
                el.classList.remove('has-error', 'has-error-within');
            });
            Array.prototype.forEach.call(form.querySelectorAll('[data-item][data-error-count]'), function (el) {
                el.removeAttribute('data-error-count');
            });
            Array.prototype.forEach.call(form.querySelectorAll('.error-count'), function (el) { el.remove(); });
        }
        marked = [];
        renderPanel();
        markOutline();
    }

    // changing a field withdraws its error: the person is fixing it
    function onInput(event) {
        var target = event.target;
        marked = marked.filter(function (entry) {
            if (entry.field === target) {
                unmarkField(target);
                return false;
            }
            return true;
        });
        renderPanel();
    }

    function init() {
        form = document.getElementById('editor');
        panel = document.querySelector('[data-error-panel]');
        if (!form) {
            return;
        }
        form.addEventListener('input', onInput);
        form.addEventListener('change', onInput);
        var initial = [];
        try {
            initial = JSON.parse(form.getAttribute('data-errors') || '[]');
        } catch (error) {
            initial = [];
        }
        if (initial.length) {
            show(initial);
        }
    }

    window.TAErrors = { show: show, clear: clear, count: function () { return marked.length; } };
    document.addEventListener('DOMContentLoaded', init);
})();
