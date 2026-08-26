<?php
/**
 * @var array $app, $people, $documents, $attestations
 * @var ?App\Service\Quote $quote
 */
$docUrl = fn (string $stored) => '/admin/demandes/' . (int) $app['id'] . '/document/' . rawurlencode($stored);
?>
<h1>Demande #<?= (int) $app['id'] ?> — <?= htmlspecialchars($app['status'], ENT_QUOTES) ?></h1>

<?php foreach ($people as $p): ?>
    <?php if ($p['licence_removed']): ?>
        <div class="alert">⚠ <?= htmlspecialchars($p['firstname'], ENT_QUOTES) ?> demande le retrait de la licence — motif : <?= htmlspecialchars($p['licence_removal_reason'], ENT_QUOTES) ?></div>
    <?php endif; ?>
<?php endforeach; ?>

<h2>Adhérent<?= count($people) > 1 ? 's' : '' ?><?= $this->fetch('partials/garennois_badge.php', ['residence' => $app['residence'] ?? '']) ?></h2>
<table class="details">
    <?php foreach ($people as $position => $p): ?>
        <tr>
            <th><?= htmlspecialchars($p['firstname'] . ' ' . $p['lastname'], ENT_QUOTES) ?></th>
            <td>
                Né(e) le <?= date('d/m/Y', strtotime($p['birthdate'])) ?><?= $p['is_minor'] ? ' — mineur(e)' : '' ?><?= $p['competitor'] ? ' — compétiteur' : '' ?><?= $p['licence_removed'] ? ' — licence retirée (' . htmlspecialchars($p['licence_removal_reason'], ENT_QUOTES) . ')' : '' ?><br>
                <?= htmlspecialchars($p['email'], ENT_QUOTES) ?> · <?= htmlspecialchars($p['phone'], ENT_QUOTES) ?><br>
                <?= htmlspecialchars($p['address'] . ', ' . $p['postalcode'] . ' ' . $p['city'], ENT_QUOTES) ?>
                <?php if ($p['is_minor']): ?>
                    <br>Représentant légal : <?= htmlspecialchars($p['guardian_fullname'], ENT_QUOTES) ?>
                    (<?= htmlspecialchars($p['guardian_email'], ENT_QUOTES) ?>, <?= htmlspecialchars($p['guardian_phone'], ENT_QUOTES) ?>)
                <?php endif; ?>
                <?php if (isset($attestations[$position])): ?>
                    <br>Santé :
                    <?php if ($attestations[$position]['outcome'] === 'all_negative'): ?>
                        <a href="<?= $docUrl($attestations[$position]['pdf_stored_name']) ?>" target="_blank">attestation signée ✔</a>
                        (le <?= date('d/m/Y H:i', strtotime($attestations[$position]['signed_at'])) ?>)
                    <?php else: ?>
                        certificat médical fourni ✔
                    <?php endif; ?>
                <?php endif; ?>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<h2>Abonnement &amp; documents</h2>
<table class="details">
    <tr><th>Abonnement</th><td><?= htmlspecialchars($app['subscription_type'], ENT_QUOTES) ?><?= $app['is_couple'] ? ' — couple' : '' ?> — tarif <?= htmlspecialchars($app['residence'], ENT_QUOTES) ?>,
        saison <?= (int) $app['season_start_year'] ?>-<?= (int) $app['season_start_year'] + 1 ?><?= (int) $app['lessons_count'] > 0 ? ' — cours collectifs × ' . (int) $app['lessons_count'] : '' ?></td></tr>
    <?php foreach ($documents as $doc): ?>
        <tr>
            <th><?= ['photo' => 'Photo', 'justificatif' => 'Justificatif de domicile', 'medical_certificate' => 'Certificat médical'][$doc['kind']] ?>
                <?= count($people) > 1 ? '(' . htmlspecialchars($people[$doc['person_position']]['firstname'] ?? '?', ENT_QUOTES) . ')' : '' ?></th>
            <td>
                <?php if (str_starts_with($doc['mime'], 'image/')): ?>
                    <a href="<?= $docUrl($doc['stored_name']) ?>" target="_blank">
                        <img class="doc-preview" src="<?= $docUrl($doc['stored_name']) ?>" alt="<?= htmlspecialchars($doc['original_name'], ENT_QUOTES) ?>">
                    </a><br>
                <?php endif; ?>
                <a href="<?= $docUrl($doc['stored_name']) ?>" target="_blank"><?= htmlspecialchars($doc['original_name'], ENT_QUOTES) ?></a>
                <span class="muted">(<?= htmlspecialchars($doc['mime'], ENT_QUOTES) ?>, <?= round($doc['size'] / 1024) ?> Ko)</span>
            </td>
        </tr>
    <?php endforeach; ?>
</table>

<?php if ($app['residence'] === 'hors-commune'): ?>
    <h2>Exception Midi</h2>
    <?php if ($app['midi_residency_override']): ?>
        <p>✔ Exception accordée — abonnement Midi ouvert à cette personne au tarif Garennois.
            Motif : <?= htmlspecialchars($app['midi_residency_override_reason'], ENT_QUOTES) ?></p>
    <?php elseif ($app['status'] === 'submitted'): ?>
        <p class="muted">L'abonnement Midi est réservé aux résidents Garennois. Un admin peut accorder une exception ponctuelle (tarif Garennois).</p>
        <form method="post" action="/admin/demandes/<?= (int) $app['id'] ?>/midi-override" class="form form-wide">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <label for="midi_override_reason">Motif de l'exception *</label>
            <textarea id="midi_override_reason" name="midi_override_reason" rows="2" maxlength="500" required></textarea>
            <button type="submit" class="btn-small">Accorder l'exception Midi</button>
        </form>
    <?php endif; ?>
<?php endif; ?>

<?php if ($quote !== null): ?>
    <h2>Montant attendu</h2>
    <table class="details">
        <?php foreach ($quote->lines as $line): ?>
            <tr><th><?= htmlspecialchars($line->label, ENT_QUOTES) ?></th><td><?= number_format($line->amount, 2, ',', ' ') ?> €</td></tr>
        <?php endforeach; ?>
        <tr><th><strong>Total</strong></th><td><strong><?= number_format($quote->total(), 2, ',', ' ') ?> €</strong></td></tr>
    </table>
<?php endif; ?>

<?php if ($app['status'] === 'submitted'): ?>
    <h2>Décision</h2>
    <form method="post" action="/admin/demandes/<?= (int) $app['id'] ?>/decision" class="form form-wide">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <label for="reason">Motif (obligatoire en cas de refus)</label>
        <textarea id="reason" name="reason" rows="3" maxlength="500"></textarea>
        <div>
            <button type="submit" name="decision" value="validate">Valider — envoyer le lien de paiement</button>
            <button type="submit" name="decision" value="reject" class="btn-danger">Refuser</button>
        </div>
    </form>
<?php endif; ?>
<p><a href="/admin/demandes">← Retour aux demandes</a></p>
