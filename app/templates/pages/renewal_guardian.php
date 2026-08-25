<?php
/** @var array $old, $errors */
$v = fn (string $k) => htmlspecialchars((string) ($old[$k] ?? ''), ENT_QUOTES);
?>
<h1>Représentant légal</h1>
<?= $this->fetch('partials/wizard_steps.php', ['steps' => $steps]) ?>
<p>Étape facultative — vous pouvez mettre à jour les coordonnées de votre représentant légal, ou simplement continuer sans rien changer.</p>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <label for="guardian_fullname">Nom et prénom du représentant légal</label>
    <input id="guardian_fullname" name="guardian_fullname" maxlength="100" value="<?= $v('fullname') ?>">
    <div class="grid2">
        <div><label for="guardian_email">Email du représentant légal</label><input type="email" id="guardian_email" name="guardian_email" value="<?= $v('email') ?>"></div>
        <div><label for="guardian_phone">Téléphone</label><input id="guardian_phone" name="guardian_phone" maxlength="40" value="<?= $v('phone') ?>"></div>
    </div>
    <p class="muted">Laissez tel quel pour ne rien changer.</p>

    <button type="submit">Continuer</button>
</form>
