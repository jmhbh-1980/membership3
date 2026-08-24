<?php
/** @var array $app, $people, $documents, $missing, $errors */
?>
<h1>Documents</h1>
<?= $this->fetch('partials/wizard_steps.php', ['steps' => $steps]) ?>
<p>Photo de profil pour chaque adhérent<?= $app['residence'] === 'garennois' ? ' et justificatif de domicile (tarif Garennois)' : '' ?>.</p>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" enctype="multipart/form-data" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <?php foreach ($people as $position => $person): ?>
        <fieldset>
            <legend>Photo de <?= htmlspecialchars($person['firstname'] . ' ' . $person['lastname'], ENT_QUOTES) ?></legend>
            <?php if (isset($documents[$position . ':photo'])): ?>
                <p>✔ Photo reçue : <?= htmlspecialchars($documents[$position . ':photo']['original_name'], ENT_QUOTES) ?>
                    <span class="muted">(envoyer un nouveau fichier pour remplacer)</span></p>
            <?php endif; ?>
            <input type="file" name="photo_<?= (int) $position ?>" accept="image/jpeg,image/png,image/webp" class="photo-input">
            <img class="photo-preview" hidden alt="Aperçu de la photo">
        </fieldset>
    <?php endforeach; ?>

    <?php if ($app['residence'] === 'garennois'): ?>
        <fieldset>
            <legend>Justificatif de domicile (moins de 3 mois)</legend>
            <?php if (isset($documents['1:justificatif'])): ?>
                <p>✔ Justificatif reçu : <?= htmlspecialchars($documents['1:justificatif']['original_name'], ENT_QUOTES) ?>
                    <span class="muted">(envoyer un nouveau fichier pour remplacer)</span></p>
            <?php endif; ?>
            <input type="file" name="justificatif_1" accept="application/pdf,image/jpeg,image/png">
        </fieldset>
    <?php endif; ?>

    <?php if ($missing !== []): ?>
        <p class="muted">Encore attendu : <?= htmlspecialchars(implode(' · ', $missing), ENT_QUOTES) ?></p>
    <?php endif; ?>

    <div class="wizard-nav">
        <?php if ($backUrl !== null): ?><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline btn-small">← Précédent</a><?php endif; ?>
        <button type="submit">Enregistrer et continuer</button>
    </div>
</form>

<script>
(function () {
    document.querySelectorAll('.photo-input').forEach(function (input) {
        var preview = input.nextElementSibling;
        input.addEventListener('change', function () {
            var file = input.files[0];
            if (!file) { preview.hidden = true; return; }
            preview.src = URL.createObjectURL(file);
            preview.hidden = false;
        });
    });
})();
</script>
