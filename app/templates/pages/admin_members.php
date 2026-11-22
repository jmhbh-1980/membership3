<?php
/**
 * @var string $search, $csrf
 * @var bool $searched  the form was sent (an empty search lists every account)
 * @var App\Service\Season[] $seasons  the season filter's choices, newest first
 * @var ?App\Service\Season $seasonFilter  only members covered for this season; null = every season
 * @var array[] $users  BJ users, each with licenceKind {kind, detail}, federationMissing, federationBlocker and
 *                      federationExportable (what the FFSquash file can carry — see AdminOpsController::withFederationExport())
 * @var array $namesById, $validSeasons
 * @var bool $exportEmpty  an export was asked and nothing among the checked rows could go in the file
 * @var App\Service\Season $season  the season the federation file is for
 */
$kindLabels = \App\Service\LicenceKinds::LABELS;
?>
<h1>Adhérents</h1>

<?php if ($exportEmpty): ?>
    <div class="alert">Aucun des adhérents cochés ne peut partir dans le fichier de la fédération :
        pas adhérent de la saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?>, licence retirée, type non déterminé,
        ou informations manquantes dans Balle Jaune (indiqué sur la ligne).</div>
<?php endif; ?>

<form method="get" class="form">
    <label for="q">Recherche (nom, email, licence, ville…)</label>
    <input id="q" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" autofocus aria-describedby="q-hint">
    <span class="muted" id="q-hint">Laissez vide pour afficher tout le monde.</span>
    <label for="saison">Saison</label>
    <select id="saison" name="saison">
        <option value="">Toutes les saisons</option>
        <?php foreach ($seasons as $s): ?>
            <option value="<?= $s->startYear ?>" <?= $seasonFilter?->startYear === $s->startYear ? 'selected' : '' ?>>
                <?= htmlspecialchars($s->label(), ENT_QUOTES) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit" class="btn-small">Rechercher</button>
</form>

<?php
$scope = array_filter([
    $seasonFilter !== null ? 'saison ' . $seasonFilter->label() : null,
    $search !== '' ? 'recherche « ' . $search . ' »' : null,
]);
?>
<?php if ($searched): ?>
    <?php if ($users === []): ?>
        <p>Aucun adhérent<?= $scope !== [] ? ' pour : ' . htmlspecialchars(implode(', ', $scope), ENT_QUOTES) : '' ?>.</p>
    <?php else: ?>
        <p aria-live="polite"><strong><?= count($users) ?></strong> résultat<?= count($users) > 1 ? 's' : '' ?>
            <?= $scope !== [] ? '— ' . htmlspecialchars(implode(', ', $scope), ENT_QUOTES) : '— tous les comptes Balle Jaune' ?>.</p>

        <?php // Empty on purpose: the checkboxes below point at it with form="…", since each row already holds its own forms. ?>
        <form method="post" id="export-form" action="/admin/membres/export-federation">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>">
            <input type="hidden" name="saison" value="<?= $seasonFilter?->startYear ?? '' ?>">
        </form>

        <div class="table-scroll">
        <table class="details" id="member-table">
            <tr><th><input type="checkbox" id="member-select-all" aria-label="Sélectionner tous les adhérents exportables"></th>
                <th>Nom</th><th>Email</th><th>Abonnement</th><th>Fin</th><th>Payé</th><th>Saisons validées</th><th>Licence</th><th>Crédits</th><th></th></tr>
            <?php foreach ($users as $u): ?>
                <?php $vs = $validSeasons[(int) $u['user_id']] ?? ['seasons' => [], 'mismatch' => false]; ?>
                <?php $name = $u['lastname'] . ' ' . $u['firstname']; ?>
                <tr>
                    <td><input type="checkbox" class="member-check" name="ids[]" value="<?= (int) $u['user_id'] ?>" form="export-form"
                        <?= $u['federationExportable'] ? '' : 'disabled' ?>
                        aria-label="Exporter <?= htmlspecialchars($name, ENT_QUOTES) ?> pour la fédération"></td>
                    <td><a href="/admin/membres/<?= (int) $u['user_id'] ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></a><?= $this->fetch('partials/garennois_badge.php', [
                        'residence' => $u['residence'] ?? '',
                        'pricingResidence' => $u['pricingResidence'] ?? '',
                    ]) ?>
                        <?php if ($u['couple'] !== null): ?><br><?= $this->fetch('partials/couple_partner.php', ['partner' => $u['couple']['partner']]) ?><?php endif; ?></td>
                    <td><?= htmlspecialchars($u['email'], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($namesById[(int) $u['subscription_id']] ?? '—', ENT_QUOTES) ?></td>
                    <td><?= ($u['subscription_date_end'] ?? '') !== '' && $u['subscription_date_end'] !== '0000-00-00' ? date('d/m/Y', strtotime($u['subscription_date_end'])) : '—' ?></td>
                    <td><?= ($u['subscription_paid'] ?? 0) ? '✔' : '✗' ?></td>
                    <td><?= $vs['seasons'] !== [] ? htmlspecialchars(implode(', ', $vs['seasons']), ENT_QUOTES) : '—' ?>
                        <?php if ($vs['mismatch']): ?>
                            <span class="muted" title="La date de fin BJ ne couvre pas la saison la plus récente enregistrée par l'appli — a probablement été modifiée manuellement dans Balle Jaune.">⚠</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($u['license_number'] !== '' ? $u['license_number'] : '—', ENT_QUOTES) ?><?= !empty($u['flag']) ? ' ⚑' : '' ?>
                        <br><span class="muted"><?= htmlspecialchars($kindLabels[$u['licenceKind']['kind']], ENT_QUOTES) ?><?=
                            $u['licenceKind']['detail'] !== '' ? ' — ' . htmlspecialchars($u['licenceKind']['detail'], ENT_QUOTES) : '' ?></span>
                        <?php if ($u['federationBlocker'] === 'saison'): ?>
                            <br><span class="muted">pas adhérent <?= htmlspecialchars($season->label(), ENT_QUOTES) ?> — hors fichier fédération</span>
                        <?php elseif ($u['federationBlocker'] === 'infos'): ?>
                            <br><span class="badge-tag badge-warning">hors fichier fédération — manque : <?= htmlspecialchars(implode(', ', $u['federationMissing']), ENT_QUOTES) ?></span>
                        <?php endif; ?></td>
                    <td><?= number_format((float) ($u['book_card_tickets'] ?? 0), 0, ',', ' ') ?></td>
                    <td>
                        <?php if (empty($u['isAdmin'])): ?>
                        <form method="post" action="/admin/membres/<?= (int) $u['user_id'] ?>/voir-comme">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                            <button type="submit" class="btn-small">Voir comme</button>
                        </form>
                        <?php endif; ?>
                        <a href="/admin/exceptions-tarif/membre/<?= (int) $u['user_id'] ?>">Exception de tarif</a>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <p class="muted">⚑ = licence à enregistrer auprès de la fédération. ⚠ = la fin d'abonnement BJ ne couvre pas la saison enregistrée par l'appli.</p>

        <p class="form-inline">
            <button type="submit" form="export-form" class="btn-outline" id="member-export-submit" data-download>Exporter la sélection pour la fédération</button>
        </p>
        <p class="muted">Même fichier que sur la page « Licences à enregistrer », pour un adhérent qui n'y figure pas (marquage déjà levé, ou jamais posé).
            Pour toute une saison : recherche vide, la saison choisie dans la liste, puis la case en tête du tableau.
            Un fichier par type de licence, 100 lignes au plus chacun, plusieurs fichiers arrivent ensemble dans un zip. Ne peuvent pas être cochés :
            les adhérents qui ne le sont pas pour la saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?>, les licences retirées ou non déterminées,
            et les adhérents à qui il manque une information obligatoire dans Balle Jaune (signalés sur leur ligne).
            L'export ne modifie rien dans Balle Jaune ni ne lève aucun marquage.<br>
            Toute personne qui encadre des mineurs (éducateur, dirigeant, accompagnateur…) est soumise au contrôle d'honorabilité
            et doit être saisie individuellement sur l'extranet : ne l'exportez pas ici.</p>

        <script>
        (function () {
            var boxes = [].slice.call(document.querySelectorAll('#member-table .member-check'));
            var selectAll = document.getElementById('member-select-all');
            var button = document.getElementById('member-export-submit');
            var enabled = boxes.filter(function (b) { return !b.disabled; });

            function refresh() {
                var n = enabled.filter(function (b) { return b.checked; }).length;
                button.disabled = n === 0;
                button.textContent = 'Exporter pour la fédération (' + n + ')';
                selectAll.checked = n > 0 && n === enabled.length;
                selectAll.indeterminate = n > 0 && n < enabled.length;
                selectAll.disabled = enabled.length === 0;
            }

            selectAll.addEventListener('change', function () {
                enabled.forEach(function (b) { b.checked = selectAll.checked; });
                refresh();
            });
            enabled.forEach(function (b) { b.addEventListener('change', refresh); });
            refresh();
        })();
        </script>
    <?php endif; ?>
<?php endif; ?>
<p><a href="/admin">← Administration</a></p>
