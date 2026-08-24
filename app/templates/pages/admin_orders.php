<?php /** @var array[] $orders */
$kinds = ['join' => 'Adhésion', 'renewal' => 'Renouvellement', 'credits' => 'Crédits', 'change' => 'Changement'];
$statuses = ['pending' => 'En attente', 'paid' => 'Payée', 'fulfilling' => 'En cours', 'fulfilled' => 'Finalisée', 'failed' => 'Échouée', 'canceled' => 'Annulée'];
?>
<h1>Commandes</h1>

<?php if ($orders === []): ?>
    <p>Aucune commande.</p>
<?php else: ?>
    <table class="details">
        <tr><th>#</th><th>Type</th><th>Email</th><th>Montant</th><th>Statut</th><th>Créée le</th><th>Finalisée le</th><th>Dossier</th></tr>
        <?php foreach ($orders as $o): ?>
            <tr>
                <td><?= (int) $o['id'] ?></td>
                <td><?= $kinds[$o['kind']] ?? $o['kind'] ?></td>
                <td><?= htmlspecialchars($o['email'], ENT_QUOTES) ?></td>
                <td><?= number_format((float) $o['amount'], 2, ',', ' ') ?> €</td>
                <td><?= $statuses[$o['status']] ?? $o['status'] ?></td>
                <td><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td>
                <td><?= $o['fulfilled_at'] !== null ? date('d/m/Y H:i', strtotime($o['fulfilled_at'])) : '—' ?></td>
                <td><?php if ($o['application_id'] !== null): ?><a href="/admin/demandes/<?= (int) $o['application_id'] ?>">Voir le dossier</a><?php else: ?>—<?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<p><a href="/admin">← Administration</a></p>
