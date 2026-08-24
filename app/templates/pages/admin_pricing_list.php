<?php /** @var array<string, array{live: bool, draft: bool}> $seasons */ ?>
<h1>Barèmes tarifaires</h1>
<p class="muted">Un barème par saison. Les modifications sont enregistrées en brouillon (invisible aux adhérents) jusqu'à publication.</p>

<?php if ($seasons === []): ?>
    <p>Aucun barème trouvé.</p>
<?php else: ?>
    <table class="details">
        <tr><th>Saison</th><th>Statut</th><th></th></tr>
        <?php foreach ($seasons as $label => $s): ?>
            <tr>
                <td><?= htmlspecialchars($label, ENT_QUOTES) ?></td>
                <td>
                    <?php if ($s['draft']): ?>
                        Brouillon<?= $s['live'] ? ' (une version publiée existe)' : '' ?>
                    <?php else: ?>
                        Publié ✔
                    <?php endif; ?>
                </td>
                <td><a href="/admin/tarifs/<?= htmlspecialchars($label, ENT_QUOTES) ?>">Modifier</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<form method="post" action="/admin/tarifs/nouvelle" class="form-inline">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <button type="submit" class="btn-small">Nouvelle saison (à partir de la plus récente)</button>
</form>

<p><a href="/admin">← Administration</a></p>
