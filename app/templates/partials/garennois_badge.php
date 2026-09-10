<?php
/**
 * @var string $residence        where they actually live ('garennois' | 'hors-commune' | '')
 * @var string $pricingResidence optional — the grid they are actually priced at. When it
 *                               differs from $residence an admin granted an exception, and
 *                               the badge must say so: the factual residence still drives
 *                               sorting, campaign priority and the justificatif requirement,
 *                               so a badge showing only the granted tariff would misread.
 */
$pricingResidence = $pricingResidence ?? '';
$hasException = $pricingResidence !== '' && $pricingResidence !== $residence;
$grantedLabel = $pricingResidence === 'garennois' ? 'Garennois' : 'Hors commune';
?>
<?php if ($residence === 'garennois'): ?><span class="badge-tag" title="Tarif Garennois (résident La Garenne-Colombes)">Garennois</span>
<?php elseif ($residence === 'hors-commune'): ?><span class="badge-tag badge-tag-blue" title="Tarif Hors commune (non-résident La Garenne-Colombes)">Non-Garennois</span>
<?php endif; ?>
<?php if ($hasException): ?><span class="badge-tag badge-tag-blue" title="Exception accordée par le club : facturé au tarif <?= htmlspecialchars($grantedLabel, ENT_QUOTES) ?> pour cette saison">tarif <?= htmlspecialchars($grantedLabel, ENT_QUOTES) ?></span><?php endif; ?>
