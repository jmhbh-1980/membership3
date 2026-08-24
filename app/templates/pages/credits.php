<?php /** @var array $user, $pack, $errors */ ?>
<h1>Crédits de jeu</h1>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<table class="details">
    <tr><th>Solde actuel</th><td><strong><?= htmlspecialchars(number_format((float) ($user['book_card_tickets'] ?? 0), 1, ',', ' '), ENT_QUOTES) ?></strong> crédit(s)</td></tr>
</table>

<h2>Recharger</h2>
<table class="details">
    <tr><th><?= htmlspecialchars($pack['label'], ENT_QUOTES) ?></th>
        <td><strong><?= number_format((float) $pack['price'], 2, ',', ' ') ?> €</strong> — <?= (int) $pack['tickets'] ?> crédits ajoutés à votre solde</td></tr>
</table>

<form method="post" action="/espace/credits/checkout" class="form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <button type="submit">Acheter <?= (int) $pack['tickets'] ?> crédits — <?= number_format((float) $pack['price'], 2, ',', ' ') ?> €</button>
</form>
<p class="muted">Le solde est mis à jour immédiatement après paiement. Un crédit = une séance.</p>
