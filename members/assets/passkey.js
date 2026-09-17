/**
 * Passkey (WebAuthn) glue for admin login and enrollment.
 *
 * Both the create() and get() ceremonies exchange binary data (challenges,
 * credential ids, signatures) as base64url strings over JSON, since that's
 * what the server side (lbuchs/webauthn) already speaks — see
 * WebauthnService. The browser's own `credential.id` is already a base64url
 * string per spec, everything else (rawId, response.*) is an ArrayBuffer
 * that needs encoding by hand here.
 */
(function () {
    'use strict';

    if (!window.PublicKeyCredential || !navigator.credentials) {
        // Progressive enhancement: hide the buttons rather than offer
        // something that can only fail on an old browser, and reveal
        // whatever explanatory note takes their place instead.
        document.querySelectorAll('[data-passkey-unsupported-hide]').forEach(function (el) {
            el.hidden = true;
        });
        document.querySelectorAll('[data-passkey-unsupported-show]').forEach(function (el) {
            el.hidden = false;
        });
        return;
    }

    function base64UrlToBuffer(base64url) {
        var padded = base64url.replace(/-/g, '+').replace(/_/g, '/');
        var padding = padded.length % 4 === 0 ? '' : '='.repeat(4 - (padded.length % 4));
        var binary = window.atob(padded + padding);
        var bytes = new Uint8Array(binary.length);
        for (var i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }
        return bytes.buffer;
    }

    function bufferToBase64Url(buffer) {
        var bytes = new Uint8Array(buffer);
        var binary = '';
        for (var i = 0; i < bytes.length; i++) {
            binary += String.fromCharCode(bytes[i]);
        }
        return window.btoa(binary).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function postJson(url, body) {
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        }).then(function (response) {
            return response.json().then(function (data) {
                if (!response.ok) {
                    throw new Error(data.error || 'Une erreur est survenue.');
                }
                return data;
            });
        });
    }

    function decodeCreateOptions(options) {
        options.challenge = base64UrlToBuffer(options.challenge);
        options.user.id = base64UrlToBuffer(options.user.id);
        (options.excludeCredentials || []).forEach(function (cred) {
            cred.id = base64UrlToBuffer(cred.id);
        });
        return options;
    }

    function decodeGetOptions(options) {
        options.challenge = base64UrlToBuffer(options.challenge);
        (options.allowCredentials || []).forEach(function (cred) {
            cred.id = base64UrlToBuffer(cred.id);
        });
        return options;
    }

    function showError(el, message) {
        if (el) {
            el.textContent = message;
            el.hidden = false;
        }
    }

    // --- Login (usernameless — discoverable credential) ---
    var loginButton = document.getElementById('passkey-login-button');
    if (loginButton) {
        var loginError = document.getElementById('passkey-login-error');
        var loginCsrf = loginButton.getAttribute('data-csrf');

        loginButton.addEventListener('click', function () {
            if (loginError) {
                loginError.hidden = true;
            }
            postJson('/connexion/passkey/options', { csrf: loginCsrf })
                .then(function (options) {
                    return navigator.credentials.get({ publicKey: decodeGetOptions(options.publicKey) });
                })
                .then(function (credential) {
                    return postJson('/connexion/passkey/verifier', {
                        csrf: loginCsrf,
                        id: credential.id,
                        clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                        authenticatorData: bufferToBase64Url(credential.response.authenticatorData),
                        signature: bufferToBase64Url(credential.response.signature),
                    });
                })
                .then(function (data) {
                    window.location.href = data.redirect;
                })
                .catch(function (error) {
                    showError(loginError, error.name === 'NotAllowedError'
                        ? 'Connexion annulée ou aucune clé d\'accès trouvée pour ce site.'
                        : error.message);
                });
        });
    }

    // --- Enrollment (admin settings) ---
    var registerButton = document.getElementById('passkey-register-button');
    if (registerButton) {
        var registerError = document.getElementById('passkey-register-error');
        var labelInput = document.getElementById('passkey-label');
        var registerCsrf = registerButton.getAttribute('data-csrf');

        registerButton.addEventListener('click', function () {
            if (registerError) {
                registerError.hidden = true;
            }
            postJson('/admin/reglages/passkeys/options', { csrf: registerCsrf })
                .then(function (options) {
                    return navigator.credentials.create({ publicKey: decodeCreateOptions(options.publicKey) });
                })
                .then(function (credential) {
                    return postJson('/admin/reglages/passkeys', {
                        csrf: registerCsrf,
                        label: (labelInput && labelInput.value.trim()) || 'Clé d\'accès',
                        clientDataJSON: bufferToBase64Url(credential.response.clientDataJSON),
                        attestationObject: bufferToBase64Url(credential.response.attestationObject),
                    });
                })
                .then(function () {
                    window.location.reload();
                })
                .catch(function (error) {
                    showError(registerError, error.name === 'NotAllowedError'
                        ? 'Enregistrement annulé.'
                        : error.message);
                });
        });
    }
})();
