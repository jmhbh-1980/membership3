<?php
/**
 * @var array $order, $breakdown
 * @var string $csrf
 */
$kinds = ['join' => 'Adhésion', 'renewal' => 'Renouvellement', 'credits' => 'Crédits', 'change' => 'Changement'];
$statuses = [
    'pending'    => 'En attente',
    'paid'       => 'Paiement reçu',
    'fulfilling' => 'En cours',
    'fulfilled'  => 'Payée',
    'failed'     => 'Échouée',
    'canceled'   => 'Annulée',
    'refunded'   => 'Remboursée',
    'processed'  => 'Traitée',
];
$meta = json_decode((string) ($order['meta'] ?? '{}'), true) ?: [];
$archived = in_array($order['status'], ['canceled', 'refunded', 'processed'], true);
?>
<h1>Commande #<?= (int) $order['id'] ?> — <?= $kinds[$order['kind']] ?? $order['kind'] ?></h1>

<table class="details">
    <tr><th>Statut</th><td><?= $statuses[$order['status']] ?? $order['status'] ?></td></tr>
    <tr><th>Email</th><td><?= htmlspecialchars($order['email'], ENT_QUOTES) ?></td></tr>
    <tr><th>Référence</th><td><?= htmlspecialchars($order['checkout_reference'], ENT_QUOTES) ?></td></tr>
    <tr><th>Transaction SumUp</th><td><?= htmlspecialchars($meta['transactionCode'] ?? '—', ENT_QUOTES) ?></td></tr>
    <tr><th>Créée le</th><td><?= date('d/m/Y H:i', strtotime($order['created_at'])) ?></td></tr>
    <tr><th>Finalisée le</th><td><?= $order['fulfilled_at'] !== null ? date('d/m/Y H:i', strtotime($order['fulfilled_at'])) : '—' ?></td></tr>
    <?php if ($order['application_id'] !== null): ?>
        <tr><th>Dossier</th><td><a href="/admin/demandes/<?= (int) $order['application_id'] ?>">Voir le dossier</a></td></tr>
    <?php endif; ?>
    <?php if ((int) $order['bj_user_id'] > 0): ?>
        <tr><th>Balle Jaune</th><td><a href="https://ballejaune.com/admin#page=/admin/users&panel=/admin/users/update/id/<?= (int) $order['bj_user_id'] ?>" target="_blank" rel="noopener">Voir la fiche</a></td></tr>
    <?php endif; ?>
</table>

<h2>Détail</h2>
<?php if ($breakdown['lines'] === []): ?>
    <p class="muted">Aucun détail disponible pour cette commande.</p>
<?php else: ?>
    <table class="details">
        <?php foreach ($breakdown['lines'] as $line): ?>
            <tr><th><?= htmlspecialchars((string) $line['label'], ENT_QUOTES) ?></th><td><?= number_format((float) $line['amount'], 2, ',', ' ') ?> €</td></tr>
        <?php endforeach; ?>
        <tr><th><strong>Total</strong></th><td><strong><?= number_format((float) $order['amount'], 2, ',', ' ') ?> €</strong></td></tr>
    </table>
    <?php if ($breakdown['promoCode'] !== null): ?>
        <p class="muted">Code promo utilisé : <?= htmlspecialchars($breakdown['promoCode'], ENT_QUOTES) ?></p>
    <?php endif; ?>
<?php endif; ?>

<h2>Actions</h2>
<?php if ($archived): ?>
    <p class="muted">Commande archivée (<?= $statuses[$order['status']] ?? $order['status'] ?>).</p>
<?php else: ?>
    <p class="muted">À utiliser si la commande a été annulée ou remboursée directement dans Balle Jaune (aucun signal live n'existe pour les commandes/paiements côté BJ).</p>
    <form method="post" action="/admin/commandes/<?= (int) $order['id'] ?>/annuler" class="form-inline" onsubmit="return confirm('Marquer cette commande comme annulée ? Elle sera archivée et exclue des totaux financiers.');">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <button type="submit" class="btn-small">Marquer annulée</button>
    </form>
    <?php if ($order['status'] === 'fulfilled'): ?>
        <form method="post" action="/admin/commandes/<?= (int) $order['id'] ?>/rembourser" class="form-inline" onsubmit="return confirm('Marquer cette commande comme remboursée ? Elle sera archivée et comptera comme un remboursement.');">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <button type="submit" class="btn-small">Marquer remboursée</button>
        </form>
        <form method="post" action="/admin/commandes/<?= (int) $order['id'] ?>/traiter" class="form-inline" onsubmit="return confirm('Marquer cette commande comme traitée et l\'archiver ?');">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <button type="submit" class="btn-small">Marquer traitée</button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<p><a href="/admin/commandes">← Commandes</a></p>
