<?php
/**
 * @var array $app, $people, $subscription, $errors
 * @var App\Service\Quote $quote
 * @var string $reglementHtml
 * @var ?string $shoesPolicyImageUrl
 */
$isJeune = $subscription['audience'] === 'jeune';
$isCouple = (bool) $app['is_couple'];
$isSummerPack = (bool) $app['summer_pack'];
$isStudentRequest = !$isCouple && (bool) $app['student_discount_requested'];
$awaitingApproval = $app['promo_code'] !== '' || ($isStudentRequest && !$app['student_discount_approved']);
?>
<h1>Paiement de l'adhésion</h1>
<p>Demande validée par le club — dernière étape : le règlement en ligne (paiement sécurisé SumUp).</p>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<h2>Vos options</h2>
<form method="post" action="/paiement/<?= htmlspecialchars($app['token'], ENT_QUOTES) ?>/options" class="form form-wide" id="options-form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <?php if (!$isJeune && !$isSummerPack): ?>
    <fieldset>
        <legend>Cours collectifs (120 € / an / personne)</legend>
        <label class="choice"><input type="checkbox" name="lessons_1" value="1" <?= (int) $app['lessons_count'] >= 1 ? 'checked' : '' ?>>
            <?= htmlspecialchars($people[1]['firstname'], ENT_QUOTES) ?> participe aux cours collectifs</label>
        <?php if ($isCouple): ?>
            <label class="choice"><input type="checkbox" name="lessons_2" value="1" <?= (int) $app['lessons_count'] === 2 ? 'checked' : '' ?>>
                <?= htmlspecialchars($people[2]['firstname'] ?? 'Conjoint(e)', ENT_QUOTES) ?> participe aux cours collectifs</label>
        <?php endif; ?>
    </fieldset>
    <?php endif; ?>

    <?php if ($isStudentRequest): ?>
        <p class="muted"><?= $app['student_discount_approved']
            ? '✔ Votre statut étudiant a été validé — la réduction de 50 % est appliquée.'
            : 'Votre certificat de scolarité doit être validé par un administrateur avant le paiement.' ?></p>
    <?php else: ?>
    <fieldset>
        <legend>Code promo</legend>
        <label for="promo_code">Vous avez un code promo ?</label>
        <input type="text" id="promo_code" name="promo_code" maxlength="32" placeholder="Code promo" value="<?= htmlspecialchars($app['promo_code'], ENT_QUOTES) ?>">
    </fieldset>
    <?php endif; ?>

    <noscript><button type="submit" class="btn-small">Mettre à jour le panier</button></noscript>
</form>

<h2>Votre panier</h2>
<table class="details">
    <?php foreach ($quote->lines as $line): ?>
        <tr>
            <th><?= htmlspecialchars($line->label, ENT_QUOTES) ?></th>
            <td><?= number_format($line->amount, 2, ',', ' ') ?> €
                <?php if ($line->amount < $line->baseAmount): ?>
                    <span class="muted">(prorata <?= $quote->prorataMonths ?>/12 déduit)</span>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
    <tr><th><strong>Total à régler</strong></th><td><strong><?= number_format($quote->total(), 2, ',', ' ') ?> €</strong></td></tr>
</table>

<?php if ($awaitingApproval): ?>
    <p class="muted">Ce code doit être validé par un administrateur avant le paiement — vous recevrez un email avec le lien de paiement dès que ce sera fait.</p>
<?php endif; ?>
<form method="post" action="/paiement/<?= htmlspecialchars($app['token'], ENT_QUOTES) ?>/checkout" class="form" id="checkout-form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <?= $this->fetch('partials/reglement_acceptance.php', ['reglementHtml' => $reglementHtml]) ?>
    <?= $this->fetch('partials/shoes_policy_acceptance.php', ['shoesPolicyImageUrl' => $shoesPolicyImageUrl]) ?>

    <button type="submit" name="payment_method" value="online" id="pay-button"><?= $awaitingApproval ? 'Envoyer pour validation' : 'Payer ' . number_format($quote->total(), 2, ',', ' ') . ' € en ligne' ?></button>

    <?php if ($app['promo_code'] === '' && !$isStudentRequest): ?>
    <details class="payment-alt">
        <summary>Vous préférez payer par virement bancaire ?</summary>
        <p class="muted">Traitement plus long : votre demande n'est finalisée qu'une fois le virement constaté par le club.</p>
        <button type="submit" name="payment_method" value="bank_transfer">Payer par virement</button>
    </details>
    <?php endif; ?>
</form>

<script>
var optionsForm = document.getElementById('options-form');
var checkoutForm = document.getElementById('checkout-form');

if (optionsForm) {
    optionsForm.addEventListener('change', function () {
        // The cart total shown on screen is now stale until the options POST
        // round-trips and the page reloads with the recomputed amount — disable
        // both payment buttons so a fast click can't check out against the
        // outdated total.
        checkoutForm.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
        optionsForm.submit();
    });
}

checkoutForm.addEventListener('submit', function () {
    // Whichever button was clicked, disable both — prevents double-submit /
    // double charge (or double order) on its own click. Deferred via
    // setTimeout: disabling the clicked button synchronously inside its own
    // form's submit handler makes the browser drop that button's name/value
    // from the submission itself (it's excluded as a disabled control by
    // the time the request is built) — silently losing payment_method.
    setTimeout(function () {
        checkoutForm.querySelectorAll('button[type="submit"]').forEach(function (b) { b.disabled = true; });
    }, 0);
});
</script>
