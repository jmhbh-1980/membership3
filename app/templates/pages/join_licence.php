<?php
/** @var array $app, $people, $errors */
$isCouple = (bool) $app['is_couple'];
$isSummerPack = (bool) $app['summer_pack'];
?>
<h1>Licence</h1>
<?= $this->fetch('partials/wizard_steps.php', ['steps' => $steps]) ?>
<?php if ($isSummerPack): ?>
    <p>Une licence est requise, facturée séparément de la cotisation. Le Pack été inclut une licence été à tarif réduit (5 €, découverte). Si vous possédez déjà une licence valide pour cette saison (prise dans un autre club), vous pouvez la retirer du panier.</p>
<?php else: ?>
    <p>Une licence est requise, facturée séparément de l'abonnement (plein tarif, non concerné par une éventuelle réduction sur l'abonnement). Si vous en possédez déjà une valide pour cette saison (prise dans un autre club), vous pouvez la retirer du panier.</p>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <?php foreach (($isCouple ? [1, 2] : [1]) as $position): ?>
        <?php $person = $people[$position] ?? null; ?>
        <?php $choice = !empty($person['licence_removed']) ? 'exception' : 'keep'; ?>
        <fieldset>
            <legend>Licence<?= $isCouple ? ' — ' . htmlspecialchars($person['firstname'] ?? ($position === 1 ? 'vous' : 'conjoint(e)'), ENT_QUOTES) : '' ?></legend>
            <label class="choice">
                <input type="radio" name="licence_<?= $position ?>" value="keep" class="licence-choice" data-position="<?= $position ?>" <?= $choice === 'keep' ? 'checked' : '' ?>>
                <?= htmlspecialchars($person['licenceInfo']['label'], ENT_QUOTES) ?>
                — <strong><?= number_format($person['licenceInfo']['price'], 0, ',', ' ') ?> €</strong>
            </label>
            <label class="choice">
                <input type="radio" name="licence_<?= $position ?>" value="exception" class="licence-choice" data-position="<?= $position ?>" <?= $choice === 'exception' ? 'checked' : '' ?>>
                <?= $position === 1 ? 'Je possède' : 'Mon/ma conjoint(e) possède' ?> déjà une licence valide pour cette saison (prise dans un autre club) — retirer la licence du panier
            </label>
            <div id="licence-reason-block-<?= $position ?>" <?= $choice === 'exception' ? '' : 'hidden' ?>>
                <label for="licence_reason_<?= $position ?>">Précisez (club, type de licence…) *</label>
                <textarea id="licence_reason_<?= $position ?>" name="licence_reason_<?= $position ?>" rows="2" maxlength="500"><?= htmlspecialchars((string) ($person['licence_removal_reason'] ?? ''), ENT_QUOTES) ?></textarea>
            </div>
        </fieldset>
    <?php endforeach; ?>

    <div class="wizard-nav">
        <?php if ($backUrl !== null): ?><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline btn-small">← Précédent</a><?php endif; ?>
        <button type="submit">Continuer</button>
    </div>
</form>

<script>
(function () {
    document.querySelectorAll('.licence-choice').forEach(function (radio) {
        radio.addEventListener('change', function () {
            var block = document.getElementById('licence-reason-block-' + radio.dataset.position);
            block.hidden = radio.value !== 'exception';
        });
    });
})();
</script>
