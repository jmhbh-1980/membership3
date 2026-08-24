<?php /** @var array[] $rows */ ?>
<h1>Demandes d'adhésion en attente</h1>
<p><a href="/admin/demandes/abandonnees">Voir les demandes abandonnées →</a></p>

<?php if ($rows === []): ?>
    <p>Aucune demande en attente de validation. 🎉</p>
<?php else: ?>
    <table class="details">
        <tr><th>#</th><th>Adhérent(s)</th><th>Abonnement</th><th>Reçue le</th><th></th></tr>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= (int) $row['app']['id'] ?></td>
                <td>
                    <?php foreach ($row['people'] as $p): ?>
                        <?= htmlspecialchars($p['firstname'] . ' ' . $p['lastname'], ENT_QUOTES) ?><?= $p['is_minor'] ? ' (mineur)' : '' ?><br>
                    <?php endforeach; ?>
                </td>
                <td><?= htmlspecialchars($row['app']['subscription_type'], ENT_QUOTES) ?><?= $row['app']['is_couple'] ? ' (couple)' : '' ?></td>
                <td><?= $row['app']['submitted_at'] ? date('d/m/Y H:i', strtotime($row['app']['submitted_at'])) : '—' ?></td>
                <td><a class="btn btn-small" href="/admin/demandes/<?= (int) $row['app']['id'] ?>">Examiner</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
