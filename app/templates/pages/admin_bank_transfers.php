<?php
/** @var array[] $orders  each enriched with 'name' and 'breakdown' (OrderBreakdownService shape) */
$kinds = ['join' => 'Adhésion', 'renewal' => 'Renouvellement'];
?>
<h1>Virements en attente</h1>
<p class="muted">Vérifiez sur le relevé bancaire du club qu'un virement de ce montant, avec la référence indiquée,
    a bien été reçu avant de confirmer. Refuser laisse l'adhérent réessayer ou payer en ligne.</p>

<?php if ($orders === []): ?>
    <p>Aucun virement en attente.</p>
<?php else: ?>
    <?php
        // Orders the payer has claimed float to the top — that's the club's
        // own actionable signal for which ones to check first, instead of
        // working through the list blind.
        usort($orders, fn (array $a, array $b) => ($b['bank_transfer_claimed_at'] !== null) <=> ($a['bank_transfer_claimed_at'] !== null));
    ?>
    <?php foreach ($orders as $o): ?>
        <fieldset>
            <table class="details">
                <tr><th>Type</th><td><?= $kinds[$o['kind']] ?? $o['kind'] ?></td></tr>
                <tr><th>Nom</th><td><?= $o['name'] !== '' ? htmlspecialchars($o['name'], ENT_QUOTES) : '—' ?></td></tr>
                <tr><th>Email</th><td><?= htmlspecialchars($o['email'], ENT_QUOTES) ?></td></tr>
                <tr><th>Référence à rechercher</th><td><strong><?= htmlspecialchars(\App\Repository\OrderRepository::bankTransferReference($o), ENT_QUOTES) ?></strong></td></tr>
                <tr>
                    <th>Virement déclaré par l'adhérent</th>
                    <td>
                        <?php if ($o['bank_transfer_claimed_at'] !== null): ?>
                            <span class="badge-tag badge-warning">le <?= date('d/m/Y H:i', strtotime($o['bank_transfer_claimed_at'])) ?></span>
                        <?php else: ?>
                            <span class="muted">Non signalé</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php foreach ($o['breakdown']['lines'] as $line): ?>
                    <tr><th><?= htmlspecialchars((string) $line['label'], ENT_QUOTES) ?></th><td><?= number_format((float) $line['amount'], 2, ',', ' ') ?> €</td></tr>
                <?php endforeach; ?>
                <tr><th><strong>Montant attendu</strong></th><td><strong><?= number_format((float) $o['amount'], 2, ',', ' ') ?> €</strong></td></tr>
                <tr><th>Demandée le</th><td><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td></tr>
            </table>
            <form method="post" action="/admin/virements/<?= (int) $o['id'] ?>/decision" class="form-inline">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                <button type="submit" name="decision" value="confirm" onclick="return confirm('Confirmer avoir constaté ce virement sur le relevé bancaire du club ?');">Virement reçu</button>
                <button type="submit" name="decision" value="reject" class="btn-danger" onclick="return confirm('Marquer ce virement comme non reçu ? L\'adhérent en sera informé par email.');">Non reçu</button>
            </form>
        </fieldset>
    <?php endforeach; ?>
<?php endif; ?>

<p><a href="/admin/commandes">← Commandes</a> · <a href="/admin">← Administration</a></p>
