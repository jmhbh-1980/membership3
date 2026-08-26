<?php /** @var array[] $orders, string $csrf, bool $archived, ?int $archivedCount */
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
$transactionCode = function (array $o): ?string {
    $meta = json_decode((string) ($o['meta'] ?? '{}'), true) ?: [];
    return $meta['transactionCode'] ?? null;
};
// Join orders keep bj_user_id = 0 forever (fulfillment records each person's id on
// application_people, not back onto the order), so the link only ever shows for
// renewal/credits orders.
$bjProfileUrl = fn (int $bjUserId): string => 'https://ballejaune.com/admin#page=/admin/users&panel=/admin/users/update/id/' . $bjUserId;
?>
<h1><?= $archived ? 'Commandes archivées' : 'Commandes' ?></h1>

<?php if ($orders === []): ?>
    <p><?= $archived ? 'Aucune commande archivée.' : 'Aucune commande.' ?></p>
<?php else: ?>
    <table class="details">
        <tr>
            <th>#</th><th>Type</th><th>Nom</th><th>Email</th><th>Montant</th><th>Statut</th><th>Transaction</th>
            <th>Créée le</th><th>Finalisée le</th><th>Dossier</th><th>Balle Jaune</th>
            <?php if (!$archived): ?><th></th><?php endif; ?>
            <th></th>
        </tr>
        <?php foreach ($orders as $o): ?>
            <tr>
                <td><?= (int) $o['id'] ?></td>
                <td><?= $kinds[$o['kind']] ?? $o['kind'] ?></td>
                <td><?= $o['name'] !== '' ? htmlspecialchars($o['name'], ENT_QUOTES) : '—' ?><?= $this->fetch('partials/garennois_badge.php', ['residence' => $o['residence'] ?? '']) ?></td>
                <td><?= htmlspecialchars($o['email'], ENT_QUOTES) ?></td>
                <td><?= number_format((float) $o['amount'], 2, ',', ' ') ?> €</td>
                <td><?= $statuses[$o['status']] ?? $o['status'] ?></td>
                <td><?= htmlspecialchars($transactionCode($o) ?? '—', ENT_QUOTES) ?></td>
                <td><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
                <td><?= $o['fulfilled_at'] !== null ? date('d/m/Y H:i', strtotime($o['fulfilled_at'])) : '—' ?></td>
                <td><?php if ($o['application_id'] !== null): ?><a href="/admin/demandes/<?= (int) $o['application_id'] ?>">Voir le dossier</a><?php else: ?>—<?php endif; ?></td>
                <td><?php if ((int) $o['bj_user_id'] > 0): ?><a href="<?= $bjProfileUrl((int) $o['bj_user_id']) ?>" target="_blank" rel="noopener">Voir la fiche</a><?php else: ?>—<?php endif; ?></td>
                <?php if (!$archived): ?>
                    <td>
                        <form method="post" action="/admin/commandes/<?= (int) $o['id'] ?>/annuler" class="form-inline" onsubmit="return confirm('Marquer cette commande comme annulée ? Elle sera archivée et exclue des totaux financiers.');">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                            <button type="submit" class="btn-small">Annuler</button>
                        </form>
                        <?php if ($o['status'] === 'fulfilled'): ?>
                            <form method="post" action="/admin/commandes/<?= (int) $o['id'] ?>/rembourser" class="form-inline" onsubmit="return confirm('Marquer cette commande comme remboursée ? Elle sera archivée et comptera comme un remboursement.');">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                                <button type="submit" class="btn-small">Rembourser</button>
                            </form>
                            <form method="post" action="/admin/commandes/<?= (int) $o['id'] ?>/traiter" class="form-inline" onsubmit="return confirm('Marquer cette commande comme traitée et l\'archiver ?');">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                                <button type="submit" class="btn-small">Marquer traitée</button>
                            </form>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
                <td><a href="/admin/commandes/<?= (int) $o['id'] ?>">Détails</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<?php if ($archived): ?>
    <p><a href="/admin/commandes">← Commandes actives</a></p>
<?php else: ?>
    <p><a href="/admin/commandes/archivees">Voir les archives (<?= (int) $archivedCount ?>)</a></p>
<?php endif; ?>
<p><a href="/admin">← Administration</a></p>
