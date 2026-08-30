<?php
/** @var array $user  BJ user record (live) */
$fmt = fn (?string $d) => ($d && $d !== '0000-00-00') ? date('d/m/Y', strtotime($d)) : '—';
?>
<h1>Bonjour <?= htmlspecialchars(trim(($user['firstname'] ?? '') . ' ' . ($user['lastname'] ?? '')), ENT_QUOTES) ?></h1>

<h2>Mon adhésion</h2>
<table class="details">
    <tr><th>Formule</th><td><?= htmlspecialchars($subscriptionName !== '' ? $subscriptionName : 'Aucune', ENT_QUOTES) ?></td></tr>
    <tr><th>Période</th><td><?= $fmt($user['subscription_date_start'] ?? null) ?> → <?= $fmt($user['subscription_date_end'] ?? null) ?></td></tr>
    <tr><th>Paiement</th><td><?php if (!($user['subscription_paid'] ?? 0)): ?>Non réglée<?php elseif (($user['subscription_paid_date'] ?? '') !== ''): ?>Payé le <?= $fmt($user['subscription_paid_date']) ?><?php else: ?>Réglée<?php endif; ?></td></tr>
    <?php if ($sumupPaidAt !== null): ?>
        <tr><th>Paiement SumUp</th><td><?= date('d/m/Y à H:i', strtotime($sumupPaidAt)) ?> <span class="muted">(vue admin)</span></td></tr>
    <?php endif; ?>
    <tr><th>Licence</th><td><?= htmlspecialchars(($user['license_number'] ?? '') !== '' ? $user['license_number'] : 'Non renseignée', ENT_QUOTES) ?><?= !empty($user['flag']) ? ' <em>(enregistrement fédération en cours)</em>' : '' ?></td></tr>
    <tr><th>Crédits de jeu</th><td><?= htmlspecialchars(number_format((float) ($user['book_card_tickets'] ?? 0), 0, ',', ' '), ENT_QUOTES) ?></td></tr>
    <tr><th>Invitations</th><td><?= htmlspecialchars(number_format((float) ($user['book_guest_tickets'] ?? 0), 0, ',', ' '), ENT_QUOTES) ?></td></tr>
</table>

<p>
    <?php if (str_contains($subscriptionName, 'TICKETS')): ?>
        <a class="btn" href="/espace/credits">Acheter des crédits de jeu</a>
    <?php else: ?>
        <a class="btn" href="/espace/renouvellement">Renouveler mon adhésion</a>
    <?php endif; ?>
    <?php if ($showLessonsButton): ?>
        <a class="btn" href="/espace/cours-collectifs">Cours collectifs</a>
    <?php endif; ?>
    <a class="btn" href="/espace/factures">Mes factures</a>
</p>

<h2>Mes informations</h2>
<table class="details">
    <tr><th>Email</th><td><?= htmlspecialchars($user['email'] ?? '', ENT_QUOTES) ?></td></tr>
    <tr><th>Adresse</th><td><?= htmlspecialchars(trim(($user['address'] ?? '') . ', ' . ($user['postalcode'] ?? '') . ' ' . ($user['city'] ?? ''), ', '), ENT_QUOTES) ?></td></tr>
</table>
<p class="muted">Ces informations proviennent de Balle Jaune. Pour les corriger, contactez le club.</p>
