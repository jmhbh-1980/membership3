<?php
/** @var array $req, $subscriptions, $liveLabel */
$currentLabel = $liveLabel[$req['id']] ?? ($req['current_label'] !== '' ? $req['current_label'] : null);
?>
<legend>
    <?= $this->fetch('partials/member_name.php', ['name' => $req['member_name'], 'bjUserId' => $req['bj_user_id']]) ?><?= $this->fetch('partials/garennois_badge.php', ['residence' => $req['residence'] ?? '']) ?>
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
    <?php if ($req['partner_email'] !== ''): ?>
        <tr><th>Conjoint(e) demandé(e)</th><td>
            <?php if ($req['requestedPartner'] !== null): ?>
                <?= $this->fetch('partials/couple_partner.php', ['partner' => $req['requestedPartner']]) ?>
                <span class="muted">(<?= htmlspecialchars($req['partner_email'], ENT_QUOTES) ?>)</span>
            <?php else: ?>
                <?= htmlspecialchars($req['partner_email'], ENT_QUOTES) ?> —
                <strong>aucun compte adhérent trouvé pour cette adresse</strong> : le/la conjoint(e) ne serait pas
                renouvelé(e) avec cette demande.
            <?php endif; ?>
        </td></tr>
    <?php endif; ?>
    <?php if ($req['currentCouple'] !== null): ?>
        <?php
            $current = $req['currentCouple']['partner'];
            $samePartner = $current !== null && $req['requestedPartner'] !== null && $current['bjUserId'] === $req['requestedPartner']['bjUserId'];
        ?>
        <?php if (!$samePartner): ?>
            <tr><th>Couple actuel</th><td><?= $this->fetch('partials/couple_partner.php', ['partner' => $current]) ?>
                <?php if ($req['kind'] !== 'licence' && !$req['is_couple']): ?>
                    <br><span class="muted">La formule demandée est individuelle : le/la conjoint(e) ne serait plus couvert(e) par ce renouvellement.</span>
                <?php elseif ($req['kind'] !== 'licence' && $req['partner_email'] !== ''): ?>
                    <br><span class="muted">La demande désigne un(e) autre conjoint(e).</span>
                <?php endif; ?></td></tr>
        <?php endif; ?>
    <?php endif; ?>
    <tr><th>Demandée le</th><td><?= date('d/m/Y H:i', strtotime($req['created_at'])) ?></td></tr>
    <?php if ($req['admin_note'] !== ''): ?><tr><th>Note</th><td><?= htmlspecialchars($req['admin_note'], ENT_QUOTES) ?></td></tr><?php endif; ?>
</table>
