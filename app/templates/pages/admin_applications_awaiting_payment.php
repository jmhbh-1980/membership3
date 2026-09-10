<?php
/**
 * @var array[] $rows {app, people, order: ?array}
 * @var string $csrf
 *
 * "Blocage" is the column that earns this page: 'awaiting_payment' collapses
 * several very different situations, and one of them (virement à confirmer) is
 * the club's own move, not the applicant's — an admin looking at this list needs
 * to know which rows are actually waiting on them.
 */
$now = new DateTimeImmutable();
$waitingDays = function (array $app) use ($now): int {
    $since = $app['validated_at'] ?: ($app['submitted_at'] ?: $app['created_at']);
    return (int) $now->diff(new DateTimeImmutable($since))->days;
};
$blockage = function (?array $order): array {
    if ($order === null) {
        return ['Lien de paiement non ouvert', 'muted'];
    }
    return match ((string) $order['status']) {
        'awaiting_bank_transfer'    => ['Virement à confirmer — action du club', 'strong'],
        'awaiting_promo_approval'   => ['Code promo à approuver — action du club', 'strong'],
        'awaiting_student_approval' => ['Statut étudiant à valider — action du club', 'strong'],
        default                     => ['Paiement commencé, non finalisé', 'muted'],
    };
};
?>
<h1>Approuvées, en attente de paiement</h1>
<p class="muted">Demandes validées par le club dont le paiement n'est pas encore encaissé.
    Une fois réglées, elles disparaissent d'ici et suivent leur cours dans
    <a href="/admin/commandes">Commandes</a>.</p>
<p><a href="/admin/demandes">← Demandes en attente</a>
   · <a href="/admin/demandes/abandonnees">Demandes abandonnées</a></p>

<?php if ($rows === []): ?>
    <p>Aucune demande en attente de paiement.</p>
    <p class="muted">Toutes les demandes approuvées ont été réglées — rien à relancer.</p>
<?php else: ?>
    <form method="post" id="bulk-form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    </form>
    <div class="table-scroll">
    <table class="details">
        <tr>
            <th><input type="checkbox" onclick="document.querySelectorAll('.row-check').forEach(c => c.checked = this.checked)"></th>
            <th>#</th><th>Adhérent(s)</th><th>Contact</th><th>Abonnement</th>
            <th>Blocage</th><th>Approuvée le</th><th>Attente</th><th></th>
        </tr>
        <?php foreach ($rows as $row): ?>
            <?php
                $app = $row['app'];
                $applicant = $row['people'][1] ?? null;
                [$blockLabel, $blockClass] = $blockage($row['order']);
                $days = $waitingDays($app);
            ?>
            <tr>
                <td><input type="checkbox" class="row-check" name="ids[]" value="<?= (int) $app['id'] ?>" form="bulk-form"></td>
                <td><?= (int) $app['id'] ?></td>
                <td>
                    <?php foreach ($row['people'] as $p): ?>
                        <?= htmlspecialchars($p['firstname'] . ' ' . $p['lastname'], ENT_QUOTES) ?><?= $p['is_minor'] ? ' (mineur)' : '' ?><br>
                    <?php endforeach; ?>
                    <?= $this->fetch('partials/garennois_badge.php', [
                        'residence' => $app['residence'] ?? '',
                        'pricingResidence' => $app['pricing_residence'] ?? '',
                    ]) ?>
                </td>
                <td>
                    <?php if ($applicant !== null): ?>
                        <?php $phoneLink = \App\Support\WhatsApp::link($applicant['phone']); ?>
                        <?= htmlspecialchars($applicant['email'], ENT_QUOTES) ?><br>
                        <?= $phoneLink !== null
                            ? '<a href="' . htmlspecialchars($phoneLink, ENT_QUOTES) . '" target="_blank" rel="noopener">' . htmlspecialchars($applicant['phone'], ENT_QUOTES) . '</a>'
                            : htmlspecialchars($applicant['phone'], ENT_QUOTES) ?>
                    <?php endif; ?>
                </td>
                <td><?= $app['subscription_type'] !== ''
                        ? htmlspecialchars($app['subscription_type'], ENT_QUOTES) . ($app['is_couple'] ? ' (couple)' : '')
                        : '<span class="muted">pas encore choisi</span>' ?></td>
                <td>
                    <?php if ($blockClass === 'strong'): ?>
                        <strong><?= htmlspecialchars($blockLabel, ENT_QUOTES) ?></strong>
                    <?php else: ?>
                        <span class="muted"><?= htmlspecialchars($blockLabel, ENT_QUOTES) ?></span>
                    <?php endif; ?>
                    <?php if ($row['order'] !== null): ?>
                        <br><a href="/admin/commandes/<?= (int) $row['order']['id'] ?>">commande #<?= (int) $row['order']['id'] ?></a>
                    <?php endif; ?>
                </td>
                <td><?= $app['validated_at'] ? date('d/m/Y', strtotime($app['validated_at'])) : '—' ?></td>
                <td><?= $days ?> j</td>
                <td>
                    <a class="btn btn-small" href="/admin/demandes/<?= (int) $app['id'] ?>">Voir</a>
                    <form method="post" action="/admin/demandes/<?= (int) $app['id'] ?>/relance-paiement" class="form-inline">
                        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                        <button type="submit" class="btn-small">Relancer</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <p class="form-inline">
        <button type="submit" form="bulk-form" formaction="/admin/demandes/attente-paiement/relancer" class="btn-small"
            onclick="if (!document.querySelector('.row-check:checked')) { alert('Sélectionnez au moins une demande.'); return false; }">
            Relancer la sélection
        </button>
    </p>
    <p class="muted">La relance renvoie le lien de paiement, pas le formulaire d'inscription —
        ces personnes ont déjà terminé leur demande.</p>
<?php endif; ?>
