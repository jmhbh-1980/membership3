<?php
/** @var App\Service\Season $season, array $errors, array $kinds, bool $isCouple, array $old @var string $backUrl */
?>
<h1>Licence — saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?></h1>
<?= $this->fetch('partials/wizard_steps.php', ['steps' => $steps]) ?>
<p>Votre licence n'a pas encore été enregistrée pour cette saison. Merci de préciser votre situation avant de poursuivre votre renouvellement.</p>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="/espace/renouvellement/licence" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <?php foreach (($isCouple ? ['self', 'partner'] : ['self']) as $who): ?>
        <?php $picked = (string) ($old[$who . '_choice'] ?? ''); ?>
        <fieldset>
            <legend>Licence<?= $isCouple ? ($who === 'self' ? ' — vous' : ' — conjoint(e)') : '' ?></legend>
            <?php foreach ($kinds as $kind => $label): ?>
                <label class="choice">
                    <input type="radio" name="<?= $who ?>_choice" value="<?= htmlspecialchars($kind, ENT_QUOTES) ?>" class="licence-choice" data-who="<?= $who ?>" <?= $picked === $kind ? 'checked' : '' ?> required>
                    <?= htmlspecialchars($label, ENT_QUOTES) ?>
                </label>
            <?php endforeach; ?>
            <label class="choice">
                <input type="radio" name="<?= $who ?>_choice" value="waive" class="licence-choice" data-who="<?= $who ?>" <?= $picked === 'waive' ? 'checked' : '' ?> required>
                <?= $who === 'self' ? 'Je possède' : 'Mon/ma conjoint(e) possède' ?> déjà une licence valide pour cette saison ailleurs — pas besoin d'une licence du club
            </label>
            <div id="<?= $who ?>-licence-reason-block" <?= $picked === 'waive' ? '' : 'hidden' ?>>
                <label for="<?= $who ?>_licence_reason">Précisez (club, type de licence…) *</label>
                <textarea id="<?= $who ?>_licence_reason" name="<?= $who ?>_licence_reason" rows="2" maxlength="500"><?= htmlspecialchars((string) ($old[$who . '_licence_reason'] ?? ''), ENT_QUOTES) ?></textarea>
                <p class="muted">Cette demande sera soumise à l'approbation du club.</p>
            </div>
        </fieldset>
    <?php endforeach; ?>

    <div class="wizard-nav">
        <a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline btn-small">← Précédent</a>
        <button type="submit">Continuer</button>
    </div>
</form>

<script>
document.querySelectorAll('.licence-choice').forEach(function (radio) {
    radio.addEventListener('change', function () {
        var block = document.getElementById(radio.dataset.who + '-licence-reason-block');
        block.hidden = radio.value !== 'waive';
    });
});
</script>
