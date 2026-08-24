<?php
/**
 * @var array $app, $people, $problems
 * @var ?App\Service\Quote $quote
 * @var ?array $subscription
 */
?>
<h1>Récapitulatif</h1>
<?= $this->fetch('partials/wizard_steps.php', ['steps' => $steps]) ?>
<p>Vérifiez votre demande avant de l'envoyer au club.</p>

<?php if ($problems !== []): ?>
    <div class="alert"><p>Votre demande est incomplète :</p>
        <ul><?php foreach ($problems as $p): ?><li><?= htmlspecialchars($p, ENT_QUOTES) ?></li><?php endforeach; ?></ul>
        <p><a href="/inscription/<?= htmlspecialchars($app['token'], ENT_QUOTES) ?>/formule">Reprendre ma demande</a></p>
    </div>
<?php endif; ?>

<h2>Adhérent<?= count($people) > 1 ? 's' : '' ?></h2>
<table class="details">
    <?php foreach ($people as $person): ?>
        <tr>
            <th><?= htmlspecialchars($person['firstname'] . ' ' . $person['lastname'], ENT_QUOTES) ?></th>
            <td>Né(e) le <?= date('d/m/Y', strtotime($person['birthdate'])) ?>
                <?= $person['is_minor'] ? ' — mineur(e), représentant légal : ' . htmlspecialchars($person['guardian_fullname'], ENT_QUOTES) : '' ?></td>
        </tr>
    <?php endforeach; ?>
    <tr><th>Adresse</th><td><?= htmlspecialchars($people[1]['address'] . ', ' . $people[1]['postalcode'] . ' ' . $people[1]['city'], ENT_QUOTES) ?></td></tr>
</table>

<?php if ($quote !== null && $subscription !== null): ?>
    <h2>Estimation du montant</h2>
    <table class="details">
        <?php foreach ($quote->lines as $line): ?>
            <tr>
                <th><?= htmlspecialchars($line->label, ENT_QUOTES) ?></th>
                <td><?= number_format($line->amount, 2, ',', ' ') ?> €
                    <?php if ($line->amount < $line->baseAmount): ?>
                        <span class="muted">(au lieu de <?= number_format($line->baseAmount, 2, ',', ' ') ?> € — prorata <?= $quote->prorataMonths ?>/12 déduit)</span>
                    <?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
        <tr><th><strong>Total</strong></th><td><strong><?= number_format($quote->total(), 2, ',', ' ') ?> €</strong></td></tr>
    </table>
    <p class="muted">Le paiement n'intervient qu'après validation de votre demande par le club. Vous recevrez un email avec le lien de paiement.</p>
<?php endif; ?>

<?php if ($problems === []): ?>
    <form method="post" class="form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <div class="wizard-nav">
            <?php if ($backUrl !== null): ?><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline btn-small">← Précédent</a><?php endif; ?>
            <button type="submit">Envoyer ma demande au club</button>
        </div>
    </form>
<?php elseif ($backUrl !== null): ?>
    <p><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline btn-small">← Précédent</a></p>
<?php endif; ?>
