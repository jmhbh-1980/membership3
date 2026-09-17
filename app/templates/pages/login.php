<h1>Connexion</h1>
<?php if (!empty($error ?? null)): ?>
<p class="error"><?= htmlspecialchars($error, ENT_QUOTES) ?></p>
<?php endif; ?>

<div id="passkey-login-error" class="alert" hidden></div>
<button type="button" id="passkey-login-button" class="btn" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>" data-passkey-unsupported-hide>
    Se connecter avec une clé d'accès
</button>
<p class="muted" data-passkey-unsupported-hide>ou</p>

<p>Saisissez l'adresse email associée à votre compte adhérent. Vous recevrez un lien de connexion valable 15&nbsp;minutes.</p>
<form method="post" action="/connexion" class="form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <label for="email">Adresse email</label>
    <input type="email" id="email" name="email" required autocomplete="email" autofocus>
    <button type="submit">Recevoir mon lien de connexion</button>
</form>
