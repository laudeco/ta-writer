// Live preview of the section in view (or the whole document), on the right of the editor. The server renders it with the
// Markdown renderer of the export; diagrams are drawn here with Mermaid, and a click opens them in the diagram modal.
(function () {
    'use strict';

    var NAVIGABLE = { epics: true, technicalStories: true, userStories: true, subtasks: true };
    var root, body, status, form, mode = 'section', timer = null, controller = null, lastPath = null;

    // "epics.0.technicalStories.2": the kind and the position of each item from the top of the document
    function pathOf(el) {
        if (el.hasAttribute('data-nav-section')) {
            return 'header';
        }
        var segments = [];
        var node = el;
        while (node && node !== form) {
            if (node.hasAttribute && node.hasAttribute('data-item')) {
                var collection = node.closest('[data-collection]');
                var kind = collection && collection.getAttribute('data-nav');
                if (NAVIGABLE[kind]) {
                    var list = collection.querySelector(':scope > [data-items]');
                    var items = Array.prototype.filter.call(list.children, function (c) { return c.hasAttribute('data-item'); });
                    segments.unshift(kind, items.indexOf(node));
                }
            }
            node = node.parentElement;
        }

        return segments.length ? segments.join('.') : 'header';
    }

    function currentPath() {
        if (mode === 'document') {
            return 'document';
        }
        var id = window.TADocNav ? window.TADocNav.active() : null;
        var el = id ? document.getElementById(id) : null;

        return el ? pathOf(el) : 'header';
    }

    function renderDiagrams() {
        if (!window.TADiagram) {
            return;
        }
        body.querySelectorAll('pre > code.language-mermaid').forEach(function (code) {
            var source = code.textContent;
            var figure = document.createElement('figure');
            figure.className = 'preview-diagram';
            figure.innerHTML = '<span class="muted">Diagram…</span>';
            code.parentElement.replaceWith(figure);
            window.TADiagram.render(source).then(function (svg) {
                figure.innerHTML = svg;
                figure.title = 'Click to view the diagram';
                figure.addEventListener('click', function () { window.TADiagram.show(source); });
            }, function () {
                figure.innerHTML = '<span class="diagram-error">Invalid diagram</span><pre></pre>';
                figure.querySelector('pre').textContent = source;
            });
        });
    }

    function setStatus(text) {
        if (status) {
            status.textContent = text;
        }
    }

    function update() {
        clearTimeout(timer);
        var path = currentPath();
        if (controller) {
            controller.abort();
        }
        controller = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var data = new FormData(form);
        data.set('path', path);
        setStatus('updating…');
        fetch('/editor/preview', {
            method: 'POST',
            body: data,
            headers: { Accept: 'text/html' },
            signal: controller ? controller.signal : undefined
        }).then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }

            return response.text();
        }).then(function (html) {
            if (path !== lastPath) {
                body.scrollTop = 0;
            }
            lastPath = path;
            body.innerHTML = html;
            renderDiagrams();
            var now = new Date();
            setStatus('updated ' + now.toTimeString().slice(0, 8));
        }).catch(function (error) {
            if (error && error.name === 'AbortError') {
                return;
            }
            setStatus('preview unavailable');
        });
    }

    function schedule() {
        clearTimeout(timer);
        timer = setTimeout(update, 600);
    }

    function markModes() {
        root.querySelectorAll('[data-preview-mode]').forEach(function (button) {
            button.setAttribute('aria-pressed', button.getAttribute('data-preview-mode') === mode ? 'true' : 'false');
        });
    }

    function setOpen(open) {
        root.classList.toggle('is-open', open);
        var toggle = document.querySelector('[data-preview-toggle]');
        if (toggle) {
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        }
    }

    function init() {
        root = document.querySelector('[data-preview]');
        form = document.getElementById('editor');
        if (!root || !form) {
            return;
        }
        body = root.querySelector('[data-preview-body]');
        status = root.querySelector('[data-preview-status]');

        root.querySelectorAll('[data-preview-mode]').forEach(function (button) {
            button.addEventListener('click', function () {
                mode = button.getAttribute('data-preview-mode');
                markModes();
                update();
            });
        });
        root.querySelector('[data-preview-close]').addEventListener('click', function () { setOpen(false); });
        var toggle = document.querySelector('[data-preview-toggle]');
        if (toggle) {
            toggle.addEventListener('click', function () { setOpen(!root.classList.contains('is-open')); });
        }

        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);
        if (window.TADocNav) {
            window.TADocNav.onActiveChange(function () {
                if (mode === 'section') {
                    update();
                }
            });
        }
        markModes();
        update();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
