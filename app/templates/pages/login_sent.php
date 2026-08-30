<h1>Vérifiez votre boîte mail</h1>
<p>Si un compte adhérent existe pour <strong><?= htmlspecialchars($email, ENT_QUOTES) ?></strong>, un lien de connexion vient d'être envoyé. Il est valable 15&nbsp;minutes.</p>
<p class="muted">Pensez à vérifier votre dossier spam. <a href="/connexion">Renvoyer un lien</a></p>

<?php if (!empty($codeError ?? null)): ?>
    <p class="error"><?= htmlspecialchars($codeError, ENT_QUOTES) ?></p>
<?php endif; ?>
<form method="post" action="/connexion/code" class="form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <input type="hidden" name="email" value="<?= htmlspecialchars($email, ENT_QUOTES) ?>">
    <label for="code">Ou saisissez le code reçu par email</label>
    <input type="text" id="code" name="code" inputmode="numeric" pattern="[0-9]{6}"
           maxlength="6" autocomplete="one-time-code" required autofocus>
    <button type="submit">Valider le code</button>
</form>
