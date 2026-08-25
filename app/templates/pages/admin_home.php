<h1>Administration</h1>
<p>Bonjour <?= htmlspecialchars($user['firstname'] ?? '', ENT_QUOTES) ?>.</p>
<?php
    $link = function (string $href, string $label, ?string $countKey = null) use ($counts): void {
        $count = $countKey !== null ? ($counts[$countKey] ?? 0) : null;
        echo '<li><a href="' . htmlspecialchars($href, ENT_QUOTES) . '">' . htmlspecialchars($label, ENT_QUOTES) . '</a>';
        if ($count !== null) {
            echo ' <span class="badge">' . $count . '</span>';
        }
        echo '</li>';
    };
?>
<ul class="admin-links">
    <?php $link('/admin/demandes', 'Demandes d\'adhésion', 'demandes'); ?>
    <?php $link('/admin/demandes/abandonnees', 'Demandes abandonnées', 'abandonnees'); ?>
    <?php $link('/admin/changements', 'Changements de formule', 'changements'); ?>
    <?php $link('/admin/campagne', 'Campagne de renouvellement'); ?>
    <?php $link('/admin/membres', 'Adhérents'); ?>
    <?php $link('/admin/cours', 'Cours collectifs', 'cours'); ?>
    <?php $link('/admin/licences', 'Licences à enregistrer', 'licences'); ?>
    <?php $link('/admin/semelles', 'Contrôle des semelles', 'semelles'); ?>
    <?php $link('/admin/commandes', 'Commandes', 'commandes'); ?>
    <?php $link('/admin/tarifs', 'Barèmes tarifaires'); ?>
    <?php $link('/admin/codes-promo', 'Codes promo'); ?>
</ul>
