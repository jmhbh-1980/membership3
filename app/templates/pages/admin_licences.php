<?php /** @var array[] $users */ ?>
<h1>Licences à enregistrer (⚑)</h1>
<p class="muted">Membres marqués « licence non enregistrée ». Saisissez le numéro une fois la licence créée auprès de la fédération : le marquage est levé automatiquement.</p>

<?php if ($users === []): ?>
    <p>Aucune licence en attente. 🎉</p>
<?php else: ?>
    <table class="details">
        <tr><th>Nom</th><th>Naissance</th><th>Type</th><th>Numéro de licence</th></tr>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= htmlspecialchars($u['lastname'] . ' ' . $u['firstname'], ENT_QUOTES) ?></td>
                <td><?= ($u['birthday'] ?? '') !== '' ? date('d/m/Y', strtotime($u['birthday'])) : '—' ?></td>
                <td><?= htmlspecialchars($u['license_number'] !== '' ? 'renouvellement (' . $u['license_number'] . ')' : 'création', ENT_QUOTES) ?></td>
                <td>
                    <form method="post" action="/admin/licences/<?= (int) $u['user_id'] ?>" class="form-inline">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                        <input name="license_number" maxlength="15" placeholder="N° licence" value="<?= htmlspecialchars($u['license_number'], ENT_QUOTES) ?>" required>
                        <button type="submit" class="btn-small">Enregistrée ✔</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>
<p><a href="/admin">← Administration</a></p>
