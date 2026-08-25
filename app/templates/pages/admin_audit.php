<?php
/** @var array[] $entries  @var string[] $actions  @var string[] $entities  @var array $filters
 *  @var int $page  @var int $totalPages  @var int $total */
$entityHref = function (string $entity, string $entityId): ?string {
    return match ($entity) {
        'order'       => '/admin/commandes/' . $entityId,
        'application' => '/admin/demandes/' . $entityId,
        default       => null,
    };
};
$formatDetails = function (?string $json): string {
    if ($json === null || $json === '') {
        return '—';
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return htmlspecialchars($json, ENT_QUOTES);
    }
    $parts = [];
    foreach ($decoded as $key => $value) {
        $parts[] = htmlspecialchars((string) $key, ENT_QUOTES) . ': ' . htmlspecialchars(is_scalar($value) ? (string) $value : json_encode($value), ENT_QUOTES);
    }
    return implode(', ', $parts) ?: '—';
};
$pageHref = function (int $targetPage) use ($filters): string {
    return '?' . http_build_query(array_filter($filters + ['page' => $targetPage], fn ($v) => $v !== ''));
};
?>
<h1>Journal d'audit</h1>

<form method="get" class="form form-wide filters-inline">
    <fieldset>
        <legend>Filtres</legend>
        <div class="grid2">
            <div>
                <label for="actor">Acteur</label>
                <input id="actor" name="actor" value="<?= htmlspecialchars($filters['actor'], ENT_QUOTES) ?>" placeholder="email">
            </div>
            <div>
                <label for="action">Action</label>
                <select id="action" name="action">
                    <option value="">Toutes</option>
                    <?php foreach ($actions as $a): ?>
                        <option value="<?= htmlspecialchars($a, ENT_QUOTES) ?>" <?= $filters['action'] === $a ? 'selected' : '' ?>><?= htmlspecialchars($a, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="entity">Entité</label>
                <select id="entity" name="entity">
                    <option value="">Toutes</option>
                    <?php foreach ($entities as $e): ?>
                        <option value="<?= htmlspecialchars($e, ENT_QUOTES) ?>" <?= $filters['entity'] === $e ? 'selected' : '' ?>><?= htmlspecialchars($e, ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label for="from">Du</label>
                <input type="date" id="from" name="from" value="<?= htmlspecialchars($filters['from'], ENT_QUOTES) ?>">
            </div>
            <div>
                <label for="to">Au</label>
                <input type="date" id="to" name="to" value="<?= htmlspecialchars($filters['to'], ENT_QUOTES) ?>">
            </div>
        </div>
        <button type="submit" class="btn-small">Filtrer</button>
        <a href="/admin/journal-audit">Réinitialiser</a>
    </fieldset>
</form>

<?php if ($entries === []): ?>
    <p>Aucune entrée.</p>
<?php else: ?>
    <table class="details">
        <tr><th>Date</th><th>Acteur</th><th>Action</th><th>Entité</th><th>Détails</th></tr>
        <?php foreach ($entries as $e): ?>
            <tr>
                <td><?= date('d/m/Y H:i:s', strtotime($e['created_at'])) ?></td>
                <td><?= htmlspecialchars($e['actor'], ENT_QUOTES) ?></td>
                <td><?= htmlspecialchars($e['action'], ENT_QUOTES) ?></td>
                <td>
                    <?php $href = $entityHref($e['entity'], $e['entity_id']); ?>
                    <?php if ($href !== null): ?>
                        <a href="<?= htmlspecialchars($href, ENT_QUOTES) ?>"><?= htmlspecialchars($e['entity'], ENT_QUOTES) ?> #<?= htmlspecialchars($e['entity_id'], ENT_QUOTES) ?></a>
                    <?php else: ?>
                        <?= htmlspecialchars($e['entity'], ENT_QUOTES) ?><?= $e['entity_id'] !== '' ? ' #' . htmlspecialchars($e['entity_id'], ENT_QUOTES) : '' ?>
                    <?php endif; ?>
                </td>
                <td><?= $formatDetails($e['details']) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>

    <p>
        <?= $total ?> entrée<?= $total > 1 ? 's' : '' ?> — page <?= $page ?> / <?= $totalPages ?>
        <?php if ($page > 1): ?> · <a href="<?= htmlspecialchars($pageHref($page - 1), ENT_QUOTES) ?>">← précédente</a><?php endif; ?>
        <?php if ($page < $totalPages): ?> · <a href="<?= htmlspecialchars($pageHref($page + 1), ENT_QUOTES) ?>">suivante →</a><?php endif; ?>
    </p>
<?php endif; ?>

<p><a href="/admin">← Administration</a></p>
