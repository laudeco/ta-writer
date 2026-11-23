// Diagrams (Mermaid). A diagram field is a card with a thumbnail; View and Edit open one modal, which shows the diagram
// with zoom and pan, and the code in edit mode. Mermaid is loaded from the project on first use, never from a CDN.
(function () {
    'use strict';

    var MERMAID_URL = '/assets/vendor/mermaid/mermaid.min.js';
    var mermaidPromise = null;
    var renderCount = 0;

    var EXAMPLES = {
        flowchart: 'flowchart TD\n    A[Start] --> B{Decision}\n    B -->|yes| C[Do it]\n    B -->|no| D[Skip]',
        sequence: 'sequenceDiagram\n    participant U as User\n    participant API\n    U->>API: request\n    API-->>U: response',
        class: 'classDiagram\n    class Document {\n        +string version\n    }\n    class Epic\n    Document "1" o-- "*" Epic',
        state: 'stateDiagram-v2\n    [*] --> Draft\n    Draft --> Published\n    Published --> [*]',
        er: 'erDiagram\n    PROJECT ||--o{ EPIC : contains\n    EPIC ||--o{ SUBTASK : contains'
    };

    function loadMermaid() {
        if (window.mermaid) {
            return Promise.resolve(window.mermaid);
        }
        if (mermaidPromise) {
            return mermaidPromise;
        }
        mermaidPromise = new Promise(function (resolve, reject) {
            var script = document.createElement('script');
            script.src = MERMAID_URL;
            script.onload = function () {
                var dark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
                window.mermaid.initialize({ startOnLoad: false, securityLevel: 'strict', theme: dark ? 'dark' : 'default' });
                resolve(window.mermaid);
            };
            script.onerror = function () {
                mermaidPromise = null;
                reject(new Error('Mermaid could not be loaded'));
            };
            document.head.appendChild(script);
        });

        return mermaidPromise;
    }

    // resolves with the SVG markup; rejects with the Mermaid error when the source is not a valid diagram
    function render(source) {
        return loadMermaid().then(function (mermaid) {
            renderCount += 1;
            var id = 'diagram-' + renderCount;
            return mermaid.render(id, source).then(function (result) {
                return result.svg;
            }, function (error) {
                // Mermaid leaves its temporary container behind on error
                [document.getElementById('d' + id), document.getElementById(id)].forEach(function (node) {
                    if (node && node.parentNode) {
                        node.parentNode.removeChild(node);
                    }
                });
                throw error;
            });
        });
    }

    function firstLine(source) {
        return (source.split('\n')[0] || '').trim() || '(empty diagram)';
    }

    // ---------- thumbnails ----------

    function drawThumb(card) {
        var thumb = card.querySelector('[data-diagram-thumb]');
        var source = card.querySelector('[data-diagram-source]').value;
        if (thumb.getAttribute('data-drawn') === source) {
            return;
        }
        if (source.trim() === '') {
            thumb.innerHTML = '<span class="muted">Empty diagram</span>';
            thumb.setAttribute('data-drawn', source);
            return;
        }
        thumb.setAttribute('data-drawn', source);
        render(source).then(function (svg) {
            thumb.innerHTML = svg;
        }, function () {
            thumb.innerHTML = '<span class="diagram-error">Invalid diagram</span>';
        });
    }

    function refreshCard(card) {
        card.querySelector('[data-diagram-first]').textContent = firstLine(card.querySelector('[data-diagram-source]').value);
        card.querySelector('[data-diagram-first]').nextElementSibling.textContent =
            card.querySelector('[data-diagram-source]').value.split('\n').length + ' lines';
        drawThumb(card);
    }

    function observeThumbs(root) {
        var cards = Array.prototype.slice.call((root || document).querySelectorAll('[data-diagram]'));
        if (!('IntersectionObserver' in window)) {
            cards.forEach(drawThumb);
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    drawThumb(entry.target);
                    io.unobserve(entry.target);
                }
            });
        }, { rootMargin: '300px 0px' });
        cards.forEach(function (card) { io.observe(card); });
    }

    // ---------- modal ----------

    var dialog, canvas, stage, codeView, editor, input, errorBox, titleBox, modeBox, zoomBox, applyButton, cancelButton, editButton, codeButton, templateSelect;
    var state = null; // { card, mode, original, onApply, lastSvg, scale, x, y, natural, timer, editable }

    function ensureDialog() {
        if (dialog) {
            return;
        }
        dialog = document.getElementById('diagram-dialog');
        if (!dialog) {
            return;
        }
        canvas = dialog.querySelector('[data-dd-canvas]');
        stage = dialog.querySelector('[data-dd-stage]');
        codeView = dialog.querySelector('[data-dd-readonly]');
        editor = dialog.querySelector('[data-dd-editor]');
        input = dialog.querySelector('[data-dd-input]');
        errorBox = dialog.querySelector('[data-dd-error]');
        titleBox = dialog.querySelector('[data-dd-title]');
        modeBox = dialog.querySelector('[data-dd-mode]');
        zoomBox = dialog.querySelector('[data-dd-zoom-level]');
        applyButton = dialog.querySelector('[data-dd-apply]');
        cancelButton = dialog.querySelector('[data-dd-cancel]');
        editButton = dialog.querySelector('[data-dd-edit]');
        codeButton = dialog.querySelector('[data-dd-code]');
        templateSelect = dialog.querySelector('[data-dd-template]');

        dialog.querySelector('[data-dd-close]').addEventListener('click', requestClose);
        cancelButton.addEventListener('click', requestClose);
        applyButton.addEventListener('click', apply);
        editButton.addEventListener('click', function () { setMode('edit'); });
        codeButton.addEventListener('click', function () { setCodeVisible(codeView.parentElement.hidden); });
        dialog.querySelector('[data-dd-copy]').addEventListener('click', function () {
            var text = state && state.mode === 'edit' ? input.value : (state ? state.original : '');
            if (navigator.clipboard) {
                navigator.clipboard.writeText(text).catch(function () {});
            }
        });
        dialog.querySelectorAll('[data-zoom]').forEach(function (button) {
            button.addEventListener('click', function () { zoomAction(button.getAttribute('data-zoom')); });
        });
        templateSelect.addEventListener('change', function () {
            if (templateSelect.value && EXAMPLES[templateSelect.value]) {
                input.value = EXAMPLES[templateSelect.value];
                input.dispatchEvent(new Event('input'));
            }
            templateSelect.value = '';
        });
        input.addEventListener('input', function () {
            clearTimeout(state.timer);
            state.timer = setTimeout(liveRender, 400);
        });
        input.addEventListener('keydown', function (event) {
            if (event.key === 'Tab') {
                event.preventDefault();
                var start = input.selectionStart;
                input.value = input.value.slice(0, start) + '    ' + input.value.slice(input.selectionEnd);
                input.selectionStart = input.selectionEnd = start + 4;
                input.dispatchEvent(new Event('input'));
            }
        });
        dialog.addEventListener('cancel', function (event) {
            event.preventDefault(); // Escape goes through requestClose, which asks before losing an edit
            requestClose();
        });
        dialog.addEventListener('keydown', shortcuts);
        // the backdrop is the dialog itself (its box fills the dialog): a click outside the content closes it
        dialog.addEventListener('click', function (event) {
            if (event.target === dialog) {
                requestClose();
            }
        });
        wireStage();
    }

    function setCodeVisible(visible) {
        codeView.parentElement.hidden = !visible;
        codeButton.setAttribute('aria-pressed', visible ? 'true' : 'false');
    }

    function setMode(mode) {
        state.mode = mode;
        var editing = mode === 'edit';
        modeBox.textContent = editing ? 'Editing' : 'View';
        // edit mode: the code editor replaces the read-only listing, and the code panel is open
        editor.hidden = !editing;
        codeView.hidden = editing;
        applyButton.hidden = !editing;
        cancelButton.hidden = !editing;
        editButton.hidden = editing || !state.editable;
        codeButton.hidden = editing;
        templateSelect.hidden = !editing;
        if (editing) {
            setCodeVisible(true);
            input.value = state.current;
            input.focus();
        }
        codeView.textContent = state.current;
    }

    function open(card, mode) {
        ensureDialog();
        if (!dialog) {
            return;
        }
        var textarea = card.querySelector('[data-diagram-source]');
        state = {
            card: card,
            original: textarea.value,
            current: textarea.value,
            onApply: null,
            editable: true,
            lastSvg: null,
            scale: 1,
            x: 0,
            y: 0,
            natural: null,
            timer: null
        };
        titleBox.textContent = firstLine(textarea.value);
        codeView.textContent = textarea.value;
        setCodeVisible(false);
        setMode(mode === 'edit' ? 'edit' : 'view');
        if (!dialog.open) {
            dialog.showModal();
        }
        canvas.innerHTML = '<span class="muted">Rendering…</span>';
        liveRender(true);
    }

    // preview use: a read-only diagram from a source string, without a field behind it
    function show(source, title) {
        ensureDialog();
        if (!dialog) {
            return;
        }
        state = { card: null, original: source, current: source, onApply: null, editable: false, lastSvg: null, scale: 1, x: 0, y: 0, natural: null, timer: null };
        titleBox.textContent = title || firstLine(source);
        codeView.textContent = source;
        setCodeVisible(false);
        setMode('view');
        if (!dialog.open) {
            dialog.showModal();
        }
        liveRender(true);
    }

    function liveRender(fit) {
        if (!state) {
            return;
        }
        var owner = state; // a render that ends after the modal was closed, or reopened on another diagram, is dropped
        var source = state.mode === 'edit' ? input.value : state.current;
        state.current = source;
        codeView.textContent = source;
        render(source).then(function (svg) {
            if (state !== owner) {
                return;
            }
            state.lastSvg = svg;
            errorBox.textContent = '';
            errorBox.hidden = true;
            placeSvg(svg, fit);
        }, function (error) {
            if (state !== owner) {
                return;
            }
            // the last valid rendering stays on screen, dimmed, with the error under the editor
            errorBox.hidden = false;
            errorBox.textContent = String(error && error.message ? error.message : error).split('\n').slice(0, 4).join('\n');
            if (state.lastSvg === null) {
                canvas.innerHTML = '<span class="diagram-error">Invalid diagram: fix the code to see it.</span>';
            } else {
                canvas.classList.add('is-stale');
            }
        }).then(function () {
            if (state === owner && state.lastSvg !== null && errorBox.hidden) {
                canvas.classList.remove('is-stale');
            }
        });
    }

    function placeSvg(svg, fit) {
        canvas.innerHTML = svg;
        var el = canvas.querySelector('svg');
        if (!el) {
            return;
        }
        var box = el.viewBox && el.viewBox.baseVal && el.viewBox.baseVal.width ? el.viewBox.baseVal : null;
        var width = box ? box.width : el.getBoundingClientRect().width;
        var height = box ? box.height : el.getBoundingClientRect().height;
        el.removeAttribute('width');
        el.removeAttribute('height');
        el.style.maxWidth = 'none';
        el.style.width = width + 'px';
        el.style.height = height + 'px';
        state.natural = { width: width, height: height };
        if (fit || state.scale === 1 && state.x === 0 && state.y === 0) {
            fitToStage();
        } else {
            applyTransform();
        }
    }

    function fitToStage() {
        if (!state.natural) {
            return;
        }
        var w = stage.clientWidth - 32;
        var h = stage.clientHeight - 32;
        state.scale = Math.max(0.05, Math.min(w / state.natural.width, h / state.natural.height, 1));
        state.x = (stage.clientWidth - state.natural.width * state.scale) / 2;
        state.y = (stage.clientHeight - state.natural.height * state.scale) / 2;
        applyTransform();
    }

    function applyTransform() {
        canvas.style.transform = 'translate(' + state.x + 'px, ' + state.y + 'px) scale(' + state.scale + ')';
        zoomBox.textContent = Math.round(state.scale * 100) + '%';
    }

    function zoomAction(action) {
        if (!state || !state.natural) {
            return;
        }
        if (action === 'fit') {
            fitToStage();
            return;
        }
        if (action === 'reset') {
            zoomAround(1, stage.clientWidth / 2, stage.clientHeight / 2);
            return;
        }
        zoomAround(action === 'in' ? 1.25 : 1 / 1.25, stage.clientWidth / 2, stage.clientHeight / 2);
    }

    function zoomAround(factor, px, py) {
        var next = Math.max(0.1, Math.min(6, state.scale * factor));
        var ratio = next / state.scale;
        state.x = px - (px - state.x) * ratio;
        state.y = py - (py - state.y) * ratio;
        state.scale = next;
        applyTransform();
    }

    function wireStage() {
        stage.addEventListener('wheel', function (event) {
            if (!event.ctrlKey && !event.metaKey) {
                return;
            }
            event.preventDefault();
            var rect = stage.getBoundingClientRect();
            zoomAround(event.deltaY < 0 ? 1.1 : 1 / 1.1, event.clientX - rect.left, event.clientY - rect.top);
        }, { passive: false });

        var drag = null;
        stage.addEventListener('pointerdown', function (event) {
            drag = { x: event.clientX, y: event.clientY, ox: state.x, oy: state.y };
            stage.setPointerCapture(event.pointerId);
            stage.classList.add('is-dragging');
        });
        stage.addEventListener('pointermove', function (event) {
            if (!drag) {
                return;
            }
            state.x = drag.ox + event.clientX - drag.x;
            state.y = drag.oy + event.clientY - drag.y;
            applyTransform();
        });
        var stop = function () {
            drag = null;
            stage.classList.remove('is-dragging');
        };
        stage.addEventListener('pointerup', stop);
        stage.addEventListener('pointercancel', stop);
        stage.addEventListener('dblclick', function () { fitToStage(); });
    }

    function shortcuts(event) {
        if (!state || event.target === input || event.ctrlKey || event.metaKey || event.altKey) {
            return;
        }
        var key = event.key;
        if (key === '+' || key === '=') { zoomAction('in'); } else if (key === '-') { zoomAction('out'); } else if (key === '0') { zoomAction('fit'); } else if (key === '1') { zoomAction('reset'); } else if (key === 'c') { codeButton.click(); } else if (key === 'e' && state.editable && state.mode !== 'edit') { setMode('edit'); }
    }

    function isDirty() {
        return state && state.mode === 'edit' && input.value !== state.original;
    }

    function requestClose() {
        if (isDirty() && !window.confirm('Close without applying your changes?')) {
            return;
        }
        close();
    }

    function close() {
        clearTimeout(state && state.timer);
        dialog.close();
        state = null;
    }

    function apply() {
        if (!state || state.mode !== 'edit') {
            return;
        }
        var card = state.card;
        if (card) {
            var textarea = card.querySelector('[data-diagram-source]');
            if (textarea.value !== input.value) {
                textarea.value = input.value;
                // the form listens to input events: an applied diagram is an edit, like typing in a field
                textarea.dispatchEvent(new Event('input', { bubbles: true }));
                card.classList.add('is-edited');
                refreshCard(card);
            }
        }
        close();
    }

    // the thumbnail opens the diagram in view mode (edit when it is still empty, so it can be written)
    function openFromThumb(thumb) {
        var card = thumb.closest('[data-diagram]');
        if (!card) {
            return;
        }
        var empty = card.querySelector('[data-diagram-source]').value.trim() === '';
        open(card, empty ? 'edit' : 'view');
    }

    document.addEventListener('click', function (event) {
        var button = event.target.closest('[data-diagram-open]');
        if (button) {
            var card = button.closest('[data-diagram]');
            if (card) {
                open(card, button.getAttribute('data-diagram-open'));
            }
            return;
        }
        var thumb = event.target.closest('[data-diagram-thumb]');
        if (thumb) {
            openFromThumb(thumb);
        }
    });

    // keyboard: the thumbnail is a button (Enter or Space)
    document.addEventListener('keydown', function (event) {
        if ((event.key !== 'Enter' && event.key !== ' ') || !event.target.matches || !event.target.matches('[data-diagram-thumb]')) {
            return;
        }
        event.preventDefault();
        openFromThumb(event.target);
    });

    window.TADiagram = {
        initIn: observeThumbs,
        refresh: refreshCard,
        render: render,
        show: show,
        examples: EXAMPLES
    };

    document.addEventListener('DOMContentLoaded', function () {
        observeThumbs(document);
    });
})();
