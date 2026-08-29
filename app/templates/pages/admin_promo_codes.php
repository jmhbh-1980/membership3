<?php
/** @var array[] $codes
 *  @var array $old, $errors
 *  @var bool $archived
 *  @var ?int $editingId
 */
$kinds = ['percent' => '%', 'fixed' => '€'];
$scopes = ['join' => 'Adhésion', 'renewal' => 'Renouvellement', 'both' => 'Les deux'];
?>
<h1><?= $archived ? 'Codes promo archivés' : 'Codes promo' ?></h1>
<p class="muted">Codes à usage ponctuel pour un cas particulier (réduction ou dispense de frais accordée par le club) — appliqués par l'adhérent au paiement de l'adhésion ou du renouvellement.</p>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($codes === []): ?>
    <p><?= $archived ? 'Aucun code promo archivé.' : 'Aucun code promo créé.' ?></p>
<?php else: ?>
    <div class="table-scroll">
    <table class="details">
        <tr><th>Code</th><th>Réduction</th><th>Portée</th><th>Utilisations</th><th>Expire le</th><th>Statut</th><th>Créé par</th><?php if (!$archived): ?><th></th><?php endif; ?></tr>
        <?php foreach ($codes as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['code'], ENT_QUOTES) ?><?php if ($c['note'] !== ''): ?><br><span class="muted"><?= htmlspecialchars($c['note'], ENT_QUOTES) ?></span><?php endif; ?></td>
                <td><?= number_format((float) $c['value'], $c['kind'] === 'percent' ? 0 : 2, ',', ' ') ?> <?= $kinds[$c['kind']] ?? $c['kind'] ?></td>
                <td><?= $scopes[$c['scope']] ?? $c['scope'] ?></td>
                <td><?= (int) $c['usage'] ?><?= $c['max_uses'] !== null ? ' / ' . (int) $c['max_uses'] : '' ?></td>
                <td><?= $c['expires_at'] !== null ? date('d/m/Y', strtotime($c['expires_at'])) : '—' ?></td>
                <td><?= ((int) $c['active'] === 1) ? 'Actif' : 'Désactivé' ?></td>
                <td><?= htmlspecialchars($c['created_by'], ENT_QUOTES) ?></td>
                <?php if (!$archived): ?>
                <td>
                    <form method="post" action="/admin/codes-promo/<?= (int) $c['id'] ?>/<?= (int) $c['active'] === 1 ? 'desactiver' : 'activer' ?>" class="form-inline">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                        <button type="submit" class="btn-small"><?= (int) $c['active'] === 1 ? 'Désactiver' : 'Activer' ?></button>
                    </form>
                    <?php if (!$c['locked']): ?>
                        <a href="/admin/codes-promo/<?= (int) $c['id'] ?>/modifier" class="btn-small">Modifier</a>
                        <form method="post" action="/admin/codes-promo/<?= (int) $c['id'] ?>/supprimer" class="form-inline" onsubmit="return confirm('Supprimer définitivement ce code promo ? Cette action est irréversible.');">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                            <button type="submit" class="btn-small btn-danger">Supprimer</button>
                        </form>
                    <?php else: ?>
                        <span class="muted">Verrouillé — déjà utilisé, archivez-le pour le remplacer</span>
                    <?php endif; ?>
                    <form method="post" action="/admin/codes-promo/<?= (int) $c['id'] ?>/archiver" class="form-inline" onsubmit="return confirm('<?= $c['locked'] ? 'Archiver ce code ? Il restera consultable dans les archives mais ne sera plus utilisable.' : 'Archiver ce code promo ? Il restera consultable dans les archives.' ?>');">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                        <button type="submit" class="btn-small">Archiver</button>
                    </form>
                </td>
                <?php endif; ?>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
<?php endif; ?>

<?php if (!$archived): ?>
    <h2><?= $editingId !== null ? 'Modifier le code' : 'Nouveau code' ?></h2>
    <form method="post" action="<?= $editingId !== null ? '/admin/codes-promo/' . $editingId . '/modifier' : '/admin/codes-promo/nouveau' ?>" class="form form-wide">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

        <label for="code">Code</label>
        <div class="form-inline">
            <input type="text" id="code" name="code" maxlength="32" placeholder="Ex. GARENNOIS20" value="<?= htmlspecialchars((string) ($old['code'] ?? ''), ENT_QUOTES) ?>" required>
            <?php if ($editingId === null): ?><button type="button" id="promo-code-generate" class="btn-small btn-outline">Générer</button><?php endif; ?>
        </div>

        <fieldset>
            <legend>Réduction</legend>
            <label class="choice"><input type="radio" name="kind" value="percent" <?= ($old['kind'] ?? 'percent') === 'percent' ? 'checked' : '' ?>> Pourcentage du total</label>
            <label class="choice"><input type="radio" name="kind" value="fixed" <?= ($old['kind'] ?? '') === 'fixed' ? 'checked' : '' ?>> Montant fixe en euros</label>
            <label for="value">Valeur</label>
            <input type="number" id="value" name="value" min="0" max="100" step="0.01" value="<?= htmlspecialchars((string) ($old['value'] ?? ''), ENT_QUOTES) ?>" required>
        </fieldset>

        <label for="scope">Valable pour</label>
        <select id="scope" name="scope">
            <?php foreach ($scopes as $key => $label): ?>
                <option value="<?= htmlspecialchars($key, ENT_QUOTES) ?>" <?= ($old['scope'] ?? 'both') === $key ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
        </select>

        <label for="max_uses">Nombre d'utilisations maximum (laisser vide pour illimité)</label>
        <input type="number" id="max_uses" name="max_uses" min="1" step="1" placeholder="1" value="<?= htmlspecialchars((string) ($old['max_uses'] ?? ''), ENT_QUOTES) ?>">

        <label for="expires_at">Date d'expiration (laisser vide pour aucune)</label>
        <input type="date" id="expires_at" name="expires_at" value="<?= htmlspecialchars((string) ($old['expires_at'] ?? ''), ENT_QUOTES) ?>">

        <label for="note">Note (motif, usage prévu — visible uniquement en administration)</label>
        <input type="text" id="note" name="note" maxlength="255" value="<?= htmlspecialchars((string) ($old['note'] ?? ''), ENT_QUOTES) ?>">

        <button type="submit"><?= $editingId !== null ? 'Enregistrer les modifications' : 'Créer le code' ?></button>
        <?php if ($editingId !== null): ?><a href="/admin/codes-promo" class="btn btn-outline">Annuler</a><?php endif; ?>
    </form>
    <script src="/assets/promo-code-generator.js" defer></script>
<?php endif; ?>

<p>
<?php if ($archived): ?>
    <a href="/admin/codes-promo">← Codes actifs</a>
<?php else: ?>
    <a href="/admin/codes-promo/archivees">Voir les archives</a> · <a href="/admin">← Administration</a>
<?php endif; ?>
</p>
