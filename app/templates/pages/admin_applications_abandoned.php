<?php /** @var array[] $rows  @var string $csrf */ ?>
<h1>Demandes abandonnées</h1>
<p class="muted">Demandes d'adhésion commencées mais jamais envoyées au club (bloquées en brouillon).</p>
<p><a href="/admin/demandes">← Demandes en attente</a></p>

<?php if ($rows === []): ?>
    <p>Aucune demande abandonnée. 🎉</p>
<?php else: ?>
    <form method="post" id="bulk-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    </form>
    <table class="details">
        <tr>
            <th><input type="checkbox" onclick="document.querySelectorAll('.row-check').forEach(c => c.checked = this.checked)"></th>
            <th>#</th><th>Adhérent(s)</th><th>Contact</th><th>Abonnement</th>
            <th>Documents</th><th>Commencée le</th><th>Dernière activité</th><th></th>
        </tr>
        <?php foreach ($rows as $row): ?>
            <?php $applicant = $row['people'][1] ?? null; ?>
            <tr>
                <td><input type="checkbox" class="row-check" name="ids[]" value="<?= (int) $row['app']['id'] ?>" form="bulk-form"></td>
                <td><?= (int) $row['app']['id'] ?></td>
                <td>
                    <?php foreach ($row['people'] as $p): ?>
                        <?= htmlspecialchars($p['firstname'] . ' ' . $p['lastname'], ENT_QUOTES) ?><?= $p['is_minor'] ? ' (mineur)' : '' ?><br>
                    <?php endforeach; ?>
                    <?= $this->fetch('partials/garennois_badge.php', ['residence' => $row['app']['residence'] ?? '']) ?>
                </td>
                <td>
                    <?php if ($applicant !== null): ?>
                        <?php $phoneLink = \App\Support\WhatsApp::link($applicant['phone']); ?>
                        <?= htmlspecialchars($applicant['email'], ENT_QUOTES) ?><br>
                        <?= $phoneLink !== null ? '<a href="' . htmlspecialchars($phoneLink, ENT_QUOTES) . '" target="_blank" rel="noopener">💬 ' . htmlspecialchars($applicant['phone'], ENT_QUOTES) . '</a>' : htmlspecialchars($applicant['phone'], ENT_QUOTES) ?>
                    <?php endif; ?>
                </td>
                <td><?= $row['app']['subscription_type'] !== ''
                        ? htmlspecialchars($row['app']['subscription_type'], ENT_QUOTES) . ($row['app']['is_couple'] ? ' (couple)' : '')
                        : '<span class="muted">pas encore choisi</span>' ?></td>
                <td><?= (int) $row['documents'] ?></td>
                <td><?= date('d/m/Y H:i', strtotime($row['app']['created_at'])) ?></td>
                <td><?= date('d/m/Y H:i', strtotime($row['app']['updated_at'])) ?></td>
                <td>
                    <a class="btn btn-small" href="/admin/demandes/<?= (int) $row['app']['id'] ?>">Voir</a>
                    <form method="post" action="/admin/demandes/<?= (int) $row['app']['id'] ?>/relance" class="form-inline">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                        <button type="submit" class="btn-small">Relancer</button>
                    </form>
                    <form method="post" action="/admin/demandes/<?= (int) $row['app']['id'] ?>/effacer" class="form-inline">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                        <button type="submit" class="btn-small btn-danger" onclick="return confirm('Effacer définitivement cette demande et ses documents ? Cette action est irréversible.')">Effacer</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    <p class="form-inline">
        <button type="submit" form="bulk-form" formaction="/admin/demandes/abandonnees/relancer" class="btn-small"
            onclick="if (!document.querySelector('.row-check:checked')) { alert('Sélectionnez au moins une demande.'); return false; }">
            Relancer la sélection
        </button>
        <button type="submit" form="bulk-form" formaction="/admin/demandes/abandonnees/effacer" class="btn-small btn-danger"
            onclick="if (!document.querySelector('.row-check:checked')) { alert('Sélectionnez au moins une demande.'); return false; } return confirm('Effacer définitivement les demandes sélectionnées et leurs documents ? Cette action est irréversible.');">
            Effacer la sélection
        </button>
    </p>
<?php endif; ?>
