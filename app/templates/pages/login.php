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
