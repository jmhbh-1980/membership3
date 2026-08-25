(function () {
    'use strict';

    var config = window.BUG_REPORT_CONFIG || {};
    var bubble = document.getElementById('bug-report-bubble');
    var modal = document.getElementById('bug-report-modal');
    var form = document.getElementById('bug-report-form');
    var cancelBtn = document.getElementById('bug-report-cancel');
    var status = modal ? modal.querySelector('.bug-report-status') : null;
    var firstnameInput = document.getElementById('br-firstname');
    var lastnameInput = document.getElementById('br-lastname');

    if (!bubble || !modal || !form) {
        return;
    }

    function openModal() {
        if (firstnameInput.value === '') { firstnameInput.value = config.firstname || ''; }
        if (lastnameInput.value === '') { lastnameInput.value = config.lastname || ''; }
        modal.hidden = false;
        firstnameInput.focus();
    }

    function closeModal() {
        modal.hidden = true;
    }

    function setStatus(message) {
        if (status) { status.textContent = message; }
    }

    function captureScreenshot() {
        if (typeof window.html2canvas !== 'function') {
            return Promise.resolve(null);
        }
        var capture = window.html2canvas(document.documentElement, {
            x: window.scrollX,
            y: window.scrollY,
            width: window.innerWidth,
            height: window.innerHeight,
            windowWidth: document.documentElement.scrollWidth,
            windowHeight: document.documentElement.scrollHeight,
            scale: 1,
            useCORS: true,
            logging: false,
        }).then(function (canvas) {
            return new Promise(function (resolve) {
                canvas.toBlob(function (blob) { resolve(blob); }, 'image/jpeg', 0.7);
            });
        }).catch(function () {
            return null;
        });

        var timeout = new Promise(function (resolve) {
            setTimeout(function () { resolve(null); }, 5000);
        });

        return Promise.race([capture, timeout]);
    }

    bubble.addEventListener('click', openModal);
    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) { closeModal(); }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.hidden) { closeModal(); }
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        var submitBtn = form.querySelector('button[type="submit"]');
        submitBtn.disabled = true;
        setStatus('Envoi en cours…');

        captureScreenshot().then(function (screenshot) {
            var data = new FormData();
            data.append('firstname', firstnameInput.value);
            data.append('lastname', lastnameInput.value);
            data.append('comment', document.getElementById('br-comment').value);
            data.append('page_url', window.location.href);
            data.append('csrf', config.csrf || '');
            if (screenshot) {
                data.append('screenshot', screenshot, 'capture.jpg');
            }

            return fetch(config.endpoint, { method: 'POST', body: data });
        }).then(function (response) {
            return response.json().then(function (payload) {
                return { ok: response.ok, payload: payload };
            });
        }).then(function (result) {
            if (result.payload && result.payload.ok) {
                setStatus('Merci, votre signalement a bien été envoyé.');
                setTimeout(function () {
                    closeModal();
                    form.reset();
                    setStatus('');
                    submitBtn.disabled = false;
                }, 2000);
            } else {
                var errors = (result.payload && result.payload.errors) || ['Envoi impossible, merci de réessayer.'];
                setStatus(errors.join(' '));
                submitBtn.disabled = false;
            }
        }).catch(function () {
            setStatus('Envoi impossible, merci de réessayer.');
            submitBtn.disabled = false;
        });
    });
})();
