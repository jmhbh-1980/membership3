<?php /** @var string $signature, $csrf */ ?>
<h1>Signature email</h1>
<p class="muted">Ajoutée automatiquement en bas de chaque email envoyé par l'application (lien de connexion,
    factures, virements, réductions étudiant…). Texte brut : les retours à la ligne sont conservés,
    pas de mise en forme HTML.</p>

<form method="post" action="/admin/reglages/signature-email" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <label for="signature">Signature</label>
    <textarea id="signature" name="signature" rows="5" maxlength="1000"><?= htmlspecialchars($signature, ENT_QUOTES) ?></textarea>

    <button type="submit">Enregistrer</button>
</form>

<p><a href="/admin">← Administration</a></p>
