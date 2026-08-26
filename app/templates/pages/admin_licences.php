<?php /** @var array[] $users  @var string $csrf */ ?>
<h1>Licences à enregistrer (⚑)</h1>
<p class="muted">Membres marqués « licence non enregistrée ». Une fois la licence créée ou renouvelée auprès de la fédération (dans Balle Jaune), levez le marquage ici.</p>

<?php if ($users === []): ?>
    <p>Aucune licence en attente. 🎉</p>
<?php else: ?>
    <table class="details">
        <tr><th>Nom</th><th>Naissance</th><th>Type</th><th></th></tr>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['lastname'] . ' ' . $u['firstname'], ENT_QUOTES) ?><?= $this->fetch('partials/garennois_badge.php', ['residence' => $u['residence'] ?? '']) ?></td>
                <td><?= ($u['birthday'] ?? '') !== '' ? date('d/m/Y', strtotime($u['birthday'])) : '—' ?></td>
                <td><?= htmlspecialchars($u['license_number'] !== '' ? 'renouvellement (' . $u['license_number'] . ')' : 'création', ENT_QUOTES) ?></td>
                <td>
                    <form method="post" action="/admin/licences/<?= (int) $u['user_id'] ?>" class="form-inline">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                        <button type="submit" class="btn-small">Licence enregistrée ✔</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<p><a href="/admin">← Administration</a></p>
