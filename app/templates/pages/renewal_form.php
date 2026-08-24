<?php
/** @var array $context, $old, $errors */
$season = $context['season'];
$selected = (string) ($old['subscription'] ?? ($context['currentSubscriptionType'] ?? ''));
$hasPartnerOnFile = (int) $context['currentPartnerBjUserId'] > 0;
?>
<h1>Renouvellement — saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?></h1>
<?= $this->fetch('partials/wizard_steps.php', ['steps' => $steps]) ?>
<p>Tarif <?= $context['residence'] === 'garennois' ? 'Garennois' : 'Hors commune' ?> (renouvellement).
<?php if ($context['currentLabel'] !== ''): ?>
    Votre abonnement actuel : <strong><?= htmlspecialchars($context['currentLabel'], ENT_QUOTES) ?></strong>.
<?php endif; ?></p>
<p class="muted">Conserver le même abonnement (et statut couple) vous mène directement au paiement. Un changement d'abonnement est soumis à l'accord du club.</p>

<?php if ($context['lateSettlement']): ?>
    <p class="muted">Saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?> déjà bien avancée : Pack été à tarif unique — 50 € de cotisation + 5 € de licence été (hors cours collectifs).</p>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <fieldset>
        <legend>Abonnement</legend>
        <?php foreach ($context['subscriptions'] as $key => $s): ?>
            <?php
                // Falls back to the Garennois grid when the subscription isn't priced for
                // the member's own residence — only reachable for Midi under the
                // auto-grandfather override (see PricingService::quote()'s matching
                // Garennois-price substitution for the same case).
                $price = ($s['individual'][$context['residence']] ?? $s['individual']['garennois'])['renouvellement'];
            ?>
            <label class="choice">
                <input type="radio" name="subscription" value="<?= htmlspecialchars($key, ENT_QUOTES) ?>"
                       data-couple-available="<?= !empty($s['couple_available']) ? 1 : 0 ?>"
                       <?= $selected === $key ? 'checked' : '' ?> required>
                <?= htmlspecialchars($s['label'], ENT_QUOTES) ?>
                <?php if ($context['lateSettlement']): ?>
                    <span class="muted">(Pack été — tarif unique, voir ci-dessus)</span>
                <?php else: ?>
                    — <strong><?= number_format($price, 0, ',', ' ') ?> €</strong>
                    <span class="muted">(hors licence)</span>
                <?php endif; ?>
                <?= $key === $context['currentSubscriptionType'] ? ' <em>(abonnement actuel)</em>' : '' ?>
            </label>
        <?php endforeach; ?>
    </fieldset>

    <?php if (!$context['lateSettlement']): ?>
    <fieldset id="competitor-block">
        <legend>Compétition</legend>
        <label class="choice"><input type="checkbox" name="competitor" value="1" <?= !empty($old['competitor']) || (empty($old) && !empty($context['currentIsCompetitor'])) ? 'checked' : '' ?>>
            Je souhaite participer aux compétitions (licence fédérale requise)</label>
    </fieldset>
    <?php endif; ?>

    <?php if (!$context['lateSettlement']): ?>
    <fieldset id="couple-block">
        <legend>Inscription en couple</legend>
        <label class="choice" id="couple-toggle-label" hidden>
            <input type="checkbox" name="is_couple" value="1" <?= !empty($old['is_couple']) || (empty($old) && !empty($context['currentIsCouple'])) ? 'checked' : '' ?>>
            Je renouvelle en couple — un seul règlement pour les deux
        </label>
    </fieldset>
    <?php endif; ?>

    <?php if (!$context['lateSettlement']): ?>
    <fieldset>
        <legend>Cours collectifs (120 € / an / personne)</legend>
        <label class="choice"><input type="checkbox" name="lessons_1" value="1" <?= !empty($old['lessons_1']) ? 'checked' : '' ?>>
            Je souhaite m'inscrire aux cours collectifs</label>
        <label class="choice" id="lessons2-label" hidden><input type="checkbox" name="lessons_2" value="1" <?= !empty($old['lessons_2']) ? 'checked' : '' ?>>
            Mon/ma conjoint(e) souhaite s'inscrire aux cours collectifs</label>
    </fieldset>
    <?php endif; ?>

    <fieldset id="partner-block" hidden>
        <legend>Conjoint(e)</legend>
        <?php if ($hasPartnerOnFile): ?>
            <p>✔ Votre conjoint(e) est déjà associé(e) à votre abonnement.</p>
        <?php else: ?>
            <label for="partner_email">Email du compte adhérent de votre conjoint(e) *</label>
            <input type="email" id="partner_email" name="partner_email" value="<?= htmlspecialchars((string) ($old['partner_email'] ?? ''), ENT_QUOTES) ?>">
        <?php endif; ?>
        <?php if (!$context['lateSettlement']): ?>
        <label class="choice"><input type="checkbox" name="partner_competitor" value="1" <?= !empty($old['partner_competitor']) ? 'checked' : '' ?>>
            Mon/ma conjoint(e) souhaite participer aux compétitions (licence fédérale requise)</label>
        <?php endif; ?>
    </fieldset>

    <button type="submit">Continuer</button>
</form>

<script>
(function () {
    var radios = document.querySelectorAll('input[name="subscription"]');
    var coupleToggleLabel = document.getElementById('couple-toggle-label');
    var coupleCheckbox = document.querySelector('input[name="is_couple"]');
    function refresh() {
        var checked = document.querySelector('input[name="subscription"]:checked');
        var coupleAvailable = !!checked && checked.dataset.coupleAvailable === '1';
        if (coupleToggleLabel) { coupleToggleLabel.hidden = !coupleAvailable; }
        if (!coupleAvailable && coupleCheckbox) { coupleCheckbox.checked = false; }
        var isCouple = coupleAvailable && coupleCheckbox && coupleCheckbox.checked;
        document.getElementById('partner-block').hidden = !isCouple;
        var lessons2 = document.getElementById('lessons2-label');
        if (lessons2) { lessons2.hidden = !isCouple; }
    }
    radios.forEach(function (r) { r.addEventListener('change', refresh); });
    if (coupleCheckbox) { coupleCheckbox.addEventListener('change', refresh); }
    refresh();
})();
</script>
