<?php /** @var string $state, ?string $reason, \App\Service\Season $season, ?array $addOn, string $csrf, string $reglementHtml, ?string $shoesPolicyImageUrl, array $errors */ ?>
<h1>Cours collectifs</h1>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<?php if ($state === 'already_enrolled'): ?>
    <p>Vous êtes inscrit(e) aux cours collectifs pour la saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?>. Bonne séance !</p>
<?php elseif ($state === 'ineligible'): ?>
    <p><?= htmlspecialchars($reason, ENT_QUOTES) ?></p>
<?php else: ?>
    <?php $isNextSeason = $season->startYear !== \App\Service\Season::fromDate(new DateTimeImmutable())->startYear; ?>
    <p>
        <?php if ($isNextSeason): ?>
            Les cours collectifs ne reprennent pas avant septembre : votre inscription sera pour la saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?>, au tarif plein.
        <?php else: ?>
            Vous n'avez pas pris les cours collectifs lors de votre renouvellement ? Vous pouvez encore vous inscrire pour le reste de la saison.
        <?php endif; ?>
    </p>
    <table class="details">
        <tr><th><?= htmlspecialchars($addOn['label'], ENT_QUOTES) ?></th>
            <td><strong><?= number_format($addOn['amount'], 2, ',', ' ') ?> €</strong>
                <?= $isNextSeason ? '(tarif plein, saison ' . htmlspecialchars($season->label(), ENT_QUOTES) . ')' : '(tarif prorata au temps restant de la saison)' ?></td></tr>
    </table>
    <form method="post" action="/espace/cours-collectifs/checkout" class="form">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

        <?= $this->fetch('partials/reglement_acceptance.php', ['reglementHtml' => $reglementHtml]) ?>
        <?= $this->fetch('partials/shoes_policy_acceptance.php', ['shoesPolicyImageUrl' => $shoesPolicyImageUrl]) ?>

        <button type="submit">S'inscrire — <?= number_format($addOn['amount'], 2, ',', ' ') ?> €</button>
    </form>
<?php endif; ?>
