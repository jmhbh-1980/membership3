<h1>Connexion</h1>
<?php if (!empty($error ?? null)): ?>
<p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>

<p>Saisissez l'adresse email associée à votre compte adhérent. Vous recevrez un lien de connexion valable 15&nbsp;minutes.</p>
<form method="post" action="/connexion" class="form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <label for="email">Adresse email</label>
    <input type="email" id="email" name="email" required autocomplete="email" autofocus>
    <button type="submit">Recevoir mon lien de connexion</button>
</form>

<?php /* Passkeys are only ever registered by an admin (see AdminPasskeyController)
        — a member could tap this and get nothing but a confusing dead end, so it
        stays collapsed and out of the way rather than sitting next to the form
        every regular member actually needs. */ ?>
<details class="login-alt" data-passkey-unsupported-hide>
    <summary>Vous êtes administrateur ?</summary>
    <div id="passkey-login-error" class="alert" hidden></div>
    <button type="button" id="passkey-login-button" class="btn" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        Se connecter avec une clé d'accès
    </button>
</details>
