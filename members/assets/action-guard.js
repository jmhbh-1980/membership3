/**
 * One action at a time.
 *
 * None of the state-changing actions in this app are idempotent: a second
 * "Relancer" is a second email, a second "Semelles OK — activer" is a second
 * Balle Jaune write, a second "Envoyer aux membres sélectionnés" is a second
 * campaign. Every one of them is a plain POST form that navigates the page on
 * success, so the first submit locks the page until the response lands: the
 * clicked button keeps its full colour and swaps its label for a spinner, every
 * other submit button goes disabled, and any further submit is refused.
 *
 * A submit that answers with a file instead (data-download on its button)
 * never leaves the page, so nothing would ever unlock it. Its form posts a
 * one-off token, the server sends it back as a cookie on the file response
 * (App\Support\DownloadToken), and the page unlocks the moment that cookie
 * shows up: the spinner lasts exactly as long as the file takes to build.
 */
(function () {
    'use strict';

    var BUSY_TEXT = 'Traitement en cours…';
    var SUBMIT_SELECTOR = 'button:not([type="button"]):not([type="reset"]), input[type="submit"], input[type="image"]';
    var DOWNLOAD_COOKIE = 'download_token';
    var DOWNLOAD_TIMEOUT_MS = 120000; // a server that never answers with the cookie must not lock the page for good

    var locked = false;
    var undo = [];

    /**
     * The button that triggered this submit. event.submitter is the answer
     * everywhere it exists — the fallbacks cover an older browser and the
     * keyboard's implicit submission, which has no submitter at all.
     */
    function submitterOf(event, form) {
        if (event.submitter) {
            return event.submitter;
        }
        var active = document.activeElement;
        if (active && active.matches && active.matches(SUBMIT_SELECTOR) && active.form === form) {
            return active;
        }
        return form.querySelector(SUBMIT_SELECTOR);
    }

    function markBusy(button) {
        var rect = button.getBoundingClientRect();
        var label = (button.textContent || '').trim();
        var announced = label !== '' ? label + ' — ' + BUSY_TEXT : BUSY_TEXT;
        var markup = button.innerHTML;

        undo.push(function () {
            button.innerHTML = markup;
            button.classList.remove('is-busy');
            button.removeAttribute('aria-busy');
            button.removeAttribute('aria-disabled');
            button.removeAttribute('title');
            button.style.minInlineSize = '';
            button.style.minBlockSize = '';
            button.style.backgroundColor = '';
            button.style.color = '';
            button.style.opacity = '';
        });

        // Pin the box at its current size before emptying it, so a row of
        // actions doesn't jump around the moment one of them starts, and
        // freeze the colours it has right now: the checkout pages disable
        // their own pay button a tick after submit, and the :disabled grey
        // would say "you can't do this" over an action already under way.
        var shown = window.getComputedStyle(button);
        button.style.backgroundColor = shown.backgroundColor;
        button.style.color = shown.color;
        button.style.opacity = '1';
        button.style.minInlineSize = rect.width + 'px';
        button.style.minBlockSize = rect.height + 'px';
        button.classList.add('is-busy');
        button.setAttribute('aria-busy', 'true');
        // aria-disabled, never disabled: a disabled submitter is dropped from
        // the entry list, which would lose the name/value of the button that
        // carries the decision (`name="decision" value="approve"`).
        button.setAttribute('aria-disabled', 'true');
        button.title = announced;

        if (button.tagName !== 'BUTTON') {
            return;
        }
        var spinner = document.createElement('span');
        spinner.className = 'btn-spinner';
        spinner.setAttribute('aria-hidden', 'true');
        var reader = document.createElement('span');
        reader.className = 'sr-only';
        reader.textContent = announced;
        button.innerHTML = '';
        button.append(spinner, reader);
    }

    function disableOthers(busy) {
        document.querySelectorAll(SUBMIT_SELECTOR).forEach(function (el) {
            if (el === busy || el.disabled) {
                return;
            }
            el.disabled = true;
            undo.push(function () { el.disabled = false; });
        });
    }

    function showProgress() {
        var bar = document.createElement('div');
        bar.className = 'action-progress';
        bar.setAttribute('role', 'status');
        var reader = document.createElement('span');
        reader.className = 'sr-only';
        reader.textContent = BUSY_TEXT;
        bar.appendChild(reader);
        document.body.appendChild(bar);
        undo.push(function () { bar.remove(); });
    }

    function lock(button) {
        locked = true;
        if (button) {
            markBusy(button);
        }
        disableOthers(button);
        showProgress();
    }

    function unlock() {
        locked = false;
        while (undo.length) {
            undo.pop()();
        }
    }

    /** Fields added during the submit event still make it into the posted data. */
    function awaitDownload(form) {
        var token = Array.prototype.map.call(window.crypto.getRandomValues(new Uint8Array(16)), function (b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
        var field = document.createElement('input');
        field.type = 'hidden';
        field.name = DOWNLOAD_COOKIE;
        field.value = token;
        form.appendChild(field);

        var started = Date.now();
        var timer = window.setInterval(function () {
            var arrived = document.cookie.split('; ').indexOf(DOWNLOAD_COOKIE + '=' + token) !== -1;
            if (!arrived && Date.now() - started < DOWNLOAD_TIMEOUT_MS) {
                return;
            }
            window.clearInterval(timer);
            document.cookie = DOWNLOAD_COOKIE + '=; Max-Age=0; Path=/' + (location.protocol === 'https:' ? '; Secure' : '');
            field.remove();
            unlock();
        }, 250);
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) {
            return;
        }
        if (locked) {
            event.preventDefault();
            return;
        }
        // Already handled by someone closer to the form: an inline
        // onsubmit="return confirm(...)" the admin cancelled, or a form another
        // script sends over XHR (the bug report). Nothing is in flight.
        if (event.defaultPrevented) {
            return;
        }
        // GET forms are the filter bars — they change nothing.
        // The attribute, not form.method: a field named "method" would shadow it.
        if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') {
            return;
        }
        var submitter = submitterOf(event, form);
        lock(submitter);
        if (submitter && submitter.hasAttribute('data-download')) {
            awaitDownload(form);
        }
    });

    // Coming back through the history cache restores the DOM exactly as it was
    // left: mid-action, with the page still locked on an action that finished
    // long ago.
    window.addEventListener('pageshow', function (event) {
        if (event.persisted) {
            unlock();
        }
    });
})();
