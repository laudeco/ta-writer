// Autosave of the editor: a change is saved as a version once the person stops typing (the delay is set by the server,
// TA_AUTOSAVE_SECONDS). It uses the same validation and versioning as the Save button, and a save is sent only when the
// content changed. A document that fails validation is not saved: the autosave pauses until the form is valid again.
(function () {
    'use strict';

    var form = null;
    var status = null;
    var toggle = null;
    var delay = 5;
    var timer = null;
    var inFlight = false;
    var again = false;
    var stopped = false;        // a conflict or a format change: nothing is saved until the page is reloaded
    var lastSaved = '';
    var retryTimer = null;
    var retries = 0;
    var TOGGLE_KEY = 'ta-autosave';

    function snapshot() {
        var parts = [];
        new FormData(form).forEach(function (value, key) {
            // the version fields are set by the server after a save: they are not content
            if (key !== '_version' && key !== 'data[version]' && key !== 'data[structureVersion]') {
                parts.push(key + '=' + value);
            }
        });

        return parts.join('\n');
    }

    function say(text, extra) {
        if (!status) {
            return;
        }
        status.textContent = text;
        if (extra) {
            status.appendChild(document.createTextNode(' '));
            status.appendChild(extra);
        }
    }

    function reloadButton() {
        var button = document.createElement('button');
        button.type = 'button';
        button.className = 'small';
        button.textContent = 'Reload';
        button.addEventListener('click', function () { window.location.reload(); });

        return button;
    }

    function clock(time) {
        return time || new Date().toTimeString().slice(0, 8);
    }

    function schedule() {
        if (!toggle || !toggle.checked || stopped) {
            return;
        }
        clearTimeout(timer);
        clearTimeout(retryTimer);
        say('Unsaved changes, saving in ' + delay + ' s…');
        timer = setTimeout(save, delay * 1000);
    }

    // the version shown on the page follows what was saved
    function showVersion(body) {
        var version = form.querySelector('input[name="data[version]"]');
        if (version && body.documentVersion) {
            version.value = body.documentVersion;
        }
        document.querySelectorAll('.doc-head .meta .pill').forEach(function (pill) {
            if (pill.classList.contains('primary') && body.documentVersion) {
                pill.textContent = 'v' + body.documentVersion;
            } else if (/saved$/.test(pill.textContent)) {
                pill.textContent = body.history + ' saved';
            }
        });
    }

    function handleSaved(body, sent) {
        status.classList.remove('is-error');
        status.title = '';
        if (window.TAErrors) {
            window.TAErrors.clear();
        }
        if (form.getAttribute('action') === '/documents' && body.id) {
            // the first autosave of a new analysis: the page carries on with the new document
            form.setAttribute('action', '/documents/' + body.id);
            var hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = '_version';
            hidden.value = body.version;
            form.appendChild(hidden);
            window.history.replaceState(null, '', '/documents/' + body.id + '/edit');
        } else {
            var versionInput = form.querySelector('input[name="_version"]');
            if (versionInput) {
                versionInput.value = body.version;
            }
        }
        showVersion(body);

        var originalFormat = form.querySelector('input[name="_format_original"]');
        if (originalFormat && body.format && body.format !== originalFormat.value) {
            // the stored text of the untouched rich fields is now in the new format: the page must be loaded again
            stopped = true;
            lastSaved = sent;
            say('Saved as ' + body.format + '. Reload the page to keep editing: the rich fields now hold the converted text.', reloadButton());
            return;
        }

        lastSaved = sent;
        retries = 0;
        if (snapshot() === sent) {
            say(body.changed ? 'Saved ' + clock(body.savedAt) + ' · v' + body.documentVersion : 'Saved ' + clock(body.savedAt) + ' · no change');
            form.dispatchEvent(new Event('ta:saved', { bubbles: true }));
        }
    }

    function save() {
        clearTimeout(timer);
        if (stopped || !toggle || !toggle.checked) {
            return;
        }
        if (inFlight) {
            again = true;
            return;
        }
        var sent = snapshot();
        if (sent === lastSaved) {
            return;
        }
        inFlight = true;
        say('Saving…');

        fetch(form.getAttribute('action'), {
            method: 'POST',
            body: new FormData(form),
            headers: { Accept: 'application/json' }
        }).then(function (response) {
            return response.json().catch(function () { return { ok: false }; }).then(function (body) {
                return { status: response.status, body: body };
            });
        }).then(function (result) {
            var body = result.body;
            if (result.status === 409) {
                stopped = true;
                say('A newer version was saved elsewhere. Nothing was overwritten.', reloadButton());
                return;
            }
            if (result.status === 422 || (body && body.ok === false && body.errors)) {
                // nothing was stored: the errors are shown on their fields, in the panel above the form
                var errors = body.errors || [];
                if (window.TAErrors) {
                    window.TAErrors.show(errors);
                }
                status.classList.add('is-error');
                say('Not saved: ' + errors.length + (errors.length === 1 ? ' error' : ' errors') + ' to fix. Nothing is stored until they are fixed.');
                status.title = errors.map(function (e) { return e.path + ': ' + e.message; }).join('\n');
                return;
            }
            if (body && body.ok) {
                handleSaved(body, sent);
                return;
            }
            throw new Error('HTTP ' + result.status);
        }).catch(function () {
            // the server did not answer: try again, a little later each time
            retries += 1;
            var wait = Math.min(60, 10 * retries);
            say('Could not save (offline or server error). Retrying in ' + wait + ' s…');
            clearTimeout(retryTimer);
            retryTimer = setTimeout(save, wait * 1000);
        }).then(function () {
            inFlight = false;
            if (again) {
                again = false;
                schedule();
            }
        });
    }

    function init() {
        form = document.getElementById('editor');
        if (!form) {
            return;
        }
        delay = parseInt(form.getAttribute('data-autosave-seconds') || '0', 10);
        toggle = document.querySelector('[data-autosave-toggle]');
        status = document.querySelector('[data-autosave-status]');
        if (!toggle || delay <= 0) {
            return;
        }
        try {
            if (localStorage.getItem(TOGGLE_KEY) === '0') {
                toggle.checked = false;
            }
        } catch (error) {
            // storage blocked: autosave stays on
        }
        lastSaved = snapshot();
        say(toggle.checked ? 'Autosave on' : 'Autosave off');

        toggle.addEventListener('change', function () {
            try {
                localStorage.setItem(TOGGLE_KEY, toggle.checked ? '1' : '0');
            } catch (error) {
                // the choice lasts for this page only
            }
            if (toggle.checked) {
                say('Autosave on');
                schedule();
            } else {
                clearTimeout(timer);
                say('Autosave off');
            }
        });
        form.addEventListener('input', schedule);
        form.addEventListener('change', schedule);
        form.addEventListener('ta:changed', schedule);
        document.addEventListener('keydown', function (event) {
            if ((event.ctrlKey || event.metaKey) && event.key === 's') {
                event.preventDefault();
                save();
            }
        });
    }

    document.addEventListener('DOMContentLoaded', init);
})();
