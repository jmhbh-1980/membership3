<?php
/** @var array $bjUser, $errors */
?>
<h1>Représentant légal</h1>
<?= $this->fetch('partials/wizard_steps.php', ['steps' => $steps]) ?>
<p>Étape facultative — vous pouvez mettre à jour les coordonnées de votre représentant légal, ou simplement continuer sans rien changer.</p>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <label for="guardian_contact">Coordonnées du représentant légal (nom, email, téléphone)</label>
    <textarea id="guardian_contact" name="guardian_contact" rows="3" maxlength="255"><?= htmlspecialchars((string) ($bjUser['custom1'] ?? ''), ENT_QUOTES) ?></textarea>
    <p class="muted">255 caractères maximum. Laissez tel quel pour ne rien changer.</p>

    <button type="submit">Continuer</button>
</form>
