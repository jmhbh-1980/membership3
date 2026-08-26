(function () {
    'use strict';

    var button = document.getElementById('promo-code-generate');
    var input = document.getElementById('code');
    if (!button || !input) {
        return;
    }

    var ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    button.addEventListener('click', function () {
        var bytes = new Uint8Array(8);
        window.crypto.getRandomValues(bytes);
        var code = '';
        for (var i = 0; i < bytes.length; i++) {
            code += ALPHABET[bytes[i] % ALPHABET.length];
        }
        input.value = code;
        input.focus();
    });
})();
