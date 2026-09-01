<?php /** @var ?string $imageUrl, $csrf; @var array $errors */ ?>
<h1>Règles chaussures</h1>
<p class="muted">Image affichée, avec une case à cocher obligatoire (« J'ai pris connaissance »), sur chaque page de
    paiement : adhésion, renouvellement, cours collectifs, crédits de jeu.</p>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($imageUrl !== null): ?>
    <img src="<?= htmlspecialchars($imageUrl, ENT_QUOTES) ?>" alt="Règles chaussures" class="reglement-image-preview">
<?php else: ?>
    <p class="muted">Aucune image envoyée pour l'instant.</p>
<?php endif; ?>

<form method="post" action="/admin/reglages/chaussures" class="form form-wide" enctype="multipart/form-data">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <label for="image"><?= $imageUrl !== null ? 'Remplacer l\'image' : 'Envoyer une image' ?></label>
    <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp" required>

    <button type="submit">Enregistrer</button>
</form>

<?php if ($imageUrl !== null): ?>
    <form method="post" action="/admin/reglages/chaussures/supprimer" class="form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <button type="submit" class="btn-danger">Supprimer l'image</button>
    </form>
<?php endif; ?>

<p><a href="/admin">← Administration</a></p>
