<?php
/** @var array $app @var App\Service\Season $currentSeason, $nextSeason @var ?string $backUrl */
?>
<h1>Choisir la formule</h1>
<p>Les inscriptions pour la saison <?= htmlspecialchars($nextSeason->label(), ENT_QUOTES) ?> sont ouvertes :
   choisissez entre le Pack été (saison <?= htmlspecialchars($currentSeason->label(), ENT_QUOTES) ?>, tarif forfaitaire)
   et une inscription directe pour la saison <?= htmlspecialchars($nextSeason->label(), ENT_QUOTES) ?> (tarif plein).</p>

<form method="post" action="/inscription/<?= htmlspecialchars($app['token'], ENT_QUOTES) ?>/formule-saison" id="summer-pack-choice-form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
</form>
<p>
    <button type="submit" form="summer-pack-choice-form" name="pack_choice" value="ete" class="btn btn-outline">Pack été — saison <?= htmlspecialchars($currentSeason->label(), ENT_QUOTES) ?></button>
    <button type="submit" form="summer-pack-choice-form" name="pack_choice" value="next" class="btn">Saison <?= htmlspecialchars($nextSeason->label(), ENT_QUOTES) ?></button>
</p>

<?php if ($backUrl !== null): ?>
    <p><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline btn-small">← Précédent</a></p>
<?php endif; ?>
