<?php /** @var array $order, $breakdown, $bank; @var ?string $backUrl; @var ?string $csrf */ ?>
<?php if (in_array($order['status'], ['fulfilled', 'fulfilling', 'paid'], true)): ?>
    <h1>Paiement confirmé ✔</h1>
    <p>Merci ! Votre paiement de <strong><?= number_format((float) $order['amount'], 2, ',', ' ') ?> €</strong> a bien été reçu.</p>
    <?php if ($breakdown['lines'] !== []): ?>
        <table class="details">
            <?php foreach ($breakdown['lines'] as $line): ?>
                <tr><th><?= htmlspecialchars((string) $line['label'], ENT_QUOTES) ?></th><td><?= number_format((float) $line['amount'], 2, ',', ' ') ?> €</td></tr>
            <?php endforeach; ?>
            <tr><th><strong>Total réglé</strong></th><td><strong><?= number_format((float) $order['amount'], 2, ',', ' ') ?> €</strong></td></tr>
        </table>
        <?php if ($breakdown['promoCode'] !== null): ?>
            <p class="muted">Code promo utilisé : <?= htmlspecialchars($breakdown['promoCode'], ENT_QUOTES) ?></p>
        <?php endif; ?>
    <?php endif; ?>
    <?php if ($order['kind'] === 'join'): ?>
        <p>Votre compte de réservation Balle Jaune est en cours de création — vos identifiants vous parviennent par email.</p>
        <p><strong>Dernière étape :</strong> lors de votre première venue, présentez vos chaussures de salle (semelles non marquantes)
            à l'accueil pour activer définitivement votre compte.</p>
    <?php endif; ?>
<?php elseif ($order['status'] === 'awaiting_promo_approval'): ?>
    <h1>Code promo en attente de validation</h1>
    <p>Votre commande utilise un code promo qui doit être validé par le club avant le paiement.
        Vous recevrez un email avec le lien de paiement dès que ce sera fait.</p>
    <?php if ($breakdown['lines'] !== []): ?>
        <table class="details">
            <?php foreach ($breakdown['lines'] as $line): ?>
                <tr><th><?= htmlspecialchars((string) $line['label'], ENT_QUOTES) ?></th><td><?= number_format((float) $line['amount'], 2, ',', ' ') ?> €</td></tr>
            <?php endforeach; ?>
            <tr><th><strong>Total (après validation)</strong></th><td><strong><?= number_format((float) $order['amount'], 2, ',', ' ') ?> €</strong></td></tr>
        </table>
    <?php endif; ?>
<?php elseif ($order['status'] === 'awaiting_bank_transfer'): ?>
    <?php if ($order['bank_transfer_claimed_at'] !== null): ?>
        <h1>Merci — nous avons bien noté</h1>
        <p>Votre virement sera vérifié par le club dès que possible. Votre demande sera finalisée
            dès que la réception sera constatée — comptez quelques jours ouvrés.</p>
    <?php else: ?>
        <h1>Merci — il ne reste que le virement à effectuer</h1>
        <p>Un email vient de vous être envoyé avec les coordonnées bancaires du club et la référence à indiquer.
            Votre demande sera finalisée dès que le club aura constaté la réception du virement — comptez quelques jours ouvrés.</p>
    <?php endif; ?>
    <table class="details">
        <tr><th>Bénéficiaire</th><td><?= htmlspecialchars($bank['name'], ENT_QUOTES) ?></td></tr>
        <tr><th>IBAN</th><td><?= htmlspecialchars($bank['iban'], ENT_QUOTES) ?></td></tr>
        <tr><th>BIC</th><td><?= htmlspecialchars($bank['bic'], ENT_QUOTES) ?></td></tr>
        <tr><th>Référence à indiquer</th><td><strong><?= htmlspecialchars(\App\Repository\OrderRepository::bankTransferReference($order), ENT_QUOTES) ?></strong></td></tr>
        <tr><th>Montant</th><td><?= number_format((float) $order['amount'], 2, ',', ' ') ?> €</td></tr>
    </table>
    <?php if ($order['bank_transfer_claimed_at'] === null): ?>
        <form method="post" action="/paiement/retour/<?= htmlspecialchars($order['checkout_reference'], ENT_QUOTES) ?>/virement-effectue" class="form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <button type="submit">J'ai effectué le virement</button>
        </form>
        <p><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline btn-small">← Précédent — je préfère payer en ligne</a></p>
    <?php endif; ?>
<?php elseif ($order['status'] === 'failed'): ?>
    <h1>Paiement refusé</h1>
    <p>Votre paiement n'a pas abouti. Aucun montant n'a été débité.</p>
    <p><a class="btn" href="/">Retour à l'accueil</a></p>
<?php else: ?>
    <h1>Paiement en attente</h1>
    <p>Votre paiement est en cours de traitement. Cette page peut être actualisée dans quelques instants ;
        vous recevrez un email de confirmation dès validation.</p>
<?php endif; ?>
