<?php
/**
 * @var array $app, $people, $subscription, $errors
 * @var App\Service\Quote $quote
 */
$isJeune = $subscription['audience'] === 'jeune';
$isCouple = (bool) $app['is_couple'];
$isSummerPack = (bool) $app['summer_pack'];
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

    <fieldset>
        <legend>Code promo</legend>
        <label for="promo_code">Vous avez un code promo ?</label>
        <input type="text" id="promo_code" name="promo_code" maxlength="32" placeholder="Code promo" value="<?= htmlspecialchars($app['promo_code'], ENT_QUOTES) ?>">
    </fieldset>

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

<?php if ($app['promo_code'] !== ''): ?>
    <p class="muted">Ce code doit être validé par un administrateur avant le paiement — vous recevrez un email avec le lien de paiement dès que ce sera fait.</p>
<?php endif; ?>
<form method="post" action="/paiement/<?= htmlspecialchars($app['token'], ENT_QUOTES) ?>/checkout" class="form" id="checkout-form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <button type="submit" id="pay-button"><?= $app['promo_code'] !== '' ? 'Envoyer pour validation' : 'Payer ' . number_format($quote->total(), 2, ',', ' ') . ' € en ligne' ?></button>
</form>

<script>
var optionsForm = document.getElementById('options-form');
var payButton = document.getElementById('pay-button');

if (optionsForm) {
    optionsForm.addEventListener('change', function () {
        // The cart total shown on screen is now stale until the options POST
        // round-trips and the page reloads with the recomputed amount — disable
        // "Payer" so a fast click can't check out against the outdated total.
        payButton.disabled = true;
        optionsForm.submit();
    });
}

document.getElementById('checkout-form').addEventListener('submit', function () {
    payButton.disabled = true; // prevent double-submit / double charge on its own click
});
</script>
