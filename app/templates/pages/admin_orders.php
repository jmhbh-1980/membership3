<?php /** @var array[] $orders, string $csrf, bool $archived, ?int $archivedCount */
$kinds = ['join' => 'Adhésion', 'renewal' => 'Renouvellement', 'credits' => 'Crédits', 'change' => 'Changement'];
$statuses = [
    'awaiting_promo_approval'  => 'Code promo en attente',
    'awaiting_bank_transfer'   => 'Virement en attente',
    'pending'    => 'En attente',
    'paid'       => 'Paiement reçu',
    'fulfilling' => 'En cours',
    'fulfilled'  => 'Payée',
    'failed'     => 'Échouée',
    'canceled'   => 'Annulée',
    'refunded'   => 'Remboursée',
    'processed'  => 'Traitée',
];
$isDuplicate = function (array $o): bool {
    $meta = json_decode((string) ($o['meta'] ?? '{}'), true) ?: [];
    return !empty($meta['duplicateFulfillment']);
};
$hasBadge = fn (array $o): bool => in_array($o['residence'] ?? '', ['garennois', 'hors-commune'], true);
?>
<h1><?= $archived ? 'Commandes archivées' : 'Commandes' ?></h1>

<?php if ($orders === []): ?>
    <p><?= $archived ? 'Aucune commande archivée.' : 'Aucune commande.' ?></p>
<?php else: ?>
    <div class="table-scroll">
    <table class="details">
        <tr>
            <th>#</th><th>Type</th><th>Nom</th><th>Montant</th><th>Statut</th><th>Finalisée le</th>
            <?php if (!$archived): ?><th></th><?php endif; ?>
            <th></th>
        </tr>
        <?php foreach ($orders as $o): ?>
            <tr>
                <td class="nowrap"><?= (int) $o['id'] ?></td>
                <td><?= $kinds[$o['kind']] ?? $o['kind'] ?></td>
                <td>
                    <?php if ($o['lastname'] === '' && $o['firstname'] === ''): ?>
                        —
                    <?php else: ?>
                        <?= htmlspecialchars($o['lastname'], ENT_QUOTES) ?><br>
                        <?= htmlspecialchars($o['firstname'], ENT_QUOTES) ?>
                    <?php endif; ?>
                    <?php if ($hasBadge($o)): ?><br><?= $this->fetch('partials/garennois_badge.php', ['residence' => $o['residence']]) ?><?php endif; ?>
                </td>
                <td class="nowrap"><?= number_format((float) $o['amount'], 2, ',', ' ') ?> €</td>
                <td class="nowrap"><?= $statuses[$o['status']] ?? $o['status'] ?>
                    <?php if ($isDuplicate($o)): ?><span class="badge-tag badge-warning" title="Paiement en double — remboursement probablement nécessaire">⚠ doublon</span><?php endif; ?>
                </td>
                <td><?= $o['fulfilled_at'] !== null ? date('d/m/Y H:i', strtotime($o['fulfilled_at'])) : '—' ?></td>
                <?php if (!$archived): ?>
                    <td>
                        <?php if ($o['status'] === 'awaiting_promo_approval'): ?>
                            <a href="/admin/codes-promo/approbations" class="btn btn-small btn-order-action">Traiter le code promo</a>
                        <?php elseif ($o['status'] === 'awaiting_bank_transfer'): ?>
                            <a href="/admin/virements" class="btn btn-small btn-order-action">Traiter le virement</a>
                        <?php else: ?>
                            <form method="post" action="/admin/commandes/<?= (int) $o['id'] ?>/annuler" class="form-inline" onsubmit="return confirm('Marquer cette commande comme annulée ? Elle sera archivée et exclue des totaux financiers.');">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                                <button type="submit" class="btn-small btn-order-action">Annuler</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($o['status'] === 'fulfilled'): ?>
                            <form method="post" action="/admin/commandes/<?= (int) $o['id'] ?>/rembourser" class="form-inline" onsubmit="return confirm('Marquer cette commande comme remboursée ? Elle sera archivée et comptera comme un remboursement.');">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                                <button type="submit" class="btn-small btn-order-action">Rembourser</button>
                            </form>
                            <form method="post" action="/admin/commandes/<?= (int) $o['id'] ?>/traiter" class="form-inline" onsubmit="return confirm('Marquer cette commande comme traitée et l\'archiver ?');">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                                <button type="submit" class="btn-small btn-order-action">Marquer traitée</button>
                            </form>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
                <td class="nowrap"><a href="/admin/commandes/<?= (int) $o['id'] ?>">Détails</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
<?php endif; ?>
<?php if ($archived): ?>
    <p><a href="/admin/commandes">← Commandes actives</a></p>
<?php else: ?>
    <p><a href="/admin/commandes/archivees">Voir les archives (<?= (int) $archivedCount ?>)</a></p>
<?php endif; ?>
<p><a href="/admin">← Administration</a></p>
