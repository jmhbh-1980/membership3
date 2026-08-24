<?php /** @var array[] $pending  @var array $subscriptions */ ?>
<h1>Demandes de changement d'abonnement</h1>

<?php if ($pending === []): ?>
    <p>Aucune demande en attente.</p>
<?php else: ?>
    <?php foreach ($pending as $req): ?>
        <fieldset>
            <legend><?= htmlspecialchars($req['member_name'], ENT_QUOTES) ?> — saison <?= (int) $req['season_start_year'] ?>-<?= (int) $req['season_start_year'] + 1 ?></legend>
            <table class="details">
                <tr><th>Abonnement actuel</th><td><?= htmlspecialchars($req['current_label'] !== '' ? $req['current_label'] : 'inconnu', ENT_QUOTES) ?></td></tr>
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
            </table>
            <form method="post" action="/admin/changements/<?= (int) $req['id'] ?>/decision" class="form form-wide">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                <label>Note (transmise à l'adhérent)</label>
                <textarea name="note" rows="2" maxlength="500"></textarea>
                <div>
                    <button type="submit" name="decision" value="approve">Accepter</button>
                    <button type="submit" name="decision" value="refuse" class="btn-danger">Refuser</button>
                </div>
            </form>
        </fieldset>
    <?php endforeach; ?>
<?php endif; ?>
<p><a href="/admin">← Administration</a></p>
