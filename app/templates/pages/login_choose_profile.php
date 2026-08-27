<?php /** @var array $candidates, string $csrf */ ?>
<h1>Qui êtes-vous ?</h1>
<p>Plusieurs profils du club utilisent cette adresse email. Sélectionnez le vôtre pour continuer.</p>
<form method="post" action="/connexion/profil" class="form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
    <?php foreach ($candidates as $c): ?>
        <button type="submit" name="bj_user_id" value="<?= (int) $c['user_id'] ?>" class="btn">
            <?= htmlspecialchars(trim($c['firstname'] . ' ' . $c['lastname']), ENT_QUOTES) ?>
            <?php if ($c['birthday'] !== '' && $c['birthday'] !== '0000-00-00'): ?>
                <br><span class="muted">né(e) le <?= date('d/m/Y', strtotime($c['birthday'])) ?></span>
            <?php endif; ?>
        </button>
    <?php endforeach; ?>
</form>
