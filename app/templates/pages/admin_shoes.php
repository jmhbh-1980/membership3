<?php /** @var array[] $users */ ?>
<h1>Contrôle des semelles</h1>
<p class="muted">Nouveaux adhérents ayant réglé leur adhésion, en attente du contrôle des semelles (compte « Visiteur »).
Valider le contrôle active leur compte de réservation (passage en « Membre »).</p>

<?php if ($users === []): ?>
    <p>Aucun compte en attente d'activation. 🎉</p>
<?php else: ?>
    <table class="details">
        <tr><th>Nom</th><th>Email</th><th>Payé le</th><th></th></tr>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['lastname'] . ' ' . $u['firstname'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($u['email'], ENT_QUOTES) ?></td>
                <td><?= ($u['subscription_paid_date'] ?? '') !== '' && $u['subscription_paid_date'] !== '0000-00-00' ? date('d/m/Y', strtotime($u['subscription_paid_date'])) : '—' ?></td>
                <td>
                    <form method="post" action="/admin/semelles/<?= (int) $u['user_id'] ?>">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                        <button type="submit" class="btn-small">Semelles OK — activer ✔</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<p><a href="/admin">← Administration</a></p>
