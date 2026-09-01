<?php /** @var ?string $shoesPolicyImageUrl */ ?>
<fieldset class="reglement-acceptance">
    <legend>Règles chaussures</legend>
    <?php if ($shoesPolicyImageUrl !== null): ?>
        <img src="<?= htmlspecialchars($shoesPolicyImageUrl, ENT_QUOTES) ?>" alt="Règles chaussures du club" class="reglement-image-preview">
    <?php else: ?>
        <p class="muted">Les règles chaussures n'ont pas encore été renseignées par le club.</p>
    <?php endif; ?>
    <label class="choice">
        <input type="checkbox" name="shoes_policy_accepted" value="1" required>
        J'ai pris connaissance des règles chaussures du club.
    </label>
</fieldset>
