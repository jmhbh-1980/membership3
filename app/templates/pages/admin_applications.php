<?php /** @var array[] $rows */ ?>
<h1>Demandes d'adhésion en attente</h1>
<p><a href="/admin/demandes/abandonnees">Voir les demandes abandonnées →</a></p>

<?php if ($rows === []): ?>
    <p>Aucune demande en attente de validation. 🎉</p>
<?php else: ?>
    <div class="table-scroll">
    <table class="details">
        <tr><th>#</th><th>Adhérent(s)</th><th>Abonnement</th><th>Reçue le</th><th></th></tr>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= (int) $row['app']['id'] ?></td>
                <td>
                    <?php foreach ($row['people'] as $position => $p): ?>
                        <?php if ($position === 1 || !$row['app']['is_couple']): ?>
                            <?= htmlspecialchars($p['firstname'] . ' ' . $p['lastname'], ENT_QUOTES) ?><?= $p['is_minor'] ? ' (mineur)' : '' ?><br>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    <?php if ($row['app']['is_couple']): ?>
                        <?= $this->fetch('partials/couple_partner.php', [
                            'partner' => isset($row['people'][2]) ? ['name' => $row['people'][2]['firstname'] . ' ' . $row['people'][2]['lastname'], 'bjUserId' => 0] : null,
                            'missingIsGap' => true,
                        ]) ?><br>
                    <?php endif; ?>
                    <?= $this->fetch('partials/garennois_badge.php', [
                        'residence' => $row['app']['residence'] ?? '',
                        'pricingResidence' => $row['app']['pricing_residence'] ?? '',
                    ]) ?>
                </td>
                <td><?= htmlspecialchars($row['app']['subscription_type'], ENT_QUOTES) ?><?= $row['app']['is_couple'] ? ' (couple)' : '' ?></td>
                <td><?= $row['app']['submitted_at'] ? date('d/m/Y H:i', strtotime($row['app']['submitted_at'])) : '—' ?></td>
                <td><a class="btn btn-small" href="/admin/demandes/<?= (int) $row['app']['id'] ?>">Examiner</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
<?php endif; ?>
