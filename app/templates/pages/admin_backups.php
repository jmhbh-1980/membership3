<?php
/** @var array{filename: string, size: int, created_at: int}[] $backups newest first */
$formatSize = fn (int $bytes) => $bytes >= 1024 * 1024
    ? number_format($bytes / 1024 / 1024, 1, ',', ' ') . ' Mo'
    : round($bytes / 1024) . ' Ko';
?>
<h1>Sauvegardes</h1>
<p class="muted">Une sauvegarde contient la base de données, les documents des adhérents
    (<code>uploads/</code>) et les barèmes tarifaires (<code>pricing_data/</code>) dans une seule archive zip.
    Téléchargez-la et conservez-la ailleurs qu'ici — elle reste sur ce serveur tant que vous ne la supprimez pas.</p>

<form method="post" action="/admin/sauvegardes/generer">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <button type="submit" class="btn">Générer une sauvegarde</button>
</form>

<?php if ($backups === []): ?>
    <p>Aucune sauvegarde pour le moment.</p>
<?php else: ?>
    <table class="details">
        <tr><th>Générée le</th><th>Taille</th><th></th></tr>
        <?php foreach ($backups as $b): ?>
            <tr>
                <td><?= date('d/m/Y H:i:s', $b['created_at']) ?></td>
                <td><?= $formatSize($b['size']) ?></td>
                <td><a href="/admin/sauvegardes/<?= htmlspecialchars($b['filename'], ENT_QUOTES) ?>/telecharger">Télécharger</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<p><a href="/admin">← Administration</a></p>
