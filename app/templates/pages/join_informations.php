<?php
/** @var array $app, $old, $errors */
$v = fn (string $k) => htmlspecialchars((string) ($old[$k] ?? ''), ENT_QUOTES);
?>
<?php if (!empty($existingMember)): ?>
    <div class="modal-overlay">
        <div class="modal-box">
            <h2>Vous êtes déjà adhérent(e)</h2>
            <p>Cette adresse email correspond à un compte déjà existant au club. Pour reprendre votre abonnement,
                connectez-vous à votre espace membre et utilisez le renouvellement plutôt qu'une nouvelle demande d'adhésion.</p>
            <a href="/" class="btn">Retour au début</a>
        </div>
    </div>
<?php endif; ?>
<h1>Vos informations</h1>
<?= $this->fetch('partials/wizard_steps.php', ['steps' => $steps]) ?>
<p>Corrigez vos informations si besoin.</p>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="/inscription/<?= htmlspecialchars($app['token'], ENT_QUOTES) ?>/informations" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <div class="grid2">
        <div><label for="firstname">Prénom *</label><input id="firstname" name="firstname" required maxlength="50" value="<?= $v('firstname') ?>"></div>
        <div><label for="lastname">Nom *</label><input id="lastname" name="lastname" required maxlength="50" value="<?= $v('lastname') ?>"></div>
    </div>
    <div class="grid2">
        <div><label for="birthdate">Date de naissance *</label><input type="date" id="birthdate" name="birthdate" required value="<?= $v('birthdate') ?>"></div>
        <div><label for="sex">Sexe *</label>
            <select id="sex" name="sex" required>
                <option value="">—</option>
                <option value="M" <?= ($old['sex'] ?? '') === 'M' ? 'selected' : '' ?>>Homme</option>
                <option value="W" <?= ($old['sex'] ?? '') === 'W' ? 'selected' : '' ?>>Femme</option>
            </select>
        </div>
    </div>
    <div class="grid2">
        <div><label for="email">Email *</label><input type="email" id="email" name="email" required value="<?= $v('email') ?>"></div>
        <div><label for="phone">Téléphone *</label><input id="phone" name="phone" required maxlength="40" value="<?= $v('phone') ?>"></div>
    </div>
    <label for="address">Adresse *</label><input id="address" name="address" required value="<?= $v('address') ?>">
    <div class="grid2">
        <div><label for="postalcode">Code postal *</label><input id="postalcode" name="postalcode" required pattern="\d{5}" value="<?= $v('postalcode') ?>"></div>
        <div><label for="city">Ville *</label><input id="city" name="city" required value="<?= $v('city') ?>"></div>
    </div>
    <p class="muted">Résidents de La Garenne-Colombes (92250)&nbsp;: tarif Garennois, sur présentation d'un justificatif de domicile.</p>
    <p class="muted" id="minor-note" hidden>Si vous êtes mineur·e, l'étape suivante vous demandera les coordonnées de votre représentant légal.</p>

    <button type="submit">Enregistrer et continuer</button>
</form>

<script>
(function () {
    var birth = document.getElementById('birthdate');
    var note = document.getElementById('minor-note');
    function refresh() {
        if (!birth.value) { note.hidden = true; return; }
        var b = new Date(birth.value), now = new Date();
        var age = now.getFullYear() - b.getFullYear() - ((now.getMonth() < b.getMonth() || (now.getMonth() === b.getMonth() && now.getDate() < b.getDate())) ? 1 : 0);
        note.hidden = age >= 18;
    }
    birth.addEventListener('change', refresh);
    birth.addEventListener('input', refresh); // keyboard typing / some autofill paths don't fire 'change'
    refresh();
})();
</script>
