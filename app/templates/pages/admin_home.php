<h1>Administration</h1>
<p>Bonjour <?= htmlspecialchars($user['firstname'] ?? '', ENT_QUOTES) ?>.</p>
<?php
    // A badge means "there is something here for you". Zero is not something,
    // and a row of grey 0s is noise that makes the counts that do matter harder
    // to spot — so an empty section simply shows no badge at all.
    $link = function (string $href, string $label, ?string $countKey = null) use ($counts): void {
        $count = $countKey !== null ? ($counts[$countKey] ?? 0) : 0;
        echo '<li><a href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
        if ($count > 0) {
            echo ' <span class="badge">' . $count . '</span>';
        }
        echo '</li>';
    };
?>
<ul class="admin-links">
    <?php $link('/admin/decisions', 'En attente de votre décision', 'decisions'); ?>
    <?php $link('/admin/demandes', 'Demandes d\'adhésion', 'demandes'); ?>
    <?php $link('/admin/demandes/attente-paiement', 'Approuvées, en attente de paiement', 'attente_paiement'); ?>
    <?php $link('/admin/demandes/abandonnees', 'Demandes abandonnées', 'abandonnees'); ?>
    <?php $link('/admin/changements', 'Changements de formule', 'changements'); ?>
    <?php $link('/admin/campagne', 'Campagne de renouvellement'); ?>
    <?php $link('/admin/membres', 'Adhérents'); ?>
    <?php $link('/admin/cours', 'Cours collectifs', 'cours'); ?>
    <?php $link('/admin/licences', 'Licences à enregistrer', 'licences'); ?>
    <?php $link('/admin/semelles', 'Contrôle des semelles', 'semelles'); ?>
    <?php $link('/admin/commandes', 'Commandes', 'commandes'); ?>
    <?php $link('/admin/tarifs', 'Barèmes tarifaires'); ?>
    <?php $link('/admin/exceptions-tarif', 'Exceptions de tarif'); ?>
    <?php $link('/admin/codes-promo', 'Codes promo'); ?>
    <?php $link('/admin/codes-promo/approbations', 'Commandes avec code promo en attente', 'promo_orders'); ?>
    <?php $link('/admin/virements', 'Virements en attente', 'bank_transfers'); ?>
    <?php $link('/admin/reglages/virement', 'Coordonnées bancaires'); ?>
    <?php $link('/admin/reglages/signature-email', 'Signature email'); ?>
    <?php $link('/admin/reglages/descriptions-factures', 'Descriptions sur les factures'); ?>
    <?php $link('/admin/reglages/reglement-interieur', 'Règlement intérieur'); ?>
    <?php $link('/admin/reglages/chaussures', 'Règles chaussures'); ?>
    <?php $link('/admin/reduction-etudiant', 'Réductions étudiant en attente', 'student_discounts'); ?>
    <?php $link('/admin/paiements-echelonnes', 'Paiements échelonnés en cours', 'installment_plans'); ?>
    <?php $link('/admin/sauvegardes', 'Sauvegardes'); ?>
    <?php $link('/admin/journal-audit', 'Journal d\'audit'); ?>
</ul>

<h2>Signalement de bug</h2>
<p>
    Mode signalement : <strong><?= $bugReportModeEnabled ? 'activé' : 'désactivé' ?></strong>
    — une bulle flottante permet aux visiteurs et adhérents (hors administrateurs) de signaler un problème.
</p>
<form method="post" action="/admin/signalement/<?= $bugReportModeEnabled ? 'desactiver' : 'activer' ?>">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <button type="submit" class="btn<?= $bugReportModeEnabled ? ' btn-danger' : '' ?>">
        <?= $bugReportModeEnabled ? 'Désactiver' : 'Activer' ?>
    </button>
</form>
