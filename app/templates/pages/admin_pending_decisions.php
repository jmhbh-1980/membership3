<?php
/**
 * @var array[] $rows   {type, typeLabel, who, what, since, days, url}
 * @var array<string,int> $counts  type => count
 *
 * One queue across all five approval types, oldest first. Each row links to the
 * page that owns the decision — an application needs its documents, a student
 * discount its certificate, a transfer the bank statement — so nothing is
 * decided from here. What this adds is the ordering: the person who has been
 * waiting longest is at the top, whatever they asked for.
 */
$typeLinks = [
    'application'    => '/admin/demandes',
    'change_request' => '/admin/changements',
    'promo'          => '/admin/codes-promo/approbations',
    'student'        => '/admin/reduction-etudiant',
    'bank_transfer'  => '/admin/virements',
];
$typeNames = [
    'application'    => 'Demandes d\'adhésion',
    'change_request' => 'Changements de formule',
    'promo'          => 'Codes promo',
    'student'        => 'Réductions étudiant',
    'bank_transfer'  => 'Virements',
];
$oldest = $rows !== [] ? (int) $rows[0]['days'] : 0;
?>
<h1>En attente de votre décision</h1>

<?php if ($rows === []): ?>
    <p>Rien en attente. Aucune demande d'adhésion, changement de formule, code promo,
        réduction étudiant ou virement n'attend votre décision.</p>
    <p class="muted">Deux tableaux ne figurent pas ici et gardent leur page :
        <a href="/admin/semelles">le contrôle des semelles</a> (l'adhérent doit se présenter au club)
        et <a href="/admin/licences">les licences à enregistrer</a> (démarche administrative en nombre,
        personne n'est bloqué en attendant).</p>
<?php else: ?>
    <p><strong><?= count($rows) ?></strong> décision<?= count($rows) > 1 ? 's' : '' ?> en attente<?php
        if ($oldest > 0): ?> — la plus ancienne depuis <strong><?= $oldest ?> jour<?= $oldest > 1 ? 's' : '' ?></strong><?php
        endif; ?>.</p>

    <p class="muted">
        <?php $parts = [];
        foreach ($counts as $type => $count) {
            $parts[] = '<a href="' . htmlspecialchars($typeLinks[$type] ?? '/admin', ENT_QUOTES) . '">'
                . htmlspecialchars($typeNames[$type] ?? $type, ENT_QUOTES) . ' : ' . (int) $count . '</a>';
        }
        echo implode(' · ', $parts); ?>
    </p>

    <div class="table-scroll">
    <table class="details">
        <tr><th>Type</th><th>Qui</th><th>Objet</th><th>Depuis</th><th></th></tr>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row['typeLabel'], ENT_QUOTES) ?></td>
                <td><?= $row['who'] !== '' ? htmlspecialchars($row['who'], ENT_QUOTES) : '<span class="muted">—</span>' ?></td>
                <td><?= htmlspecialchars($row['what'], ENT_QUOTES) ?></td>
                <td><?= (int) $row['days'] ?> j<br>
                    <span class="muted"><?= date('d/m/Y', strtotime($row['since'])) ?></span></td>
                <td><a class="btn btn-small" href="<?= htmlspecialchars($row['url'], ENT_QUOTES) ?>">Décider</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>

    <p class="muted">Le contrôle des semelles et les licences à enregistrer ne figurent pas ici :
        le premier demande la présence de l'adhérent au club, le second est une démarche
        administrative en nombre qui ne bloque personne. Ils gardent leurs pages
        (<a href="/admin/semelles">semelles</a>, <a href="/admin/licences">licences</a>).</p>
<?php endif; ?>

<p><a href="/admin">← Administration</a></p>
