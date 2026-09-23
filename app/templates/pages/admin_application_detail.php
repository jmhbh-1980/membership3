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

<?php $isCouple = (bool) $app['is_couple']; ?>
<h2><?= $isCouple ? 'Inscription en couple' : 'Adhérent' . (count($people) > 1 ? 's' : '') ?><?= $this->fetch('partials/garennois_badge.php', [
    'residence' => $app['residence'] ?? '',
    'pricingResidence' => $app['pricing_residence'] ?? '',
]) ?></h2>
<?php if ($isCouple): ?>
    <p class="muted">Un seul règlement pour les deux adhésions, par
        <?= htmlspecialchars(isset($people[1]) ? $people[1]['firstname'] . ' ' . $people[1]['lastname'] : 'le demandeur', ENT_QUOTES) ?>.
        <?php if (!isset($people[2])): ?><strong>Le/la conjoint(e) n'a pas encore été renseigné(e).</strong><?php endif; ?></p>
<?php endif; ?>
<table class="details">
    <?php foreach ($people as $position => $p): ?>
        <tr>
            <th><?= $this->fetch('partials/member_name.php', ['name' => $p['firstname'] . ' ' . $p['lastname'], 'bjUserId' => $p['bj_user_id']]) ?>
                <?php if ($isCouple): ?><br><span class="muted"><?= $position === 1 ? 'demandeur(se) — règle pour les deux' : 'conjoint(e)' ?></span><?php endif; ?></th>
            <td>
                Né(e) le <?= date('d/m/Y', strtotime($p['birthdate'])) ?><?= \App\Support\Age::suffix($p['birthdate']) ?><?= $p['is_minor'] ? ' — mineur(e)' : '' ?><?= $p['competitor'] ? ' — compétiteur' : '' ?><?= $p['licence_removed'] ? ' — licence retirée (' . htmlspecialchars($p['licence_removal_reason'], ENT_QUOTES) . ')' : '' ?><br>
                <?php $phoneLink = \App\Support\WhatsApp::link($p['phone']); ?>
                <?= htmlspecialchars($p['email'], ENT_QUOTES) ?> ·
                <?= $phoneLink !== null ? '<a href="' . htmlspecialchars($phoneLink, ENT_QUOTES) . '" target="_blank" rel="noopener">💬 ' . htmlspecialchars($p['phone'], ENT_QUOTES) . '</a>' : htmlspecialchars($p['phone'], ENT_QUOTES) ?><br>
                <?= htmlspecialchars($p['address'] . ', ' . $p['postalcode'] . ' ' . $p['city'], ENT_QUOTES) ?>
                <?php if ($p['is_minor']): ?>
                    <?php $guardianPhoneLink = \App\Support\WhatsApp::link($p['guardian_phone']); ?>
                    <br>Représentant légal : <?= htmlspecialchars($p['guardian_fullname'], ENT_QUOTES) ?>
                    (<?= htmlspecialchars($p['guardian_email'], ENT_QUOTES) ?>,
                    <?= $guardianPhoneLink !== null ? '<a href="' . htmlspecialchars($guardianPhoneLink, ENT_QUOTES) . '" target="_blank" rel="noopener">💬 ' . htmlspecialchars($p['guardian_phone'], ENT_QUOTES) . '</a>' : htmlspecialchars($p['guardian_phone'], ENT_QUOTES) ?>)
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
    <?php $appliedGrid = (string) $app['pricing_residence'] !== '' ? (string) $app['pricing_residence'] : (string) $app['residence']; ?>
    <tr><th>Abonnement</th><td><?= htmlspecialchars($app['subscription_type'], ENT_QUOTES) ?><?= $app['is_couple'] ? ' — couple' : '' ?> — tarif <?= htmlspecialchars($appliedGrid, ENT_QUOTES) ?><?= $appliedGrid !== $app['residence'] ? ' (exception — résidence : ' . htmlspecialchars($app['residence'], ENT_QUOTES) . ')' : '' ?>,
        saison <?= (int) $app['season_start_year'] ?>-<?= (int) $app['season_start_year'] + 1 ?><?= (int) $app['lessons_count'] > 0 ? ' — cours collectifs × ' . (int) $app['lessons_count'] : '' ?></td></tr>
    <?php foreach ($documents as $doc): ?>
        <tr>
            <th><?= ['photo' => 'Photo', 'justificatif' => 'Justificatif de domicile', 'medical_certificate' => 'Certificat médical', 'student_certificate' => 'Certificat de scolarité'][$doc['kind']] ?>
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

<?php
    $grantable = $app['residence'] === 'garennois' ? 'hors-commune' : 'garennois';
    $gridLabel = fn (string $r): string => $r === 'garennois' ? 'Garennois' : 'Hors commune';
?>
<h2>Exception de tarif</h2>
<?php if ((string) $app['pricing_residence'] !== ''): ?>
    <p>Exception accordée — cette demande est facturée au tarif
        <strong><?= htmlspecialchars($gridLabel((string) $app['pricing_residence']), ENT_QUOTES) ?></strong>,
        quel que soit l'abonnement choisi.<br>
        Motif : <?= htmlspecialchars((string) $app['pricing_residence_reason'], ENT_QUOTES) ?>
        <?php if ((string) $app['pricing_residence_by'] !== ''): ?>
            <br><span class="muted">Accordée par <?= htmlspecialchars((string) $app['pricing_residence_by'], ENT_QUOTES) ?>.</span>
        <?php endif; ?></p>
    <p class="muted">Elle sera reportée sur le compte du membre à la validation du paiement, pour cette saison
        uniquement : elle devra être réaccordée l'an prochain depuis
        <a href="/admin/exceptions-tarif">Exceptions de tarif</a>.</p>
    <?php if ($app['status'] === 'submitted'): ?>
        <form method="post" action="/admin/demandes/<?= (int) $app['id'] ?>/exception-tarif" class="form form-wide">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <input type="hidden" name="revoke" value="1">
            <label for="exception_revoke_reason">Motif de la révocation</label>
            <textarea id="exception_revoke_reason" name="reason" rows="2" maxlength="500"></textarea>
            <button type="submit" class="btn-small" onclick="return confirm('Révoquer l\'exception de tarif ?')">Révoquer l'exception</button>
        </form>
    <?php endif; ?>
<?php elseif ($app['status'] === 'submitted'): ?>
    <p class="muted">Cette demande est facturée au tarif <?= htmlspecialchars($gridLabel((string) $app['residence']), ENT_QUOTES) ?>,
        d'après le code postal. Le club peut accorder à titre exceptionnel le tarif
        <strong><?= htmlspecialchars($gridLabel($grantable), ENT_QUOTES) ?></strong> sur toutes les formules<?php
        if ($grantable === 'garennois'): ?> — y compris l'abonnement Midi, réservé aux Garennois<?php endif; ?>.
        Le justificatif de domicile n'est pas demandé dans ce cas : le motif ci-dessous en tient lieu.</p>
    <form method="post" action="/admin/demandes/<?= (int) $app['id'] ?>/exception-tarif" class="form form-wide">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <label for="exception_reason">Motif de l'exception *</label>
        <textarea id="exception_reason" name="reason" rows="2" maxlength="500" required></textarea>
        <button type="submit" class="btn-small">Accorder le tarif <?= htmlspecialchars($gridLabel($grantable), ENT_QUOTES) ?></button>
    </form>
<?php endif; ?>

<?php if (!empty($app['student_discount_requested']) || $app['student_discount_refused_at'] !== null): ?>
    <h2>Statut étudiant</h2>
    <p class="muted">
        <?php if (!empty($app['student_discount_requested'])): ?>
            <?= $app['student_discount_approved']
                ? '✔ Validé — réduction de 50 % appliquée sur la cotisation et les cours collectifs.'
                : 'Certificat transmis, en attente de validation — voir '
                    . '<a href="/admin/reduction-etudiant">Réductions étudiant en attente</a>.' ?>
        <?php endif; ?>
        <?php if ($app['student_discount_refused_at'] !== null): ?>
            Précédemment refusé<?= $app['student_discount_refusal_reason'] !== '' ? ' : ' . htmlspecialchars($app['student_discount_refusal_reason'], ENT_QUOTES) : '' ?>.
        <?php endif; ?>
    </p>
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
