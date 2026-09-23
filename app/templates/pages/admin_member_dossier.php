<?php
/**
 * @var array $dossier  MemberDossierService::forBjUser() output
 * @var string $csrf
 */
$u = $dossier['bjUser'];
$id = (int) $u['user_id'];
$money = fn (float $v): string => number_format($v, 2, ',', ' ') . ' €';
$date = fn (?string $d): string => $d && !str_starts_with($d, '0000') ? date('d/m/Y', strtotime($d)) : '—';
$dateTime = fn (?string $d): string => $d && !str_starts_with($d, '0000') ? date('d/m/Y H:i', strtotime($d)) : '—';
$or = fn (?string $v): string => ($v ?? '') !== '' ? htmlspecialchars($v, ENT_QUOTES) : '<span class="muted">—</span>';
$suspended = ($u['suspend_date'] ?? '') !== '' && !str_starts_with((string) $u['suspend_date'], '0000');
$kindLabels = [
    'application' => 'Adhésion', 'order' => 'Commande', 'invoice' => 'Facture',
    'credit_note' => 'Avoir', 'season' => 'Saison', 'change_request' => 'Changement',
    'exception' => 'Tarif', 'student' => 'Étudiant', 'attestation' => 'Santé',
    'installment' => 'Échelonnement', 'lessons' => 'Cours', 'audit' => 'Journal', 'email' => 'Email',
];
?>
<h1><?= htmlspecialchars(trim(($u['lastname'] ?? '') . ' ' . ($u['firstname'] ?? '')), ENT_QUOTES) ?>
    <?= $this->fetch('partials/garennois_badge.php', [
        'residence' => $dossier['residence'],
        'pricingResidence' => $dossier['pricingResidence'],
    ]) ?></h1>
<p class="muted">Fiche adhérent — identifiant Balle Jaune <?= $id ?>.
    <?= $dossier['roleName'] !== '' ? 'Rôle : <strong>' . htmlspecialchars($dossier['roleName'], ENT_QUOTES) . '</strong>.' : '' ?></p>

<?php if ($dossier['couple'] !== null): ?>
    <p><?= $this->fetch('partials/couple_partner.php', ['partner' => $dossier['couple']['partner']]) ?><br>
        <?php if ($dossier['couple']['partner'] !== null): ?>
            <span class="muted">Adhésion en couple : un seul règlement couvre les deux, et l'un ou l'autre peut
                renouveler pour le couple. Les commandes réglées par le/la conjoint(e) figurent aussi ci-dessous.</span>
        <?php else: ?>
            <span class="muted">Balle Jaune indique une adhésion en couple sans conjoint(e) rattaché(e) (champ
                custom3 vide). Pour renouveler en couple, l'adhérent devra saisir l'email de son/sa conjoint(e) ;
                renseigner l'identifiant du/de la conjoint(e) dans custom3 évite cette étape.</span>
        <?php endif; ?></p>
<?php endif; ?>

<?php if ($suspended): ?>
    <div class="alert">Compte suspendu dans Balle Jaune le <?= $date($u['suspend_date']) ?><?=
        ($u['suspend_reason'] ?? '') !== '' ? ' — ' . htmlspecialchars((string) $u['suspend_reason'], ENT_QUOTES) : '' ?>.
        Cet adhérent ne peut pas se connecter.</div>
<?php endif; ?>

<p class="form-inline">
    <a class="btn btn-small" href="/admin/exceptions-tarif/membre/<?= $id ?>">Exception de tarif</a>
    <?php if ($dossier['roleName'] !== 'Administrateur'): ?>
        <form method="post" action="/admin/membres/<?= $id ?>/voir-comme" class="form-inline">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <button type="submit" class="btn-small">Voir comme</button>
        </form>
    <?php endif; ?>
</p>

<h2>Contact</h2>
<table class="details">
    <tr><th>Email</th><td><?= $or($u['email'] ?? '') ?><?= ($u['email2'] ?? '') !== '' ? '<br>' . htmlspecialchars((string) $u['email2'], ENT_QUOTES) . ' <span class="muted">(secondaire)</span>' : '' ?></td></tr>
    <tr><th>Téléphone</th><td>
        <?php foreach (['phone1', 'phone2'] as $f): ?>
            <?php if (($u[$f] ?? '') !== ''): ?>
                <?php $link = \App\Support\WhatsApp::link((string) $u[$f]); ?>
                <?= $link !== null
                    ? '<a href="' . htmlspecialchars($link, ENT_QUOTES) . '" target="_blank" rel="noopener">' . htmlspecialchars((string) $u[$f], ENT_QUOTES) . '</a>'
                    : htmlspecialchars((string) $u[$f], ENT_QUOTES) ?><br>
            <?php endif; ?>
        <?php endforeach; ?>
        <?= ($u['phone1'] ?? '') === '' && ($u['phone2'] ?? '') === '' ? '<span class="muted">—</span>' : '' ?>
    </td></tr>
    <tr><th>Adresse</th><td><?= $or($u['address'] ?? '') ?><br>
        <?= htmlspecialchars(trim(((string) ($u['postalcode'] ?? '')) . ' ' . ((string) ($u['city'] ?? ''))), ENT_QUOTES) ?></td></tr>
    <?php $birthday = $u['birthday'] ?? null; ?>
    <tr><th>Naissance</th><td><?= $date($birthday) . \App\Support\Age::suffix($birthday) ?></td></tr>
</table>

<h2>Adhésion</h2>
<table class="details">
    <tr><th>Abonnement</th><td><?= $or($dossier['subscriptionName']) ?></td></tr>
    <tr><th>Période</th><td><?= $date($u['subscription_date_start'] ?? null) ?> → <?= $date($u['subscription_date_end'] ?? null) ?></td></tr>
    <tr><th>Réglée</th><td><?= ($u['subscription_paid'] ?? 0) ? 'oui' : 'non' ?><?=
        ($u['subscription_paid_date'] ?? '') !== '' && !str_starts_with((string) $u['subscription_paid_date'], '0000')
            ? ' — le ' . $date($u['subscription_paid_date']) : '' ?></td></tr>
    <tr><th>Tarif</th><td><?= htmlspecialchars($dossier['pricingResidence'], ENT_QUOTES) ?><?php
        if ($dossier['exception'] !== null): ?> <span class="muted">(exception : <?= htmlspecialchars((string) $dossier['exception']['reason'], ENT_QUOTES) ?>)</span><?php
        endif; ?></td></tr>
    <tr><th>Licence</th><td><?= $or($u['license_number'] ?? '') ?><?= ($u['license_year'] ?? '') !== '' ? ' (' . htmlspecialchars((string) $u['license_year'], ENT_QUOTES) . ')' : '' ?><?=
        !empty($u['flag']) ? ' — <strong>à enregistrer auprès de la fédération</strong>' : '' ?></td></tr>
    <tr><th>Crédits</th><td><?= number_format((float) ($u['book_card_tickets'] ?? 0), 0, ',', ' ') ?> réservation(s)</td></tr>
    <?php if (trim((string) ($u['subscription_notes'] ?? '')) !== ''): ?>
        <tr><th>Notes BJ</th><td class="muted"><?= nl2br(htmlspecialchars((string) $u['subscription_notes'], ENT_QUOTES)) ?></td></tr>
    <?php endif; ?>
</table>

<h2>Documents</h2>
<?php if (!$dossier['hasApplication']): ?>
    <p class="muted">Aucun document. Cet adhérent n'a pas rejoint le club via cette application
        (adhésion antérieure, ou compte créé directement dans Balle Jaune) : la photo et le
        justificatif de domicile sont attachés à une demande d'adhésion, qui n'existe pas ici.
        Ses documents apparaîtront s'il renouvelle un jour via l'application.</p>
<?php else: ?>
    <?php foreach ($dossier['documentsByApplication'] as $applicationId => $bundle): ?>
        <?php
            $docUrl = fn (string $stored): string => '/admin/demandes/' . (int) $applicationId . '/document/' . rawurlencode($stored);
            // A couple's application holds both partners' documents: name whose each one is.
            $whose = function (int $position) use ($bundle, $id): string {
                $person = $bundle['people'][$position] ?? null;
                if ($person === null) {
                    return '';
                }
                $name = $person['firstname'] . ' ' . $person['lastname'];
                $label = (int) $person['bj_user_id'] === $id ? $name : 'conjoint(e) : ' . $name;
                return '<span class="muted">(' . htmlspecialchars($label, ENT_QUOTES) . ')</span>';
            };
        ?>
        <p class="muted">Demande #<?= (int) $applicationId ?> — <?= htmlspecialchars((string) $bundle['application']['status'], ENT_QUOTES) ?>,
            <?= $date($bundle['application']['created_at']) ?><?= $bundle['application']['is_couple'] ? ', inscription en couple' : '' ?>
            · <a href="/admin/demandes/<?= (int) $applicationId ?>">voir la demande</a></p>
        <?php if ($bundle['documents'] === [] && $bundle['attestations'] === []): ?>
            <p class="muted">Aucun document conservé pour cette demande.</p>
        <?php else: ?>
            <table class="details">
                <?php foreach ($bundle['documents'] as $doc): ?>
                    <tr>
                        <th><?= ['photo' => 'Photo', 'justificatif' => 'Justificatif de domicile',
                                 'medical_certificate' => 'Certificat médical',
                                 'student_certificate' => 'Certificat de scolarité'][$doc['kind']] ?? htmlspecialchars($doc['kind'], ENT_QUOTES) ?>
                            <?= $whose((int) $doc['person_position']) ?></th>
                        <td>
                            <?php if (str_starts_with((string) $doc['mime'], 'image/')): ?>
                                <a href="<?= $docUrl($doc['stored_name']) ?>" target="_blank" rel="noopener">
                                    <img class="doc-preview" src="<?= $docUrl($doc['stored_name']) ?>" alt=""></a><br>
                            <?php endif; ?>
                            <a href="<?= $docUrl($doc['stored_name']) ?>" target="_blank" rel="noopener"><?= htmlspecialchars((string) $doc['original_name'], ENT_QUOTES) ?></a>
                            <span class="muted">(<?= round((int) $doc['size'] / 1024) ?> Ko)</span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php foreach ($bundle['attestations'] as $position => $att): ?>
                    <tr><th>Attestation de santé <?= $whose((int) $position) ?></th><td>
                        <?= $att['outcome'] === 'all_negative' ? 'questionnaire signé' : 'certificat médical fourni' ?>
                        <?php if ((string) $att['pdf_stored_name'] !== ''): ?>
                            — <a href="<?= $docUrl($att['pdf_stored_name']) ?>" target="_blank" rel="noopener">voir le PDF</a>
                        <?php endif; ?>
                        <span class="muted">(<?= $dateTime($att['signed_at']) ?>)</span>
                    </td></tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    <?php endforeach; ?>
<?php endif; ?>

<h2>Saisons</h2>
<?php if ($dossier['seasons'] === []): ?>
    <p class="muted">Aucune saison enregistrée par l'application. Les adhésions réglées hors
        application (espèces, chèque, virement direct) n'y figurent pas.</p>
<?php else: ?>
    <div class="table-scroll">
    <table class="details">
        <tr><th>Saison</th><th>Formule</th><th>Licence</th><th>Cours</th><th>Tarif</th><th>Commande</th></tr>
        <?php foreach ($dossier['seasons'] as $s): ?>
            <tr>
                <td><?= (int) $s['season_start_year'] ?>-<?= (int) $s['season_start_year'] + 1 ?></td>
                <td><?= htmlspecialchars((string) $s['subscription_type'], ENT_QUOTES) ?>
                    <?php if ($s['is_couple']): ?><br><?= $this->fetch('partials/couple_partner.php', ['partner' => $s['partner']]) ?><?php endif; ?></td>
                <td><?= $s['competitor'] ? 'fédérale (compétiteur)' : 'pass' ?></td>
                <td><?= (int) $s['lessons'] > 0 ? (int) $s['lessons'] : '—' ?></td>
                <td><?= $or((string) $s['pricing_residence']) ?></td>
                <td><?= $s['order_id'] ? '<a href="/admin/commandes/' . (int) $s['order_id'] . '">#' . (int) $s['order_id'] . '</a>' : '<span class="muted">—</span>' ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
<?php endif; ?>

<h2>Commandes</h2>
<?php if ($dossier['orders'] === []): ?>
    <p class="muted">Aucune commande passée via l'application.</p>
<?php else: ?>
    <div class="table-scroll">
    <table class="details">
        <tr><th>#</th><th>Type</th><th>Montant</th><th>Statut</th><th>Date</th><th>Facture</th></tr>
        <?php foreach ($dossier['orders'] as $o): ?>
            <tr>
                <td><a href="/admin/commandes/<?= (int) $o['id'] ?>"><?= (int) $o['id'] ?></a></td>
                <td><?= htmlspecialchars((string) $o['kind'], ENT_QUOTES) ?><?=
                    (int) ($o['installment_number'] ?? 0) > 0 ? ' <span class="muted">(versement ' . (int) $o['installment_number'] . ')</span>' : '' ?>
                    <?php if ($o['couple'] !== null): ?><br><?= $this->fetch('partials/couple_partner.php', [
                        'partner' => $o['couple']['other'],
                        'lead'    => $o['couple']['paidByPartner'] ? 'réglée par' : 'avec',
                    ]) ?><?php endif; ?></td>
                <td><?= $money((float) $o['amount']) ?></td>
                <td><?= htmlspecialchars((string) $o['status'], ENT_QUOTES) ?></td>
                <td><?= $date($o['created_at']) ?></td>
                <td>
                    <?= $o['invoice'] !== null
                        ? '<a href="/admin/commandes/' . (int) $o['id'] . '/facture" target="_blank" rel="noopener">' . htmlspecialchars((string) $o['invoice']['number'], ENT_QUOTES) . '</a>'
                        : '<span class="muted">—</span>' ?>
                    <?= $o['creditNote'] !== null
                        ? '<br><span class="muted">avoir ' . htmlspecialchars((string) $o['creditNote']['number'], ENT_QUOTES) . '</span>'
                        : '' ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
<?php endif; ?>

<h2>Historique</h2>
<?php if ($dossier['timeline'] === []): ?>
    <p class="muted">Rien d'enregistré pour cet adhérent.</p>
<?php else: ?>
    <p class="muted">Reconstitué à partir des dates conservées dans l'application — il n'existe pas
        d'historique de statuts à proprement parler. Les décisions d'admin, les emails, les
        commandes, les factures et ce que l'adhérent signe explicitement y figurent ; ouvrir une
        page de paiement ou abandonner un panier ne laisse aucune trace. Un trou dans cette liste
        signifie « non enregistré », pas « rien ne s'est passé ».</p>
    <div class="table-scroll">
    <table class="details">
        <tr><th>Date</th><th>Type</th><th>Événement</th><th>Détail</th><th>Par</th></tr>
        <?php foreach ($dossier['timeline'] as $e): ?>
            <tr>
                <td><?= $dateTime($e['at']) ?></td>
                <td><?= htmlspecialchars($kindLabels[$e['kind']] ?? $e['kind'], ENT_QUOTES) ?></td>
                <td><?= $e['url'] !== null
                        ? '<a href="' . htmlspecialchars($e['url'], ENT_QUOTES) . '">' . htmlspecialchars($e['label'], ENT_QUOTES) . '</a>'
                        : htmlspecialchars($e['label'], ENT_QUOTES) ?></td>
                <td class="muted"><?= htmlspecialchars($e['detail'], ENT_QUOTES) ?></td>
                <td class="muted"><?= htmlspecialchars($e['actor'], ENT_QUOTES) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
<?php endif; ?>

<p><a href="/admin/membres">← Adhérents</a></p>
