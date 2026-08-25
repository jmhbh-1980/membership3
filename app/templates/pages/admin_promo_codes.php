<?php
/** @var array[] $codes
 *  @var array $old, $errors
 */
$kinds = ['percent' => '%', 'fixed' => '€'];
$scopes = ['join' => 'Adhésion', 'renewal' => 'Renouvellement', 'both' => 'Les deux'];
?>
<h1>Codes promo</h1>
<p class="muted">Codes à usage ponctuel pour un cas particulier (réduction ou dispense de frais accordée par le club) — appliqués par l'adhérent au paiement de l'adhésion ou du renouvellement.</p>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($codes === []): ?>
    <p>Aucun code promo créé.</p>
<?php else: ?>
    <table class="details">
        <tr><th>Code</th><th>Réduction</th><th>Portée</th><th>Utilisations</th><th>Expire le</th><th>Statut</th><th>Créé par</th><th></th></tr>
        <?php foreach ($codes as $c): ?>
            <tr>
                <td><?= htmlspecialchars($c['code'], ENT_QUOTES) ?><?php if ($c['note'] !== ''): ?><br><span class="muted"><?= htmlspecialchars($c['note'], ENT_QUOTES) ?></span><?php endif; ?></td>
                <td><?= number_format((float) $c['value'], $c['kind'] === 'percent' ? 0 : 2, ',', ' ') ?> <?= $kinds[$c['kind']] ?? $c['kind'] ?></td>
                <td><?= $scopes[$c['scope']] ?? $c['scope'] ?></td>
                <td><?= (int) $c['usage'] ?><?= $c['max_uses'] !== null ? ' / ' . (int) $c['max_uses'] : '' ?></td>
                <td><?= $c['expires_at'] !== null ? date('d/m/Y', strtotime($c['expires_at'])) : '—' ?></td>
                <td><?= ((int) $c['active'] === 1) ? 'Actif' : 'Désactivé' ?></td>
                <td><?= htmlspecialchars($c['created_by'], ENT_QUOTES) ?></td>
                <td>
                    <form method="post" action="/admin/codes-promo/<?= (int) $c['id'] ?>/<?= (int) $c['active'] === 1 ? 'desactiver' : 'activer' ?>" class="form-inline">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                        <button type="submit" class="btn-small"><?= (int) $c['active'] === 1 ? 'Désactiver' : 'Activer' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php endif; ?>

<h2>Nouveau code</h2>
<form method="post" action="/admin/codes-promo/nouveau" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <label for="code">Code</label>
    <input type="text" id="code" name="code" maxlength="32" placeholder="Ex. GARENNOIS20" value="<?= htmlspecialchars((string) ($old['code'] ?? ''), ENT_QUOTES) ?>" required>

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

    <button type="submit">Créer le code</button>
</form>

<p><a href="/admin">← Administration</a></p>
