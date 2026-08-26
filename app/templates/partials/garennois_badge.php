<?php /** @var string $residence */ ?>
<?php if ($residence === 'garennois'): ?><span class="badge-tag" title="Tarif Garennois (résident La Garenne-Colombes)">Garennois</span>
<?php elseif ($residence === 'hors-commune'): ?><span class="badge-tag badge-tag-blue" title="Tarif Hors commune (non-résident La Garenne-Colombes)">Non-Garennois</span>
<?php endif; ?>
