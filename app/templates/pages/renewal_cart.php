<?php
/**
 * @var App\Service\Season $season
 * @var array $intent, $subscription, $errors
 * @var App\Service\Quote $quote
 */
$isJeune = $subscription['audience'] === 'jeune';
$isCouple = (bool) $intent['isCouple'];
$isLateSettlement = !empty($intent['lateSettlement']);
?>
<h1>Paiement du renouvellement — saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?></h1>
<?= $this->fetch('partials/wizard_steps.php', ['steps' => $steps]) ?>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($isLateSettlement): ?>
    <p class="muted">Saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?> déjà bien avancée : Pack été à tarif unique — 50 € de cotisation + 5 € de licence été (hors cours collectifs).</p>
<?php endif; ?>

<?php if (!$isJeune): ?>
<h2>Vos options</h2>
<form method="post" action="/espace/renouvellement/options" class="form form-wide" id="options-form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <fieldset>
        <legend>Licence<?= $isCouple ? ' — vous' : '' ?></legend>
        <label class="choice">
            <input type="checkbox" name="remove_licence" value="1" id="remove-licence" <?= !empty($intent['licenceRemoved']) ? 'checked' : '' ?>>
            Je possède déjà une licence valide pour cette saison — retirer la licence du panier
        </label>
        <div id="licence-reason-block" <?= !empty($intent['licenceRemoved']) ? '' : 'hidden' ?>>
            <label for="licence_reason">Précisez (club, type de licence…) *</label>
            <textarea id="licence_reason" name="licence_reason" rows="2" maxlength="500"><?= htmlspecialchars((string) ($intent['licenceRemovalReason'] ?? ''), ENT_QUOTES) ?></textarea>
        </div>
    </fieldset>

    <?php if ($isCouple): ?>
    <fieldset>
        <legend>Licence — conjoint(e)</legend>
        <label class="choice">
            <input type="checkbox" name="partner_remove_licence" value="1" id="partner-remove-licence" <?= !empty($intent['partnerLicenceRemoved']) ? 'checked' : '' ?>>
            Mon/ma conjoint(e) possède déjà une licence valide pour cette saison — retirer sa licence du panier
        </label>
        <div id="partner-licence-reason-block" <?= !empty($intent['partnerLicenceRemoved']) ? '' : 'hidden' ?>>
            <label for="partner_licence_reason">Précisez (club, type de licence…) *</label>
            <textarea id="partner_licence_reason" name="partner_licence_reason" rows="2" maxlength="500"><?= htmlspecialchars((string) ($intent['partnerLicenceRemovalReason'] ?? ''), ENT_QUOTES) ?></textarea>
        </div>
    </fieldset>
    <?php endif; ?>

    <?php if (!$isLateSettlement): ?>
    <fieldset>
        <legend>Cours collectifs (120 € / an / personne)</legend>
        <label class="choice"><input type="checkbox" name="lessons_1" value="1" <?= (int) $intent['lessons'] >= 1 ? 'checked' : '' ?>>
            Je participe aux cours collectifs</label>
        <?php if ($isCouple): ?>
            <label class="choice"><input type="checkbox" name="lessons_2" value="1" <?= (int) $intent['lessons'] === 2 ? 'checked' : '' ?>>
                Mon/ma conjoint(e) participe aux cours collectifs</label>
        <?php endif; ?>
    </fieldset>
    <?php endif; ?>

    <noscript><button type="submit" class="btn-small">Mettre à jour le panier</button></noscript>
</form>
<?php endif; ?>

<h2>Votre panier</h2>
<p id="cart-updating-notice" class="muted" hidden>Mise à jour du panier…</p>
<table class="details">
    <?php foreach ($quote->lines as $line): ?>
        <tr><th><?= htmlspecialchars($line->label, ENT_QUOTES) ?></th><td><?= number_format($line->amount, 2, ',', ' ') ?> €</td></tr>
    <?php endforeach; ?>
    <tr><th><strong>Total à régler</strong></th><td><strong><?= number_format($quote->total(), 2, ',', ' ') ?> €</strong></td></tr>
</table>

<form method="post" action="/espace/renouvellement/checkout" class="form" id="checkout-form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <button type="submit" id="pay-button">Payer <?= number_format($quote->total(), 2, ',', ' ') ?> € en ligne</button>
</form>

<script>
var optionsForm = document.getElementById('options-form');
var payButton = document.getElementById('pay-button');
var updatingNotice = document.getElementById('cart-updating-notice');

function lockCartWhileUpdating() {
    // The cart total shown on screen is now stale until the options POST
    // round-trips and the page reloads with the recomputed amount — disable
    // "Payer" so a fast click can't check out against the outdated total.
    // Note: never disable optionsForm's own fields here — a disabled field
    // is dropped from its own form submission (including the CSRF token),
    // so that would corrupt the very save this is meant to protect.
    payButton.disabled = true;
}

// Steady-state (not mid-update) validity: "Payer" must stay disabled for as
// long as any removal is checked but its mandatory reason is still empty —
// not just while a request is in flight.
function refreshPayButtonValidity() {
    var pairs = [['remove-licence', 'licence_reason'], ['partner-remove-licence', 'partner_licence_reason']];
    var missingReason = pairs.some(function (pair) {
        var box = document.getElementById(pair[0]);
        var reasonField = document.getElementById(pair[1]);
        return box && box.checked && reasonField && reasonField.value.trim() === '';
    });
    payButton.disabled = missingReason;
    updatingNotice.textContent = 'Merci de préciser le motif du retrait de la licence pour continuer.';
    updatingNotice.hidden = !missingReason;
}

if (optionsForm) {
    optionsForm.addEventListener('change', function (e) {
        if (e.target.id === 'remove-licence' || e.target.id === 'partner-remove-licence') {
            var reasonBlockId = e.target.id === 'remove-licence' ? 'licence-reason-block' : 'partner-licence-reason-block';
            document.getElementById(reasonBlockId).hidden = !e.target.checked;
            if (!e.target.checked) {
                // Re-adding the licence applies at once.
                lockCartWhileUpdating();
                optionsForm.submit();
            } else {
                refreshPayButtonValidity(); // reason not filled in yet — block Payer
            }
            return;
        }
        if (e.target.id === 'licence_reason' || e.target.id === 'partner_licence_reason') {
            var boxId = e.target.id === 'licence_reason' ? 'remove-licence' : 'partner-remove-licence';
            var box = document.getElementById(boxId);
            // Textareas fire 'change' on blur when the value changed — save
            // automatically once a reason has been typed, no confirm button needed.
            if (box.checked && e.target.value.trim() !== '') {
                lockCartWhileUpdating();
                optionsForm.submit();
            } else {
                refreshPayButtonValidity();
            }
            return;
        }
        lockCartWhileUpdating();
        optionsForm.submit();
    });
    optionsForm.addEventListener('input', function (e) {
        if (e.target.id === 'licence_reason' || e.target.id === 'partner_licence_reason') refreshPayButtonValidity(); // live feedback as they type
    });
    refreshPayButtonValidity(); // initial state on load
}

document.getElementById('checkout-form').addEventListener('submit', function () {
    payButton.disabled = true; // prevent double-submit / double charge on its own click
});
</script>
