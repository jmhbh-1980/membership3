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
</body>
</html>
