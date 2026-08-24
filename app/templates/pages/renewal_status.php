<?php
/**
 * @var string $state  done | not_yet_open | change_pending | change_approved | choice | couple_awaiting_next_season
 * @var App\Service\Season $season
 * @var ?array $request
 * @var ?array $steps
 */
$isLicenceRequest = $request !== null && $request['kind'] === 'licence';
?>
<h1>Renouvellement — saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?></h1>
<?php if (!empty($steps)): ?>
    <?= $this->fetch('partials/wizard_steps.php', ['steps' => $steps]) ?>
<?php endif; ?>
<?php if ($state === 'done'): ?>
    <p>✔ Votre adhésion pour la saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?> est déjà réglée. Bonne saison !</p>
    <p><a class="btn" href="/espace">Retour à mon espace</a></p>
<?php elseif ($state === 'not_yet_open'): ?>
    <p>✔ Vous êtes à jour pour la saison en cours. Le tarif de la saison <?= htmlspecialchars($season->next()->label(), ENT_QUOTES) ?> n'est pas encore publié — vous serez informé·e dès son ouverture.</p>
    <p><a class="btn" href="/espace">Retour à mon espace</a></p>
<?php elseif ($state === 'couple_awaiting_next_season'): ?>
    <p>Votre renouvellement en couple pour la saison en cours n'est pas encore réglé. Le Pack été n'étant pas proposé aux couples, vous pourrez renouveler directement pour la saison <?= htmlspecialchars($season->next()->label(), ENT_QUOTES) ?> dès que son tarif sera publié — vous serez informé·e à ce moment-là.</p>
    <p><a class="btn" href="/espace">Retour à mon espace</a></p>
<?php elseif ($state === 'choice'): ?>
    <p>Votre abonnement pour la saison en cours n'est pas encore réglé. La saison
       <?= htmlspecialchars($season->next()->label(), ENT_QUOTES) ?> est déjà ouverte : choisissez entre le Pack été
       (saison en cours, tarif forfaitaire) et une inscription directe pour la saison
       <?= htmlspecialchars($season->next()->label(), ENT_QUOTES) ?> (tarif plein).</p>
    <p>
        <a class="btn btn-outline" href="/espace/renouvellement?choice=ete">Pack été — saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?></a>
        <a class="btn" href="/espace/renouvellement?choice=next">Saison <?= htmlspecialchars($season->next()->label(), ENT_QUOTES) ?></a>
    </p>
<?php elseif ($state === 'change_pending'): ?>
    <?php if ($isLicenceRequest): ?>
        <p>Votre demande de retrait de licence a bien été transmise au club. Vous recevrez un email dès qu'elle aura été examinée ;
            vous pourrez alors procéder au paiement depuis votre espace.</p>
    <?php else: ?>
        <p>Votre demande de changement de formule a bien été transmise au club. Vous recevrez un email dès qu'elle aura été examinée ;
            vous pourrez alors procéder au paiement depuis votre espace.</p>
    <?php endif; ?>
    <p><a class="btn" href="/espace">Retour à mon espace</a></p>
<?php elseif ($state === 'change_approved'): ?>
    <?php if ($isLicenceRequest): ?>
        <p>✔ Votre demande de retrait de licence a été acceptée par le club<?= $request['admin_note'] !== '' ? ' — ' . htmlspecialchars($request['admin_note'], ENT_QUOTES) : '' ?>.</p>
    <?php else: ?>
        <p>✔ Votre changement de formule a été accepté par le club<?= $request !== null && $request['admin_note'] !== '' ? ' — ' . htmlspecialchars($request['admin_note'], ENT_QUOTES) : '' ?>.</p>
    <?php endif; ?>
    <p><a class="btn" href="/espace/renouvellement/paiement">Procéder au paiement</a></p>
<?php endif; ?>
