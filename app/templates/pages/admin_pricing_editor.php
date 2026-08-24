<?php
/**
 * @var string $label, $csrf, $title
 * @var array $catalogue, $errors
 * @var bool $isLive
 * @var bool $canUnpublish   true only for a published season that hasn't started yet
 * @var string[] $bjNames    "_"-prefixed simplified subscriptions — what catalogue subscriptions map to
 * @var string[] $allBjNames every BJ subscription — the ticket pack isn't "_"-prefixed
 */
$bjOptions = fn (string $current) => $current !== '' && !in_array($current, $bjNames, true) ? [...$bjNames, $current] : $bjNames;
$allBjOptions = fn (string $current) => $current !== '' && !in_array($current, $allBjNames, true) ? [...$allBjNames, $current] : $allBjNames;
$v = fn ($x) => htmlspecialchars((string) $x, ENT_QUOTES);
?>
<h1>Barème <?= $v($label) ?></h1>

<?php if ($isLive): ?>
    <p class="muted">Cette saison est publiée. Enregistrer crée un brouillon — la version publiée reste active pour les adhérents jusqu'à ce que vous publiiez le brouillon.</p>
<?php else: ?>
    <p class="muted">Brouillon — invisible des adhérents tant qu'il n'est pas publié.</p>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= $v($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="/admin/tarifs/<?= $v($label) ?>/enregistrer" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= $v($csrf) ?>">

    <fieldset>
        <legend>Licences</legend>
        <?php foreach (['pass' => 'Licence Pass', 'federale' => 'Licence fédérale', 'jeune' => 'Licence jeune', 'ete' => 'Licence été (découverte)'] as $key => $defaultLabel): ?>
            <?php $l = $catalogue['licences'][$key] ?? ['label' => $defaultLabel, 'price' => 0]; ?>
            <div class="grid2">
                <div><label>Libellé (<?= $key ?>)</label><input name="licences[<?= $key ?>][label]" value="<?= $v($l['label']) ?>" required></div>
                <div><label>Prix (€)</label><input type="number" step="0.01" min="0" name="licences[<?= $key ?>][price]" value="<?= $v($l['price']) ?>" required></div>
            </div>
        <?php endforeach; ?>
    </fieldset>

    <fieldset>
        <legend>Pack été</legend>
        <div class="grid2">
            <div><label>Libellé</label><input name="summer_pack[label]" value="<?= $v($catalogue['summer_pack']['label'] ?? 'Pack été') ?>" required></div>
            <div><label>Cotisation (€, tarif unique)</label><input type="number" step="0.01" min="0" name="summer_pack[cotisation]" value="<?= $v($catalogue['summer_pack']['cotisation'] ?? 0) ?>" required></div>
        </div>
        <p class="muted">S'ajoute à la licence été (voir « Licences » ci-dessus) — total facturé aux adhérents concernés : cotisation + licence(s).</p>
    </fieldset>

    <fieldset>
        <legend>Cours collectifs</legend>
        <div class="grid2">
            <div><label>Libellé</label><input name="lessons[label]" value="<?= $v($catalogue['lessons']['label'] ?? '') ?>" required></div>
            <div><label>Prix (€ / an / personne)</label><input type="number" step="0.01" min="0" name="lessons[price]" value="<?= $v($catalogue['lessons']['price'] ?? 0) ?>" required></div>
        </div>
    </fieldset>

    <fieldset>
        <legend>Formule Tickets</legend>
        <div class="grid2">
            <div><label>Libellé</label><input name="ticket_pack[label]" value="<?= $v($catalogue['ticket_pack']['label'] ?? '') ?>" required></div>
            <div><label>Nombre de séances</label><input type="number" min="1" name="ticket_pack[tickets]" value="<?= $v($catalogue['ticket_pack']['tickets'] ?? 5) ?>" required></div>
        </div>
        <div class="grid2">
            <div><label>Prix (€)</label><input type="number" step="0.01" min="0" name="ticket_pack[price]" value="<?= $v($catalogue['ticket_pack']['price'] ?? 0) ?>" required></div>
            <div><label>Abonnement Balle Jaune</label>
                <select name="ticket_pack[bj_subscription]" required>
                    <?php foreach ($allBjOptions((string) ($catalogue['ticket_pack']['bj_subscription'] ?? '')) as $name): ?>
                        <option value="<?= $v($name) ?>" <?= ($catalogue['ticket_pack']['bj_subscription'] ?? '') === $name ? 'selected' : '' ?>><?= $v($name) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </fieldset>

    <fieldset>
        <legend>Abonnements</legend>
        <div style="overflow-x: auto;">
        <table class="details">
            <tr>
                <th>Abonnement</th><th>Grille</th><th>Libellé</th><th>Abonnement Balle Jaune</th>
                <th>Garennois 1ère insc.</th><th>Garennois renouv.</th>
                <th>Hors commune 1ère insc.</th><th>Hors commune renouv.</th>
            </tr>
            <?php foreach ($catalogue['subscriptions'] as $key => $s): ?>
                <?php
                    $grids = ['individual' => 'Individuel'];
                    if (!empty($s['couple_available'])) {
                        $grids['couple'] = 'Couple';
                    }
                    $first = true;
                ?>
                <?php foreach ($grids as $gridKey => $gridLabel): ?>
                    <?php
                        $prices = $s[$gridKey] ?? [];
                        $garennois = $prices['garennois'] ?? null;
                        $horsCommune = $prices['hors-commune'] ?? null;
                    ?>
                    <tr>
                        <td>
                            <?php if ($first): ?>
                                <?= $v($key) ?><br>
                                <span class="muted"><?= $v($s['audience']) ?><?= !empty($s['couple_available']) ? ', couple disponible' : '' ?></span>
                                <input type="hidden" name="subscriptions[<?= $v($key) ?>][audience]" value="<?= $v($s['audience']) ?>">
                                <input type="hidden" name="subscriptions[<?= $v($key) ?>][couple_available]" value="<?= !empty($s['couple_available']) ? '1' : '0' ?>">
                                <?php $first = false; ?>
                            <?php endif; ?>
                        </td>
                        <td><?= $gridLabel ?></td>
                        <td>
                            <?php if ($gridKey === 'individual'): ?>
                                <input name="subscriptions[<?= $v($key) ?>][label]" value="<?= $v($s['label']) ?>" required style="min-width:14em;">
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($gridKey === 'individual'): ?>
                                <select name="subscriptions[<?= $v($key) ?>][bj_subscription]" required style="min-width:16em;">
                                    <?php foreach ($bjOptions($s['bj_subscription']) as $name): ?>
                                        <option value="<?= $v($name) ?>" <?= $s['bj_subscription'] === $name ? 'selected' : '' ?>><?= $v($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </td>
                        <td><input type="number" step="0.01" min="0" style="width:6em;" name="subscriptions[<?= $v($key) ?>][<?= $gridKey ?>][garennois][premiere]" value="<?= $v($garennois['premiere'] ?? '') ?>"></td>
                        <td><input type="number" step="0.01" min="0" style="width:6em;" name="subscriptions[<?= $v($key) ?>][<?= $gridKey ?>][garennois][renouvellement]" value="<?= $v($garennois['renouvellement'] ?? '') ?>"></td>
                        <td><input type="number" step="0.01" min="0" style="width:6em;" name="subscriptions[<?= $v($key) ?>][<?= $gridKey ?>][hors_commune][premiere]" value="<?= $v($horsCommune['premiere'] ?? '') ?>"></td>
                        <td><input type="number" step="0.01" min="0" style="width:6em;" name="subscriptions[<?= $v($key) ?>][<?= $gridKey ?>][hors_commune][renouvellement]" value="<?= $v($horsCommune['renouvellement'] ?? '') ?>"></td>
                    </tr>
                <?php endforeach; ?>
            <?php endforeach; ?>
        </table>
        </div>
        <p class="muted">Laisser les 2 champs « Hors commune » vides si l'abonnement n'est pas ouvert hors commune (ex. Midi). La licence n'est plus liée à l'abonnement : son tarif dépend uniquement du statut compétiteur (voir « Licences » ci-dessus).</p>
    </fieldset>

    <button type="submit">Enregistrer le brouillon</button>
</form>

<form method="post" action="/admin/tarifs/<?= $v($label) ?>/publier" class="form-inline">
    <input type="hidden" name="csrf" value="<?= $v($csrf) ?>">
    <button type="submit" class="btn-small">Publier cette saison</button>
</form>

<?php if ($canUnpublish): ?>
<form method="post" action="/admin/tarifs/<?= $v($label) ?>/depublier" class="form-inline">
    <input type="hidden" name="csrf" value="<?= $v($csrf) ?>">
    <button type="submit" class="btn-small btn-danger">Dépublier cette saison</button>
</form>
<p class="muted">Repasse la saison en brouillon — plus visible pour les renouvellements tant qu'elle n'est pas republiée.</p>
<?php endif; ?>

<div class="grid2">
    <div>
        <a href="/admin/tarifs/<?= $v($label) ?>/export.csv" class="btn-small">Exporter en CSV</a>
    </div>
    <div>
        <form method="post" action="/admin/tarifs/<?= $v($label) ?>/importer" enctype="multipart/form-data" class="form-inline">
            <input type="hidden" name="csrf" value="<?= $v($csrf) ?>">
            <input type="file" name="csv" accept=".csv,text/csv" required>
            <button type="submit" class="btn-small">Importer un CSV</button>
        </form>
    </div>
</div>

<p><a href="/admin/tarifs">← Barèmes tarifaires</a></p>
