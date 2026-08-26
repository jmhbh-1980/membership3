<?php
/** @var array[] $orders  each enriched with 'name' and 'breakdown' (OrderBreakdownService shape) */
$kinds = ['join' => 'Adhésion', 'renewal' => 'Renouvellement'];
?>
<h1>Commandes avec code promo en attente</h1>
<p class="muted">Le code promo appliqué à ces commandes doit être validé avant qu'un lien de paiement ne soit envoyé. Refuser laisse l'adhérent poursuivre au tarif plein.</p>

<?php if ($orders === []): ?>
    <p>Aucune commande en attente.</p>
<?php else: ?>
    <?php foreach ($orders as $o): ?>
        <fieldset>
            <table class="details">
                <tr><th>Type</th><td><?= $kinds[$o['kind']] ?? $o['kind'] ?></td></tr>
                <tr><th>Nom</th><td><?= $o['name'] !== '' ? htmlspecialchars($o['name'], ENT_QUOTES) : '—' ?></td></tr>
                <tr><th>Email</th><td><?= htmlspecialchars($o['email'], ENT_QUOTES) ?></td></tr>
                <tr><th>Code promo</th><td><?= htmlspecialchars((string) ($o['breakdown']['promoCode'] ?? '—'), ENT_QUOTES) ?></td></tr>
                <?php foreach ($o['breakdown']['lines'] as $line): ?>
                    <tr><th><?= htmlspecialchars((string) $line['label'], ENT_QUOTES) ?></th><td><?= number_format((float) $line['amount'], 2, ',', ' ') ?> €</td></tr>
                <?php endforeach; ?>
                <tr><th><strong>Total</strong></th><td><strong><?= number_format((float) $o['amount'], 2, ',', ' ') ?> €</strong></td></tr>
                <tr><th>Demandée le</th><td><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td></tr>
            </table>
            <form method="post" action="/admin/codes-promo/approbations/<?= (int) $o['id'] ?>/decision" class="form form-wide">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                <label>Note (transmise à l'adhérent en cas de refus)</label>
                <textarea name="note" rows="2" maxlength="500"></textarea>
                <div>
                    <button type="submit" name="decision" value="approve">Approuver</button>
                    <button type="submit" name="decision" value="refuse" class="btn-danger">Refuser</button>
                </div>
            </form>
        </fieldset>
    <?php endforeach; ?>
<?php endif; ?>

<p><a href="/admin/codes-promo">← Codes promo</a> · <a href="/admin">← Administration</a></p>
