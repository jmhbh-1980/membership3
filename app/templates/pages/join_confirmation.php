<?php
/** @var array $app */
$labels = [
    'draft'            => ['En cours de saisie', 'Votre demande n\'a pas encore été envoyée au club.'],
    'submitted'        => ['Demande transmise ✔', 'Votre demande est en attente de validation par le club. Vous recevrez un email dès qu\'elle sera examinée.'],
    'validated'        => ['Demande validée ✔', 'Votre demande a été validée. Consultez vos emails pour procéder au paiement.'],
    'awaiting_payment' => ['En attente de paiement', 'Votre demande est validée. Consultez vos emails pour procéder au paiement.'],
    'paid'             => ['Paiement reçu ✔', 'Votre paiement a bien été reçu. Votre compte est en cours de création.'],
    'fulfilled'        => ['Adhésion finalisée ✔', 'Bienvenue au club ! Vos identifiants Balle Jaune vous ont été envoyés.'],
    'rejected'         => ['Demande refusée', 'Votre demande n\'a pas pu être acceptée.'],
];
[$heading, $text] = $labels[$app['status']] ?? $labels['draft'];
?>
<h1>Ma demande d'adhésion</h1>
<h2><?= htmlspecialchars($heading, ENT_QUOTES) ?></h2>
<p><?= htmlspecialchars($text, ENT_QUOTES) ?></p>
<?php if ($app['status'] === 'rejected' && $app['rejection_reason'] !== ''): ?>
    <p>Motif : <?= htmlspecialchars($app['rejection_reason'], ENT_QUOTES) ?></p>
<?php endif; ?>
<?php if ($app['status'] === 'draft'): ?>
    <p><a class="btn" href="/inscription/<?= htmlspecialchars($app['token'], ENT_QUOTES) ?>/formule">Reprendre ma demande</a></p>
<?php endif; ?>
