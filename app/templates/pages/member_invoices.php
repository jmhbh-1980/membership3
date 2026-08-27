<?php
/** @var array $invoices */
?>
<h1>Mes factures</h1>

<?php if ($invoices === []): ?>
    <p class="muted">Aucune facture disponible pour le moment.</p>
<?php else: ?>
    <table class="details">
        <?php foreach ($invoices as $invoice): ?>
            <tr>
                <th><?= htmlspecialchars($invoice['number'], ENT_QUOTES) ?></th>
                <td><?= date('d/m/Y', strtotime($invoice['issued_at'])) ?></td>
                <td><?= number_format((float) $invoice['amount'], 2, ',', ' ') ?> €</td>
                <td><a href="/espace/factures/<?= (int) $invoice['id'] ?>/telecharger" target="_blank" rel="noopener">Télécharger</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<p><a href="/espace">← Mon espace</a></p>
