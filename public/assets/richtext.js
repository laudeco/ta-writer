// Rich text fields of the editor, on Quill. Quill edits HTML; the server writes the value in the format of the document
// when it saves. A field that nobody touched is never sent back as HTML: its stored value is kept (see FormDataNormalizer).
(function () {
    'use strict';

    if (typeof window.Quill === 'undefined') {
        return; // the editor page loads Quill; other pages do not need this script
    }

    var editors = new WeakMap();

    function toolbarFor(format) {
        if (format === 'text') {
            return false; // plain text: no formatting to offer
        }
        return [
            [{ header: [1, 2, 3, false] }],
            ['bold', 'italic', 'strike', 'code'],
            ['link', 'blockquote', 'code-block'],
            [{ list: 'ordered' }, { list: 'bullet' }, { list: 'check' }],
            ['table'],
            ['clean']
        ];
    }

    function shell(el) {
        return el.closest('[data-rich-shell]');
    }

    function field(el) {
        return el.closest('[data-rich]');
    }

    function markEdited(el, quill) {
        var f = field(el);
        f.querySelector('[data-rich-mode]').value = 'html';
        f.querySelector('[data-rich-html]').value = quill.getSemanticHTML();
        f.classList.add('is-edited');
        // the hidden fields changed: the form and the preview listen to input events like for any field
        f.querySelector('[data-rich-html]').dispatchEvent(new Event('input', { bubbles: true }));
    }

    function insertTable(quill) {
        var table = quill.getModule('table');
        if (table) {
            table.insertTable(2, 2);
        }
    }

    function instantiate(el) {
        if (editors.has(el) || el.getAttribute('data-rich-ready') === 'true') {
            return editors.get(el);
        }
        var format = shell(el).getAttribute('data-format') || 'markdown';
        var toolbar = toolbarFor(format);
        var modules = {};
        if (toolbar) {
            modules.toolbar = {
                container: toolbar,
                handlers: { table: function () { insertTable(quill); } }
            };
        }
        modules.table = true;
        var quill = new window.Quill(el, { theme: 'snow', modules: modules, placeholder: 'Write here…' });
        if (!toolbar) {
            el.closest('[data-rich]').classList.add('format-text');
        }
        // the stored value, shown as the editor's starting point. 'silent': it is not an edit.
        var html = el.getAttribute('data-html') || '';
        if (html !== '') {
            // setContents, not dangerouslyPasteHTML: the paste moves the cursor into this editor, which would steal the focus
            // from the field the person is typing in whenever an editor starts (on scroll, or on a click elsewhere)
            quill.setContents(quill.clipboard.convert({ html: html }), 'silent');
        }
        quill.on('text-change', function (delta, oldDelta, source) {
            if (source === 'user') {
                markEdited(el, quill);
            }
        });
        el.setAttribute('data-rich-ready', 'true');
        editors.set(el, quill);
        return quill;
    }

    function setSourceMode(f, on) {
        var source = f.querySelector('[data-rich-source-view]');
        var editor = f.querySelector('[data-rich-editor]');
        var toggle = f.querySelector('[data-rich-toggle]');
        source.hidden = !on;
        editor.hidden = on;
        toggle.setAttribute('aria-pressed', on ? 'true' : 'false');
        toggle.textContent = on ? 'Rich text' : 'Source';
    }

    // the Source view edits the stored value (in the format of the document): it becomes the value of this field
    function wireSource(f) {
        var textarea = f.querySelector('[data-rich-source-view] textarea');
        textarea.addEventListener('input', function () {
            f.querySelector('[data-rich-mode]').value = 'source';
            f.classList.add('is-edited');
        });
    }

    function addChrome(f) {
        if (f.querySelector('[data-rich-toggle]')) {
            return;
        }
        var toggle = document.createElement('button');
        toggle.type = 'button';
        toggle.className = 'btn small ghost rich-toggle';
        toggle.setAttribute('data-rich-toggle', '');
        toggle.setAttribute('aria-pressed', 'false');
        toggle.textContent = 'Source';
        toggle.addEventListener('click', function () {
            var on = f.querySelector('[data-rich-source-view]').hidden;
            if (on) {
                // leaving the rich editor: its last content is what the Source view shows if it was never saved
                setSourceMode(f, true);
            } else {
                setSourceMode(f, false);
            }
        });
        f.querySelector('.field-label').appendChild(toggle);
        wireSource(f);
    }

    function observe(el) {
        if (!('IntersectionObserver' in window)) {
            instantiate(el);
            return;
        }
        var io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    instantiate(entry.target);
                    io.unobserve(entry.target);
                }
            });
        }, { rootMargin: '300px 0px' });
        io.observe(el);
    }

    function initField(f) {
        var el = f.querySelector('[data-rich-editor]');
        if (!el || el.getAttribute('data-rich-init') === 'true') {
            return;
        }
        el.setAttribute('data-rich-init', 'true');
        addChrome(f);
        observe(el);
        // a click on the field starts the editor right away, even before it scrolls into view
        el.addEventListener('click', function () { instantiate(el); }, { once: true });
    }

    // switching the document format: the editors change with it
    function wireFormat(select) {
        var notice = document.querySelector('[data-format-notice]');
        var original = document.querySelector('input[name="_format_original"]');
        var previous = select.value;

        select.addEventListener('change', function () {
            var target = select.value;
            if (target === 'text') {
                var losing = [];
                document.querySelectorAll('[data-rich]').forEach(function (f) {
                    var stored = f.querySelector('[data-rich-editor]').getAttribute('data-html') || '';
                    if (stored.replace(/<[^>]*>/g, '').trim() !== '' || f.classList.contains('is-edited')) {
                        losing.push(f);
                    }
                });
                var question = losing.length + ' field(s) will lose their formatting (lists, tables, code, links) and become plain text. Continue?';
                if (losing.length > 0 && !window.confirm(question)) {
                    select.value = previous;
                    return;
                }
                // the editors already open change now, under the eyes of the person; the others are converted on save
                document.querySelectorAll('[data-rich-editor][data-rich-ready="true"]').forEach(function (el) {
                    var quill = editors.get(el);
                    if (quill) {
                        var text = quill.getText().replace(/\n+$/, '');
                        quill.setText(text, 'user');
                    }
                });
            }
            previous = target;
            document.querySelectorAll('[data-rich-shell]').forEach(function (s) {
                s.setAttribute('data-format', target);
                if (target === 'text') {
                    s.closest('[data-rich]').classList.add('format-text');
                } else {
                    s.closest('[data-rich]').classList.remove('format-text');
                }
            });
            if (notice && original) {
                var changed = target !== original.value;
                notice.hidden = !changed;
                notice.querySelector('[data-format-target]').textContent = target;
            }
        });
    }

    // a resource is rich only when its type is a note: show the matching input
    function wireConditions(root) {
        root.querySelectorAll('[data-when-field]').forEach(function (f) {
            var item = f.closest('[data-item]') || f.closest('fieldset');
            var driver = item && item.querySelector('select[data-field="' + f.getAttribute('data-when-field') + '"]');
            if (!driver) {
                return;
            }
            // the rich body and the Source button follow the condition; the plain field is shown when the body is not
            var apply = function () {
                var on = driver.value === f.getAttribute('data-when-equals');
                var body = f.querySelector('[data-rich-body]');
                if (body) {
                    body.hidden = !on;
                }
                f.querySelectorAll('[data-rich-mode], [data-rich-html], [data-rich-source-view] textarea, input[name$="[original]"]').forEach(function (i) {
                    i.disabled = !on;
                });
                var toggle = f.querySelector('[data-rich-toggle]');
                if (toggle) {
                    toggle.hidden = !on;
                }
                var plain = f.querySelector('.plain-fallback');
                if (plain) {
                    plain.disabled = on;
                    plain.hidden = on;
                }
            };
            apply();
            driver.addEventListener('change', apply);
        });
    }

    function initIn(root) {
        (root || document).querySelectorAll('[data-rich]').forEach(initField);
        wireConditions(root || document);
    }

    document.addEventListener('DOMContentLoaded', function () {
        initIn(document);
        var format = document.querySelector('select[data-field="format"]');
        if (format) {
            wireFormat(format);
        }
    });

    window.TARichText = { initIn: initIn };
})();
