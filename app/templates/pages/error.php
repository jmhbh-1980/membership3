<?php
/**
 * Error page. Variables:
 * - bool $notFound
 * - ?string $detail  exception details, only populated in dev
 */
?>
<h1><?= $notFound ? 'Page introuvable' : 'Une erreur est survenue' ?></h1>
<?php if ($notFound): ?>
    <p>La page demandée n'existe pas ou n'est plus disponible.</p>
<?php else: ?>
    <p>Nous sommes désolés, une erreur inattendue s'est produite. Merci de réessayer plus tard.</p>
<?php endif; ?>
<p><a href="/">Retour à l'accueil</a></p>
<?php if (!empty($detail)): ?>
    <pre class="error-detail"><?= htmlspecialchars($detail, ENT_QUOTES) ?></pre>
<?php endif; ?>
