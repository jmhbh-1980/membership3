<?php
/** @var array $app, $minor, $old, $errors */
$v = fn (string $k, string $fallback = '') => htmlspecialchars((string) ($old[$k] ?? $fallback), ENT_QUOTES);
?>
<h1>Représentant légal</h1>
<?= $this->fetch('partials/wizard_steps.php', ['steps' => $steps]) ?>
<p><?= htmlspecialchars(trim($minor['firstname'] . ' ' . $minor['lastname']), ENT_QUOTES) ?> est mineur·e : merci de renseigner les
coordonnées de son représentant légal. Les communications concernant cette demande lui seront adressées.</p>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <label for="guardian_fullname">Nom et prénom du représentant légal *</label>
    <input id="guardian_fullname" name="guardian_fullname" required maxlength="100" value="<?= $v('guardian_fullname', $minor['guardian_fullname'] ?? '') ?>">
    <div class="grid2">
        <div><label for="guardian_email">Email du représentant légal *</label><input type="email" id="guardian_email" name="guardian_email" required value="<?= $v('guardian_email', $minor['guardian_email'] ?? '') ?>"></div>
        <div><label for="guardian_phone">Téléphone *</label><input id="guardian_phone" name="guardian_phone" required maxlength="40" value="<?= $v('guardian_phone', $minor['guardian_phone'] ?? '') ?>"></div>
    </div>

    <div class="wizard-nav">
        <?php if ($backUrl !== null): ?><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline btn-small">← Précédent</a><?php endif; ?>
        <button type="submit">Continuer</button>
    </div>
</form>
