<?php /** @var array[] $orders, string $csrf, bool $archived, ?int $archivedCount
 * @var array{kind: ?string, status: ?string, dateFrom: ?string, dateTo: ?string, items: string[]} $filters
 * @var string $sort, $dir
 */
$kinds = ['join' => 'Adhésion', 'renewal' => 'Renouvellement', 'credits' => 'Crédits', 'change' => 'Changement', 'lessons' => 'Cours collectifs'];
$itemFilterOptions = [
    'cotisation'       => 'Cotisation',
    'licence-pass'     => 'Licence — Pass',
    'licence-federale' => 'Licence — Fédérale',
    'licence-jeune'    => 'Licence — Jeune',
    'licence-ete'      => 'Licence — Été',
    'lessons'          => 'Cours collectifs',
    'tickets'          => 'Tickets / crédits',
    'discount'         => 'Réduction',
];
$statuses = [
    'awaiting_promo_approval'  => 'Code promo en attente',
    'awaiting_bank_transfer'   => 'Virement en attente',
    'awaiting_student_approval' => 'Statut étudiant en attente',
    'pending'    => 'En attente',
    'paid'       => 'Paiement reçu',
    'fulfilling' => 'En cours',
    'fulfilled'  => 'Payée',
    'failed'     => 'Échouée',
    'canceled'   => 'Annulée',
    'refunded'   => 'Remboursée',
    'processed'  => 'Traitée',
];
$archivedStatuses = ['canceled', 'refunded', 'processed'];
$kindFilterOptions = ['join' => $kinds['join'], 'renewal' => $kinds['renewal'], 'credits' => $kinds['credits'], 'lessons' => $kinds['lessons']];
$statusFilterOptions = $archived
    ? array_intersect_key($statuses, array_flip($archivedStatuses))
    : array_diff_key($statuses, array_flip($archivedStatuses));
$isDuplicate = function (array $o): bool {
    $meta = json_decode((string) ($o['meta'] ?? '{}'), true) ?: [];
    return !empty($meta['duplicateFulfillment']);
};
$hasBadge = fn (array $o): bool => in_array($o['residence'] ?? '', ['garennois', 'hors-commune'], true);
$hasFilters = $filters['kind'] !== null || $filters['status'] !== null || $filters['dateFrom'] !== null || $filters['dateTo'] !== null || $filters['items'] !== [];
$sortLink = function (string $column, string $label) use ($filters, $sort, $dir): string {
    $isActive = $sort === $column;
    $params = array_filter([
        'kind'      => $filters['kind'],
        'items'     => $filters['items'],
        'status'    => $filters['status'],
        'date_from' => $filters['dateFrom'],
        'date_to'   => $filters['dateTo'],
        'sort'      => $column,
        'dir'       => $isActive && $dir === 'asc' ? 'desc' : 'asc',
    ], fn ($v) => $v !== null && $v !== '' && $v !== []);
    $arrow = $isActive ? ($dir === 'asc' ? ' ▲' : ' ▼') : '';
    return '<a href="?' . htmlspecialchars(http_build_query($params), ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . $arrow . '</a>';
};
?>
<h1><?= $archived ? 'Commandes archivées' : 'Commandes' ?></h1>

<form method="get" class="filter-bar">
    <div>
        <label for="f-kind">Type</label>
        <select id="f-kind" name="kind">
            <option value="">Tous</option>
            <?php foreach ($kindFilterOptions as $k => $label): ?>
                <option value="<?= htmlspecialchars($k, ENT_QUOTES) ?>" <?= $filters['kind'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <label for="f-status">Statut</label>
        <select id="f-status" name="status">
            <option value="">Tous</option>
            <?php foreach ($statusFilterOptions as $s => $label): ?>
                <option value="<?= htmlspecialchars($s, ENT_QUOTES) ?>" <?= $filters['status'] === $s ? 'selected' : '' ?>><?= htmlspecialchars($label, ENT_QUOTES) ?></option>
            <?php endforeach; ?>
        </select>
    </div>

    <div>
        <span class="filter-bar-label">Contient</span>
        <div class="filter-bar-checks">
            <?php foreach ($itemFilterOptions as $t => $label): ?>
                <label class="choice"><input type="checkbox" name="items[]" value="<?= htmlspecialchars($t, ENT_QUOTES) ?>" <?= in_array($t, $filters['items'], true) ? 'checked' : '' ?>> <?= htmlspecialchars($label, ENT_QUOTES) ?></label>
            <?php endforeach; ?>
        </div>
    </div>

    <div>
        <label for="f-date-from">Finalisée du</label>
        <input type="date" id="f-date-from" name="date_from" value="<?= htmlspecialchars($filters['dateFrom'] ?? '', ENT_QUOTES) ?>">
    </div>

    <div>
        <label for="f-date-to">au</label>
        <input type="date" id="f-date-to" name="date_to" value="<?= htmlspecialchars($filters['dateTo'] ?? '', ENT_QUOTES) ?>">
    </div>

    <div>
        <button type="submit" class="btn-small">Filtrer</button>
    </div>
    <?php if ($hasFilters): ?>
        <div>
            <a href="<?= $archived ? '/admin/commandes/archivees' : '/admin/commandes' ?>" class="btn btn-outline btn-small">Réinitialiser</a>
        </div>
    <?php endif; ?>
</form>

<?php if ($orders === []): ?>
    <p><?= $hasFilters ? 'Aucune commande pour ces filtres.' : ($archived ? 'Aucune commande archivée.' : 'Aucune commande.') ?></p>
<?php else: ?>
    <?php if (!$archived): ?>
        <form method="post" id="bulk-form">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        </form>
    <?php endif; ?>
    <div class="table-scroll">
    <table class="details">
        <tr>
            <?php if (!$archived): ?>
                <th><input type="checkbox" onclick="document.querySelectorAll('.row-check').forEach(c => c.checked = this.checked)"></th>
            <?php endif; ?>
            <th><?= $sortLink('id', '#') ?></th>
            <th><?= $sortLink('kind', 'Type') ?></th>
            <th>Nom</th>
            <th><?= $sortLink('amount', 'Montant') ?></th>
            <th><?= $sortLink('status', 'Statut') ?></th>
            <th><?= $sortLink('fulfilled_at', 'Finalisée le') ?></th>
            <?php if (!$archived): ?><th></th><?php endif; ?>
            <th></th>
        </tr>
        <?php foreach ($orders as $o): ?>
            <tr>
                <?php if (!$archived): ?>
                    <td><input type="checkbox" class="row-check" name="ids[]" value="<?= (int) $o['id'] ?>" form="bulk-form"></td>
                <?php endif; ?>
                <td class="nowrap"><?= (int) $o['id'] ?></td>
                <td><?= $kinds[$o['kind']] ?? $o['kind'] ?></td>
                <td>
                    <?php if ($o['lastname'] === '' && $o['firstname'] === ''): ?>
                        —
                    <?php else: ?>
                        <?= htmlspecialchars($o['lastname'], ENT_QUOTES) ?><br>
                        <?= htmlspecialchars($o['firstname'], ENT_QUOTES) ?>
                    <?php endif; ?>
                    <?php if ($hasBadge($o)): ?><br><?= $this->fetch('partials/garennois_badge.php', ['residence' => $o['residence']]) ?><?php endif; ?>
                </td>
                <td class="nowrap"><?= number_format((float) $o['amount'], 2, ',', ' ') ?> €</td>
                <td class="nowrap"><?= $statuses[$o['status']] ?? $o['status'] ?>
                    <?php if ($isDuplicate($o)): ?><span class="badge-tag badge-warning" title="Paiement en double — remboursement probablement nécessaire">⚠ doublon</span><?php endif; ?>
                </td>
                <td><?= $o['fulfilled_at'] !== null ? date('d/m/Y H:i', strtotime($o['fulfilled_at'])) : '—' ?></td>
                <?php if (!$archived): ?>
                    <td>
                        <?php if ($o['status'] === 'awaiting_promo_approval'): ?>
                            <a href="/admin/codes-promo/approbations" class="btn btn-small btn-order-action">Traiter le code promo</a>
                        <?php elseif ($o['status'] === 'awaiting_bank_transfer'): ?>
                            <a href="/admin/virements" class="btn btn-small btn-order-action">Traiter le virement</a>
                        <?php elseif ($o['status'] === 'awaiting_student_approval'): ?>
                            <a href="/admin/reduction-etudiant" class="btn btn-small btn-order-action">Traiter le statut étudiant</a>
                        <?php else: ?>
                            <form method="post" action="/admin/commandes/<?= (int) $o['id'] ?>/annuler" class="form-inline" onsubmit="return confirm('Marquer cette commande comme annulée ? Elle sera archivée et exclue des totaux financiers.');">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                                <button type="submit" class="btn-small btn-order-action">Annuler</button>
                            </form>
                        <?php endif; ?>
                        <?php if ($o['status'] === 'fulfilled'): ?>
                            <form method="post" action="/admin/commandes/<?= (int) $o['id'] ?>/rembourser" class="form-inline" onsubmit="return confirm('Marquer cette commande comme remboursée ? Elle sera archivée et comptera comme un remboursement.');">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                                <button type="submit" class="btn-small btn-order-action">Rembourser</button>
                            </form>
                            <form method="post" action="/admin/commandes/<?= (int) $o['id'] ?>/traiter" class="form-inline" onsubmit="return confirm('Marquer cette commande comme traitée et l\'archiver ?');">
                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                                <button type="submit" class="btn-small btn-order-action">Marquer traitée</button>
                            </form>
                        <?php endif; ?>
                    </td>
                <?php endif; ?>
                <td class="nowrap"><a href="/admin/commandes/<?= (int) $o['id'] ?>">Détails</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php if (!$archived): ?>
        <p class="form-inline">
            <button type="submit" form="bulk-form" formaction="/admin/commandes/selection/annuler" class="btn-small"
                onclick="if (!document.querySelector('.row-check:checked')) { alert('Sélectionnez au moins une commande.'); return false; } return confirm('Marquer les commandes sélectionnées comme annulées ? Elles seront archivées et exclues des totaux financiers.');">
                Annuler la sélection
            </button>
            <button type="submit" form="bulk-form" formaction="/admin/commandes/selection/rembourser" class="btn-small"
                onclick="if (!document.querySelector('.row-check:checked')) { alert('Sélectionnez au moins une commande.'); return false; } return confirm('Marquer les commandes sélectionnées comme remboursées ?');">
                Rembourser la sélection
            </button>
            <button type="submit" form="bulk-form" formaction="/admin/commandes/selection/traiter" class="btn-small"
                onclick="if (!document.querySelector('.row-check:checked')) { alert('Sélectionnez au moins une commande.'); return false; } return confirm('Marquer les commandes sélectionnées comme traitées et les archiver ?');">
                Marquer traitée la sélection
            </button>
        </p>
        <p class="muted">Seules les commandes éligibles dans la sélection sont affectées (ex. « Rembourser » n'agit que sur celles déjà payées) — les autres sont ignorées silencieusement.</p>
    <?php endif; ?>
<?php endif; ?>
<?php if ($archived): ?>
    <p><a href="/admin/commandes">← Commandes actives</a></p>
<?php else: ?>
    <p><a href="/admin/commandes/archivees">Voir les archives (<?= (int) $archivedCount ?>)</a></p>
<?php endif; ?>
<p><a href="/admin">← Administration</a></p>
