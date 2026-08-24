<?php
/**
 * @var array $app, $people, $subscriptions (available), $old, $errors
 * @var bool $isJeune
 */
$applicant = $people[1];
$selected = (string) ($old['subscription'] ?? $app['subscription_type']);
$isSummerPack = (bool) $app['summer_pack'];
?>
<h1>Choix de l'abonnement</h1>
<?= $this->fetch('partials/wizard_steps.php', ['steps' => $steps]) ?>
<p><?= htmlspecialchars($applicant['firstname'] . ' ' . $applicant['lastname'], ENT_QUOTES) ?>,
tarif <?= $app['residence'] === 'garennois' ? 'Garennois' : 'Hors commune' ?>, saison <?= (int) $app['season_start_year'] ?>-<?= (int) $app['season_start_year'] + 1 ?>.</p>

<?php if ($isSummerPack): ?>
    <p class="muted">Saison <?= (int) $app['season_start_year'] ?>-<?= (int) $app['season_start_year'] + 1 ?> déjà bien avancée : Pack été à tarif unique — 50 € de cotisation, en formule Heures Pleines (hors cours collectifs). Vous pourrez adhérer au tarif plein à la rentrée.</p>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <?php if ($isSummerPack): ?>
        <fieldset>
            <legend>Abonnement</legend>
            <p><strong>Heures Pleines</strong> — 50 € <span class="muted">(hors licence — Pack été, formule fixe)</span></p>
        </fieldset>
    <?php else: ?>
        <fieldset>
            <legend>Abonnement</legend>
            <?php foreach ($subscriptions as $key => $s): ?>
                <?php
                    // Falls back to the Garennois grid when the subscription isn't priced for
                    // the applicant's own residence — only reachable for Midi under an
                    // admin-granted residency override (see PricingService::quote()'s
                    // matching Garennois-price substitution for the same case).
                    $price = ($s['individual'][$app['residence']] ?? $s['individual']['garennois'])['premiere'];
                    $coupleNote = !empty($s['couple_available'])
                        ? number_format($s['couple'][$app['residence']]['premiere'], 0, ',', ' ') . ' € pour 2, hors licences'
                        : '';
                ?>
                <label class="choice">
                    <input type="radio" name="subscription" value="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                           data-couple-available="<?= !empty($s['couple_available']) ? 1 : 0 ?>"
                           data-couple-note="<?= htmlspecialchars($coupleNote, ENT_QUOTES) ?>"
                           <?= $selected === $key ? 'checked' : '' ?> required>
                    <?= htmlspecialchars($s['label'], ENT_QUOTES) ?> — <strong><?= number_format($price, 0, ',', ' ') ?> €</strong>
                    <span class="muted">(hors licence)</span>
                </label>
            <?php endforeach; ?>
        </fieldset>
    <?php endif; ?>

    <?php if (!$isJeune): ?>
    <?php if (!$isSummerPack): ?>
    <fieldset id="competitor-block">
        <legend>Compétition</legend>
        <label class="choice"><input type="checkbox" name="competitor" value="1" <?= !empty($old['competitor']) ? 'checked' : '' ?>>
            Je souhaite participer aux compétitions (licence fédérale requise)</label>
    </fieldset>
    <?php endif; ?>

    <?php if (!$isSummerPack): ?>
    <fieldset id="couple-block">
        <legend>Inscription en couple</legend>
        <label class="choice" id="couple-toggle-label" hidden>
            <input type="checkbox" name="is_couple" value="1" <?= !empty($old['is_couple']) ? 'checked' : '' ?>>
            Je m'inscris en couple avec mon/ma conjoint(e) — un seul règlement pour les deux
            <span class="muted" id="couple-price-note"></span>
        </label>
        <p class="muted" id="couple-next-note" hidden>Les informations de votre conjoint(e) vous seront demandées à l'étape suivante.</p>
    </fieldset>
    <?php endif; ?>

    <?php if (!$isSummerPack): ?>
    <fieldset id="lessons-block">
        <legend>Cours collectifs (120 € / an / personne)</legend>
        <label class="choice"><input type="checkbox" name="lessons_1" value="1" <?= !empty($old['lessons_1']) || (empty($old) && (int) $app['lessons_count'] >= 1) ? 'checked' : '' ?>>
            Je souhaite m'inscrire aux cours collectifs</label>
        <label class="choice" id="lessons2-label" hidden><input type="checkbox" name="lessons_2" value="1" <?= !empty($old['lessons_2']) || (empty($old) && (int) $app['lessons_count'] === 2) ? 'checked' : '' ?>>
            Le/la conjoint(e) souhaite s'inscrire aux cours collectifs</label>
    </fieldset>
    <?php endif; ?>

    <?php else: ?>
        <p class="muted">L'abonnement Jeune inclut le mini-squash (4-7 ans) ou l'école des jeunes (8-18 ans) ainsi que la licence jeune.</p>
    <?php endif; ?>

    <div class="wizard-nav">
        <?php if ($backUrl !== null): ?><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline btn-small">← Précédent</a><?php endif; ?>
        <button type="submit">Continuer</button>
    </div>
</form>

<script>
(function () {
    var radios = document.querySelectorAll('input[name="subscription"]');
    var coupleToggleLabel = document.getElementById('couple-toggle-label');
    var couplePriceNote = document.getElementById('couple-price-note');
    var coupleCheckbox = document.querySelector('input[name="is_couple"]');
    var coupleNextNote = document.getElementById('couple-next-note');
    var lessons2 = document.getElementById('lessons2-label');

    function refresh() {
        var checked = document.querySelector('input[name="subscription"]:checked');
        var coupleAvailable = !!checked && checked.dataset.coupleAvailable === '1';
        if (coupleToggleLabel) { coupleToggleLabel.hidden = !coupleAvailable; }
        if (!coupleAvailable && coupleCheckbox) { coupleCheckbox.checked = false; }
        if (couplePriceNote) { couplePriceNote.textContent = coupleAvailable ? '(' + checked.dataset.coupleNote + ')' : ''; }
        var isCouple = coupleAvailable && coupleCheckbox && coupleCheckbox.checked;
        if (coupleNextNote) { coupleNextNote.hidden = !isCouple; }
        if (lessons2) { lessons2.hidden = !isCouple; }
    }
    radios.forEach(function (r) { r.addEventListener('change', refresh); });
    if (coupleCheckbox) { coupleCheckbox.addEventListener('change', refresh); }
    refresh();
})();
</script>
