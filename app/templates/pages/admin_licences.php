<?php
/**
 * @var array[] $users   flagged BJ users, each with licenceKind {kind, detail}, demarche, residence, couple
 * @var array{licence: string[], demarche: string[]} $filters  active filters, from the query string
 * @var ?int $registered  licences just marked registered (null when no action just ran)
 * @var int $failed       of those, how many BJ refused
 * @var App\Service\Season $season  the season shown — past members' leftover flags are left out
 * @var string $csrf
 *
 * Filters run in the page, with no reload: the list is loaded once and every
 * row carries its licence kind and démarche. Without JavaScript the bar stays
 * hidden and the full list and per-row buttons still work.
 */
$kindLabels = \App\Service\LicenceKinds::LABELS;
$demarcheLabels = ['creation' => 'Création', 'renouvellement' => 'Renouvellement'];
$groups = ['licence' => ['Licence', $kindLabels], 'demarche' => ['Démarche', $demarcheLabels]];
$counts = ['licence' => array_fill_keys(array_keys($kindLabels), 0), 'demarche' => array_fill_keys(array_keys($demarcheLabels), 0)];
foreach ($users as $u) {
    $counts['licence'][$u['licenceKind']['kind']]++;
    $counts['demarche'][$u['demarche']]++;
}
// Posted back by both forms so the redirect lands on the same filtered view.
$filterQuery = http_build_query(array_filter(array_map(static fn (array $v): string => implode(',', $v), $filters)));
$plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n > 1 ? $many : $one);
?>
<h1>Licences à enregistrer (⚑)</h1>
<p class="muted">Adhérents de la saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?> marqués « licence non enregistrée ».
    Une fois la licence créée ou renouvelée auprès de la fédération (dans Balle Jaune), levez le marquage ici.
    Les anciens adhérents qui n'ont pas renouvelé n'apparaissent pas, même si le marquage est resté dans Balle Jaune.
    Liste par ordre alphabétique.</p>

<?php if ($registered !== null && $registered > 0): ?>
    <div class="alert alert-ok">✔ <?= $plural($registered, 'licence marquée enregistrée', 'licences marquées enregistrées') ?>.</div>
<?php endif; ?>
<?php if ($failed > 0): ?>
    <div class="alert"><?= $plural($failed, 'licence n\'a', 'licences n\'ont') ?> pas pu être modifiée<?= $failed > 1 ? 's' : '' ?> :
        Balle Jaune n'a pas répondu. Elle<?= $failed > 1 ? 's sont restées' : ' est restée' ?> dans la liste, réessayez dans un instant.</div>
<?php endif; ?>

<?php if ($users === []): ?>
    <p>Aucune licence en attente. 🎉</p>
<?php else: ?>
    <div class="filter-bar" id="licence-filters" hidden>
        <?php foreach ($groups as $key => [$legend, $labels]): ?>
            <div>
                <span class="filter-bar-label"><?= htmlspecialchars($legend, ENT_QUOTES) ?></span>
                <div class="filter-bar-checks">
                    <?php foreach ($labels as $value => $label): ?>
                        <?php if ($counts[$key][$value] === 0) { continue; } ?>
                        <label class="choice"><input type="checkbox" data-filter="<?= $key ?>" value="<?= $value ?>"
                            <?= in_array($value, $filters[$key], true) ? 'checked' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES) ?> <span class="muted">(<?= $counts[$key][$value] ?>)</span></label>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
        <div>
            <button type="button" class="btn-outline btn-small" id="licence-filters-reset" hidden>Réinitialiser</button>
        </div>
    </div>

    <p aria-live="polite"><strong><?= count($users) ?></strong> licence<?= count($users) > 1 ? 's' : '' ?> à enregistrer
        — <span id="licence-shown"><?= count($users) ?></span> affichée(s).</p>

    <form method="post" id="bulk-form" action="/admin/licences/selection">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="filters" class="filters-field" value="<?= htmlspecialchars($filterQuery, ENT_QUOTES) ?>">
    </form>

    <div class="table-scroll">
    <table class="details" id="licence-table">
        <tr>
            <th><input type="checkbox" id="licence-select-all" aria-label="Sélectionner toutes les licences affichées"></th>
            <th>Nom</th><th>Naissance</th><th>Licence</th><th>Démarche</th><th></th>
        </tr>
        <?php foreach ($users as $u): ?>
            <?php $name = $u['lastname'] . ' ' . $u['firstname']; ?>
            <tr data-kind="<?= htmlspecialchars($u['licenceKind']['kind'], ENT_QUOTES) ?>" data-demarche="<?= $u['demarche'] ?>">
                <td><input type="checkbox" class="row-check" name="ids[]" value="<?= (int) $u['user_id'] ?>" form="bulk-form"
                    aria-label="Sélectionner <?= htmlspecialchars($name, ENT_QUOTES) ?>"></td>
                <td><?= $this->fetch('partials/member_name.php', ['name' => $name, 'bjUserId' => $u['user_id']]) ?><?= $this->fetch('partials/garennois_badge.php', ['residence' => $u['residence'] ?? '']) ?>
                    <?php if ($u['couple'] !== null): ?><br><?= $this->fetch('partials/couple_partner.php', ['partner' => $u['couple']['partner']]) ?><?php endif; ?></td>
                <?php $birthday = (string) ($u['birthday'] ?? ''); ?>
                <?php // Balle Jaune answers the MySQL zero date for a member with no
                      // birthday on file, and strtotime() turns that into 30/11/-0001. ?>
                <td><?= $birthday !== '' && !str_starts_with($birthday, '0000')
                        ? date('d/m/Y', strtotime($birthday)) . \App\Support\Age::suffix($birthday)
                        : '—' ?></td>
                <td><span class="nowrap"><?= htmlspecialchars($kindLabels[$u['licenceKind']['kind']], ENT_QUOTES) ?></span>
                    <?php if ($u['licenceKind']['detail'] !== ''): ?><br><span class="muted"><?= htmlspecialchars($u['licenceKind']['detail'], ENT_QUOTES) ?></span><?php endif; ?></td>
                <td><?= $demarcheLabels[$u['demarche']] ?>
                    <?php if ($u['demarche'] === 'renouvellement'): ?><br><span class="muted"><?= htmlspecialchars((string) $u['license_number'], ENT_QUOTES) ?></span><?php endif; ?></td>
                <td class="nowrap">
                    <form method="post" action="/admin/licences/<?= (int) $u['user_id'] ?>" class="form-inline">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                        <input type="hidden" name="filters" class="filters-field" value="<?= htmlspecialchars($filterQuery, ENT_QUOTES) ?>">
                        <button type="submit" class="btn-small">Licence enregistrée ✔</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <p id="licence-none" hidden>Aucune licence pour ces filtres.</p>

    <p class="form-inline">
        <button type="submit" form="bulk-form" id="licence-bulk-submit">Marquer enregistrées la sélection</button>
    </p>
    <p class="muted">Seules les licences cochées et affichées sont marquées : changer de filtre décoche les lignes masquées.</p>

    <script>
    (function () {
        var bar = document.getElementById('licence-filters');
        var boxes = [].slice.call(bar.querySelectorAll('input[data-filter]'));
        var rows = [].slice.call(document.querySelectorAll('#licence-table tr[data-kind]'));
        var selectAll = document.getElementById('licence-select-all');
        var bulk = document.getElementById('licence-bulk-submit');
        var reset = document.getElementById('licence-filters-reset');
        var fields = [].slice.call(document.querySelectorAll('.filters-field'));
        var check = function (row) { return row.querySelector('.row-check'); };
        var visibleRows = function () { return rows.filter(function (row) { return !row.hidden; }); };
        var chosen = function (group) {
            return boxes.filter(function (b) { return b.dataset.filter === group && b.checked; })
                .map(function (b) { return b.value; });
        };
        var selectedCount = function () {
            return visibleRows().filter(function (row) { return check(row).checked; }).length;
        };

        function refreshSelection() {
            var shown = visibleRows().length;
            var n = selectedCount();
            bulk.disabled = n === 0;
            bulk.textContent = 'Marquer enregistrées (' + n + ')';
            selectAll.checked = n > 0 && n === shown;
            selectAll.indeterminate = n > 0 && n < shown;
            selectAll.disabled = shown === 0;
        }

        function applyFilters() {
            var licence = chosen('licence');
            var demarche = chosen('demarche');
            rows.forEach(function (row) {
                // Within a group any checked option matches; across groups all must.
                var match = (licence.length === 0 || licence.indexOf(row.dataset.kind) !== -1)
                    && (demarche.length === 0 || demarche.indexOf(row.dataset.demarche) !== -1);
                row.hidden = !match;
                // Never act on a row the admin can no longer see.
                if (!match) { check(row).checked = false; }
            });
            var shown = visibleRows().length;
            document.getElementById('licence-shown').textContent = shown;
            document.getElementById('licence-none').hidden = shown !== 0;

            var params = new URLSearchParams();
            if (licence.length) { params.set('licence', licence.join(',')); }
            if (demarche.length) { params.set('demarche', demarche.join(',')); }
            var query = params.toString();
            // Also drops ?enregistrees= after the first view, so a reload doesn't repeat the message.
            var url = new URL(location.href);
            url.search = query;
            history.replaceState(null, '', url.href);
            fields.forEach(function (f) { f.value = query; });
            reset.hidden = query === '';
            refreshSelection();
        }

        boxes.forEach(function (b) { b.addEventListener('change', applyFilters); });
        reset.addEventListener('click', function () {
            boxes.forEach(function (b) { b.checked = false; });
            applyFilters();
        });
        selectAll.addEventListener('change', function () {
            var on = selectAll.checked;
            visibleRows().forEach(function (row) { check(row).checked = on; });
            refreshSelection();
        });
        rows.forEach(function (row) { check(row).addEventListener('change', refreshSelection); });
        bulk.addEventListener('click', function (e) {
            var n = selectedCount();
            if (!confirm('Marquer ' + n + ' licence' + (n > 1 ? 's' : '') + ' comme enregistrée' + (n > 1 ? 's' : '')
                    + ' auprès de la fédération ?')) {
                e.preventDefault();
            }
        });

        bar.hidden = false;
        applyFilters();
    })();
    </script>
<?php endif; ?>
<p><a href="/admin">← Administration</a></p>
