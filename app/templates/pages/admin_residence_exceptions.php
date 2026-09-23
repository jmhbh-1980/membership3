<?php
/**
 * @var array[] $rows     {exception, name, residence, cost: ?array, creditNote: ?array, settled: bool,
 *                         couple: ?array{partner: ?array}, costCountedWith: ?string}
 * @var App\Service\Season $season
 * @var App\Service\Season[] $seasons
 * @var float $total
 */
$money = fn (float $v): string => number_format($v, 2, ',', ' ') . ' €';
$gridLabel = fn (string $r): string => $r === 'garennois' ? 'Garennois' : 'Hors commune';
$active = array_filter($rows, fn (array $r): bool => $r['exception']['revoked_at'] === null);
?>
<h1>Exceptions de tarif — saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?></h1>
<p class="muted">Tarif accordé à titre exceptionnel, indépendamment du code postal. Une exception vaut
    <strong>pour une seule saison</strong> : elle doit être réaccordée délibérément chaque année.
    Le club décide hors de l'application — un membre ne peut pas en faire la demande ici.</p>

<form method="get" class="form form-wide filters-inline">
    <fieldset>
        <legend>Saison</legend>
        <label for="saison">Saison</label>
        <select id="saison" name="saison" onchange="this.form.submit()">
            <?php foreach ($seasons as $s): ?>
                <option value="<?= $s->startYear ?>" <?= $s->startYear === $season->startYear ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s->label(), ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn-small">Afficher</button></noscript>
    </fieldset>
</form>

<?php if ($rows === []): ?>
    <p>Aucune exception accordée pour cette saison.</p>
    <p class="muted">Pour en accorder une, ouvrez la fiche du membre depuis
        <a href="/admin/membres">l'annuaire des membres</a> ou depuis
        <a href="/admin/campagne">la campagne de renouvellement</a>.</p>
<?php else: ?>
    <div class="table-scroll">
    <table class="details">
        <tr>
            <th>Membre</th><th>Résidence</th><th>Tarif accordé</th><th>Motif</th>
            <th>Accordée par</th><th>Coût</th><th>Avoir</th><th></th>
        </tr>
        <?php foreach ($rows as $row): ?>
            <?php $e = $row['exception']; $revoked = $e['revoked_at'] !== null; ?>
            <tr<?= $revoked ? ' class="muted"' : '' ?>>
                <td><?= $this->fetch('partials/member_name.php', ['name' => $row['name'], 'bjUserId' => $e['bj_user_id']]) ?>
                    <?= $this->fetch('partials/garennois_badge.php', [
                        'residence' => $row['residence'],
                        'pricingResidence' => $revoked ? $row['residence'] : $e['pricing_residence'],
                    ]) ?>
                    <?php if ($row['couple'] !== null): ?><br><?= $this->fetch('partials/couple_partner.php', ['partner' => $row['couple']['partner']]) ?><?php endif; ?></td>
                <td><?= htmlspecialchars($gridLabel((string) $row['residence']), ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($gridLabel((string) $e['pricing_residence']), ENT_QUOTES) ?>
                    <?= $revoked ? '<br><span class="muted">révoquée le ' . date('d/m/Y', strtotime((string) $e['revoked_at'])) . '</span>' : '' ?></td>
                <td><?= htmlspecialchars((string) $e['reason'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars((string) $e['granted_by'], ENT_QUOTES) ?><br>
                    <span class="muted"><?= date('d/m/Y', strtotime((string) $e['granted_at'])) ?></span></td>
                <td>
                    <?php if (!$row['settled']): ?>
                        <span class="muted">pas encore renouvelé</span>
                    <?php elseif ($row['costCountedWith'] !== null): ?>
                        <span class="muted">même commande de couple, comptée avec
                            <?= htmlspecialchars($row['costCountedWith'], ENT_QUOTES) ?></span>
                    <?php elseif ($row['cost']['existing'] !== null || $row['cost']['eligible']): ?>
                        <?= htmlspecialchars($money((float) $row['cost']['amount']), ENT_QUOTES) ?>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if ($row['creditNote'] !== null): ?>
                        <?= htmlspecialchars((string) $row['creditNote']['number'], ENT_QUOTES) ?>
                    <?php elseif ($row['settled'] && !$revoked && $row['cost']['eligible']): ?>
                        <span class="muted">à émettre</span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><a href="/admin/exceptions-tarif/membre/<?= (int) $e['bj_user_id'] ?>?saison=<?= $season->startYear ?>">Ouvrir</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>

    <p><strong><?= count($active) ?></strong> exception<?= count($active) > 1 ? 's' : '' ?> en vigueur —
        coût pour la saison : <strong><?= htmlspecialchars($money($total), ENT_QUOTES) ?></strong>
        <span class="muted">(différence entre le tarif réglé et le tarif accordé, pour les membres ayant déjà réglé ;
            une commande de couple n'est comptée qu'une fois).</span></p>
<?php endif; ?>

<p><a href="/admin">← Administration</a></p>
