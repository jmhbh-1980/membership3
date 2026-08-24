<?php /** @var array[] $members  @var array $filters  @var ?int $sent */ ?>
<h1>Campagne de renouvellement</h1>

<?php if ($sent !== null): ?>
    <div class="alert alert-ok">✔ <?= (int) $sent ?> email<?= $sent > 1 ? 's' : '' ?> de renouvellement envoyé<?= $sent > 1 ? 's' : '' ?>.</div>
<?php endif; ?>

<form method="get" class="form form-wide filters-inline">
    <fieldset>
        <legend>Filtres</legend>
        <div class="grid2">
            <div>
                <label for="statut">Adhésion</label>
                <select id="statut" name="statut">
                    <option value="active" <?= $filters['active'] === 'active' ? 'selected' : '' ?>>Active (non expirée)</option>
                    <option value="expired" <?= $filters['active'] === 'expired' ? 'selected' : '' ?>>Expirée</option>
                    <option value="all" <?= $filters['active'] === 'all' ? 'selected' : '' ?>>Toutes</option>
                </select>
            </div>
            <div>
                <label for="paye">Paiement</label>
                <select id="paye" name="paye">
                    <option value="all" <?= $filters['paid'] === 'all' ? 'selected' : '' ?>>Tous</option>
                    <option value="1" <?= $filters['paid'] === '1' ? 'selected' : '' ?>>Réglée</option>
                    <option value="0" <?= $filters['paid'] === '0' ? 'selected' : '' ?>>Non réglée</option>
                </select>
            </div>
        </div>
        <button type="submit" class="btn-small">Filtrer</button>
    </fieldset>
</form>

<form method="post" action="/admin/campagne/envoyer" class="form form-wide" style="max-width:none">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <p><strong><?= count($members) ?></strong> membre<?= count($members) > 1 ? 's' : '' ?> —
        <label class="choice" style="display:inline"><input type="checkbox" id="select-all"> tout sélectionner</label></p>
    <table class="details">
        <tr><th></th><th>Nom</th><th>Email</th><th>Abonnement</th><th>Fin</th><th>Payé</th></tr>
        <?php foreach ($members as $m): ?>
            <tr>
                <td><input type="checkbox" name="members[]" value="<?= (int) $m['user_id'] ?>" class="member-check" <?= $m['email'] === '' ? 'disabled' : '' ?>></td>
                <td><?= htmlspecialchars($m['lastname'] . ' ' . $m['firstname'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($m['email'] !== '' ? $m['email'] : '— sans email —', ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($m['subscription'], ENT_QUOTES) ?></td>
                <td><?= $m['date_end'] !== '' && $m['date_end'] !== '0000-00-00' ? date('d/m/Y', strtotime($m['date_end'])) : '—' ?></td>
                <td><?= $m['paid'] ? '✔' : '✗' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    <button type="submit" onclick="return confirm('Envoyer l\'email de renouvellement aux membres sélectionnés ?')">Envoyer aux membres sélectionnés</button>
</form>

<script>
document.getElementById('select-all').addEventListener('change', function () {
    document.querySelectorAll('.member-check:not(:disabled)').forEach(c => { c.checked = this.checked; });
});
</script>
<p><a href="/admin">← Administration</a></p>
