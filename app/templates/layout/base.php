<?php
/**
 * Base layout. Variables:
 * - string $title     page title
 * - string $content   rendered page body (provided by PhpRenderer layouts)
 * - string $clubName  from renderer attributes
 */
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($title ?? '', ENT_QUOTES) ?> — <?= htmlspecialchars($clubName, ENT_QUOTES) ?></title>
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="site-header">
    <a class="brand" href="/"><?= htmlspecialchars($clubName, ENT_QUOTES) ?></a>
    <nav class="site-nav">
        <?php $sessionUser = $_SESSION['user'] ?? null; ?>
        <?php if ($sessionUser !== null): ?>
            <a href="/espace">Mon espace</a>
            <?php if (($sessionUser['role'] ?? '') === 'admin'): ?><a href="/admin">Administration</a><?php endif; ?>
            <a href="/deconnexion">Déconnexion</a>
        <?php else: ?>
            <a href="/connexion">Connexion</a>
        <?php endif; ?>
    </nav>
</header>
<main class="container">
<?= $content ?>
</main>
<footer class="site-footer">
    <p><?= htmlspecialchars($clubName, ENT_QUOTES) ?> — saison <?= date('n') >= 9 ? date('Y') . '-' . (date('Y') + 1) : (date('Y') - 1) . '-' . date('Y') ?></p>
</footer>
<?php if (($bugReportModeEnabled ?? false) && ($sessionUser === null || ($sessionUser['role'] ?? '') !== 'admin')): ?>
<button type="button" id="bug-report-bubble" class="bug-report-bubble" aria-label="Signaler un problème">🐞</button>
<div id="bug-report-modal" class="modal-overlay bug-report-modal" hidden>
    <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="bug-report-title">
        <h2 id="bug-report-title">Signaler un problème</h2>
        <form id="bug-report-form" class="form">
            <label for="br-firstname">Prénom</label>
            <input type="text" id="br-firstname" name="firstname" required maxlength="80">
            <label for="br-lastname">Nom</label>
            <input type="text" id="br-lastname" name="lastname" required maxlength="80">
            <label for="br-comment">Description du problème</label>
            <textarea id="br-comment" name="comment" required maxlength="4000"></textarea>
            <p class="bug-report-status" aria-live="polite"></p>
            <div class="wizard-nav">
                <button type="submit" class="btn">Envoyer</button>
                <button type="button" class="btn btn-outline" id="bug-report-cancel">Annuler</button>
            </div>
        </form>
    </div>
</div>
<script>
    window.BUG_REPORT_CONFIG = {
        firstname: <?= json_encode($sessionUser['firstname'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        lastname: <?= json_encode($sessionUser['lastname'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        csrf: <?= json_encode(\App\Support\Csrf::token(), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
        endpoint: '/signalement'
    };
</script>
<script src="/assets/vendor/html2canvas.min.js" defer></script>
<script src="/assets/bug-report.js" defer></script>
<?php endif; ?>
</body>
</html>
