<?php /** @var array $order */ ?>
<h1>Paiement — simulation (mode développement)</h1>
<p class="muted">SumUp n'est pas configuré : cette page simule la page de paiement hébergée.</p>
<table class="details">
    <tr><th>Commande</th><td>#<?= (int) $order['id'] ?> (<?= htmlspecialchars($order['checkout_reference'], ENT_QUOTES) ?>)</td></tr>
    <tr><th>Montant</th><td><?= number_format((float) $order['amount'], 2, ',', ' ') ?> €</td></tr>
</table>
<form method="post" class="form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <button type="submit">✅ Simuler un paiement réussi</button>
</form>
