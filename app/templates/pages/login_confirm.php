<h1>Connexion</h1>
<p>Pour finaliser votre connexion, cliquez sur le bouton ci-dessous.</p>
<form method="post" action="/connexion/verifier" class="form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <input type="hidden" name="token" value="<?= htmlspecialchars($token, ENT_QUOTES) ?>">
    <button type="submit">Se connecter</button>
</form>
