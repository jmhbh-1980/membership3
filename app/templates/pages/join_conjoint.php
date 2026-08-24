<?php
/** @var array $app, $old, $errors @var ?array $partner */
$v = fn (string $k, string $fallback = '') => htmlspecialchars((string) ($old[$k] ?? $fallback), ENT_QUOTES);
?>
<h1>Conjoint(e)</h1>
<?= $this->fetch('partials/wizard_steps.php', ['steps' => $steps]) ?>
<p>Informations de votre conjoint(e), avec qui vous vous inscrivez en couple.</p>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <div class="grid2">
        <div><label>Prénom *</label><input name="firstname" required maxlength="50" value="<?= $v('firstname', $partner['firstname'] ?? '') ?>"></div>
        <div><label>Nom *</label><input name="lastname" required maxlength="50" value="<?= $v('lastname', $partner['lastname'] ?? '') ?>"></div>
    </div>
    <div class="grid2">
        <div><label>Date de naissance *</label><input type="date" name="birthdate" required value="<?= $v('birthdate', $partner['birthdate'] ?? '') ?>"></div>
        <div><label>Sexe *</label>
            <select name="sex" required>
                <option value="">—</option>
                <option value="M" <?= ($old['sex'] ?? ($partner['sex'] ?? '')) === 'M' ? 'selected' : '' ?>>Homme</option>
                <option value="W" <?= ($old['sex'] ?? ($partner['sex'] ?? '')) === 'W' ? 'selected' : '' ?>>Femme</option>
            </select>
        </div>
    </div>
    <div class="grid2">
        <div><label>Email *</label><input type="email" name="email" required value="<?= $v('email', $partner['email'] ?? '') ?>"></div>
        <div><label>Téléphone *</label><input name="phone" required maxlength="40" value="<?= $v('phone', $partner['phone'] ?? '') ?>"></div>
    </div>
    <p class="muted">L'adresse du foyer est celle indiquée à la première étape.</p>
    <label class="choice"><input type="checkbox" name="competitor" value="1" <?= !empty($old['competitor']) || (empty($old) && !empty($partner['competitor'])) ? 'checked' : '' ?>>
        Mon/ma conjoint(e) souhaite participer aux compétitions (licence fédérale requise)</label>

    <div class="wizard-nav">
        <?php if ($backUrl !== null): ?><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline btn-small">← Précédent</a><?php endif; ?>
        <button type="submit">Continuer</button>
    </div>
</form>
