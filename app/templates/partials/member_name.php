<?php
/**
 * @var string $name      already the display text (any order of first/last) — escaped here, not by the caller
 * @var int    $bjUserId  the member's Balle Jaune id, or 0/absent when this person isn't a member yet
 *                        (e.g. a join application still pending fulfillment) — in that case there is no
 *                        profile page to link to, so the name renders as plain text.
 */
$bjUserId = (int) ($bjUserId ?? 0);
?>
<?php if ($name === ''): ?>—
<?php elseif ($bjUserId > 0): ?><a href="/admin/membres/<?= $bjUserId ?>"><?= htmlspecialchars($name, ENT_QUOTES) ?></a>
<?php else: ?><?= htmlspecialchars($name, ENT_QUOTES) ?>
<?php endif; ?>
