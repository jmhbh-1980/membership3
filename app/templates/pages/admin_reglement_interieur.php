<?php /** @var string $markdown, $html, $csrf */ ?>
<h1>Règlement intérieur</h1>
<p class="muted">Affiché, avec une case à cocher obligatoire (« J'ai lu et j'accepte »), sur chaque page de
    paiement : adhésion, renouvellement, cours collectifs, crédits de jeu. Rédigé en Markdown.</p>

<form method="post" action="/admin/reglages/reglement-interieur" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <label for="reglement">Texte (Markdown)</label>
    <textarea id="reglement" name="reglement" rows="20"><?= htmlspecialchars($markdown, ENT_QUOTES) ?></textarea>

    <button type="submit">Enregistrer</button>
</form>

<h2>Aperçu</h2>
<div class="reglement-box"><?= $html !== '' ? $html : '<p class="muted">Rien à afficher pour l\'instant.</p>' ?></div>

<p><a href="/admin">← Administration</a></p>
