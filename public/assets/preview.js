// Live preview of the section in view (or the whole document), on the right of the editor. The server renders the
// document with the Markdown renderer of the export. Its diagrams are images rendered by the server in the background:
// they arrive one by one, two at a time, and never hold up the editing. A click opens a diagram in the diagram modal.
(function () {
    'use strict';

    var NAVIGABLE = { epics: true, technicalStories: true, userStories: true, subtasks: true };
    var PARALLEL = 2;
    var root, body, status, form, mode = 'section', timer = null, controller = null, lastPath = null;
    var images = new Map(); // diagram source -> object URL of its PNG, kept for the page
    var pending = new Map(); // diagram source -> request in flight
    var queue = [];
    var running = 0;
    var requestId = 0; // only the answer to the latest request is shown

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

    function setStatus(text) {
        if (status) {
            status.textContent = text;
        }
    }

    // a warning above the preview: the last good preview stays, dimmed, with the reason and a retry
    function showWarning(lines) {
        var box = root.querySelector('[data-preview-warning]');
        var text = root.querySelector('[data-preview-warning-text]');
        var list = root.querySelector('[data-preview-warning-list]');
        text.textContent = lines[0];
        list.innerHTML = '';
        lines.slice(1).forEach(function (line) {
            var li = document.createElement('li');
            li.textContent = line;
            list.appendChild(li);
        });
        list.hidden = lines.length < 2;
        box.hidden = false;
        body.classList.add('is-stale');
        setStatus('Preview out of date');
        var dot = document.querySelector('[data-preview-dot]');
        if (dot) {
            dot.hidden = false;
        }
    }

    function clearWarning() {
        root.querySelector('[data-preview-warning]').hidden = true;
        body.classList.remove('is-stale');
        var dot = document.querySelector('[data-preview-dot]');
        if (dot) {
            dot.hidden = true;
        }
    }

    // diagrams that could not be rendered, in the background; shown once the queue is empty
    var diagramFailures = [];
    function diagramsSettled() {
        if (running === 0 && queue.length === 0 && diagramFailures.length > 0) {
            var n = diagramFailures.length;
            showWarning([n + (n === 1 ? ' diagram could not be rendered' : ' diagrams could not be rendered')].concat(diagramFailures));
        }
    }

    // ---------- diagrams: images from the server, in the background ----------

    function loadImage(source) {
        if (images.has(source)) {
            return Promise.resolve(images.get(source));
        }
        // the same diagram twice in the document is rendered once: two renderings of one source would clash on the server
        if (pending.has(source)) {
            return pending.get(source);
        }
        var data = new FormData();
        data.set('source', source);

        var request = fetch('/editor/preview/diagram', { method: 'POST', body: data }).then(function (response) {
            if (!response.ok) {
                return response.text().then(function (message) { throw new Error(message || 'HTTP ' + response.status); });
            }

            return response.blob();
        }).then(function (blob) {
            var url = URL.createObjectURL(blob);
            images.set(source, url);
            pending.delete(source);

            return url;
        }, function (error) {
            pending.delete(source);
            throw error;
        });
        pending.set(source, request);

        return request;
    }

    // one diagram: its image is shown in its own figure (the job is a parameter, not a shared variable)
    function showDiagram(job) {
        return loadImage(job.source).then(function (url) {
            if (job.figure.isConnected) {
                var img = new Image();
                img.alt = 'Diagram';
                img.src = url;
                job.figure.classList.remove('is-pending');
                job.figure.replaceChildren(img);
                job.figure.title = 'Click to view the diagram';
                job.figure.addEventListener('click', function () { window.TADiagram.show(job.source); });
            }
        }, function (error) {
            diagramFailures.push(String(error && error.message ? error.message : error).split('\n')[0]);
            if (job.figure.isConnected) {
                job.figure.classList.remove('is-pending');
                job.figure.classList.add('is-error');
                var label = document.createElement('span');
                label.className = 'diagram-error';
                label.textContent = 'Diagram unavailable: ' + String(error && error.message ? error.message : error).split('\n')[0];
                job.figure.replaceChildren(label);
                job.figure.title = 'Click to see the source';
                job.figure.addEventListener('click', function () { window.TADiagram.show(job.source); });
            }
        });
    }

    function pump() {
        while (running < PARALLEL && queue.length > 0) {
            var next = queue.shift();
            if (!next.figure.isConnected) {
                continue; // the preview was replaced meanwhile
            }
            running += 1;
            showDiagram(next).then(function () {
                running -= 1;
                pump();
                diagramsSettled();
            });
        }
    }

    // the queue is rebuilt from what is shown: the diagrams still pending in the preview, in document order
    function queueDiagrams() {
        queue.length = 0;
        body.querySelectorAll('figure.is-pending').forEach(function (figure) {
            var code = figure.querySelector('code.language-mermaid');
            if (code) {
                queue.push({ figure: figure, source: code.textContent });
            }
        });
        pump();
    }

    // ---------- updates ----------

    function update() {
        clearTimeout(timer);
        var path = currentPath();
        if (controller) {
            controller.abort();
        }
        var id = ++requestId;
        diagramFailures = [];
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
                return response.text().then(function (message) {
                    throw new Error(message || 'The preview could not be generated (HTTP ' + response.status + ').');
                });
            }

            return response.text();
        }).then(function (html) {
            if (id !== requestId) {
                return; // a newer preview was asked for meanwhile
            }
            if (path !== lastPath) {
                body.scrollTop = 0;
            }
            lastPath = path;
            body.innerHTML = html;
            clearWarning();
            setStatus('updated ' + new Date().toTimeString().slice(0, 8));
            queueDiagrams();
        }).catch(function (error) {
            if (id !== requestId || (error && error.name === 'AbortError')) {
                return; // superseded by a newer request: not an error
            }
            // a network failure is a TypeError from fetch: the server did not answer at all
            var reason = error instanceof TypeError ? 'The server did not answer. Check that it is running.' : String(error.message || error).split('\n')[0];
            showWarning(['Preview could not be updated.', reason]);
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

        root.querySelector('[data-preview-retry]').addEventListener('click', function () { update(); });
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
