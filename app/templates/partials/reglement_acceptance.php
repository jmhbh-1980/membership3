<?php /** @var string $reglementHtml */ ?>
<fieldset class="reglement-acceptance">
    <legend>Règlement intérieur</legend>
    <div class="reglement-box"><?= $reglementHtml !== ''
        ? $reglementHtml
        : '<p class="muted">Le règlement intérieur n\'a pas encore été renseigné par le club.</p>' ?></div>
    <label class="choice">
        <input type="checkbox" name="reglement_accepted" value="1" required>
        J'ai lu et j'accepte le règlement intérieur du club.
    </label>
</fieldset>
