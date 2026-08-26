<?php
/** @var array $req, $subscriptions, $liveLabel */
$currentLabel = $liveLabel[$req['id']] ?? ($req['current_label'] !== '' ? $req['current_label'] : null);
?>
<legend>
    <?= htmlspecialchars($req['member_name'], ENT_QUOTES) ?><?= $this->fetch('partials/garennois_badge.php', ['residence' => $req['residence'] ?? '']) ?>
    — saison <?= (int) $req['season_start_year'] ?>-<?= (int) $req['season_start_year'] + 1 ?>
    <?php if ($req['status'] === 'approved'): ?>
        <span class="badge-tag">Approuvée le <?= htmlspecialchars(date('d/m/Y H:i', strtotime($req['decided_at'])), ENT_QUOTES) ?> — en attente de paiement</span>
    <?php elseif ($req['status'] === 'completed'): ?>
        <span class="badge-tag">Approuvée le <?= htmlspecialchars(date('d/m/Y H:i', strtotime($req['decided_at'])), ENT_QUOTES) ?> — réglée</span>
    <?php elseif ($req['status'] === 'refused'): ?>
        <span class="badge-tag">Refusée le <?= htmlspecialchars(date('d/m/Y H:i', strtotime($req['decided_at'])), ENT_QUOTES) ?></span>
    <?php endif; ?>
</legend>
<table class="details">
    <tr><th>Abonnement actuel</th><td><?= htmlspecialchars($currentLabel ?? 'inconnu', ENT_QUOTES) ?></td></tr>
    <?php if ($req['kind'] === 'licence'): ?>
        <tr><th>Retrait de licence demandé</th><td><?= htmlspecialchars($req['licence_removal_reason'], ENT_QUOTES) ?></td></tr>
        <?php if ($req['partner_licence_removed']): ?>
            <tr><th>Retrait — conjoint(e)</th><td><?= htmlspecialchars($req['partner_licence_removal_reason'], ENT_QUOTES) ?></td></tr>
        <?php endif; ?>
    <?php else: ?>
        <tr><th>Abonnement demandé</th><td><?= htmlspecialchars($subscriptions[$req['subscription_type']]['label'] ?? $req['subscription_type'], ENT_QUOTES) ?><?= $req['is_couple'] ? ' — couple' : '' ?><?= $req['competitor'] ? ' — compétiteur' : '' ?><?= (int) $req['lessons'] > 0 ? ' + cours collectifs × ' . (int) $req['lessons'] : '' ?></td></tr>
    <?php endif; ?>
    <?php if ($req['partner_email'] !== ''): ?><tr><th>Conjoint(e)</th><td><?= htmlspecialchars($req['partner_email'], ENT_QUOTES) ?></td></tr><?php endif; ?>
    <tr><th>Demandée le</th><td><?= date('d/m/Y H:i', strtotime($req['created_at'])) ?></td></tr>
    <?php if ($req['admin_note'] !== ''): ?><tr><th>Note</th><td><?= htmlspecialchars($req['admin_note'], ENT_QUOTES) ?></td></tr><?php endif; ?>
</table>
