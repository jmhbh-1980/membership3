<?php
/**
 * The other half of a couple, drawn the same way on every admin page, so an
 * admin looking at either partner always sees the other and can jump to them.
 *
 * @var ?array $partner      ['name' => string, 'bjUserId' => int] from App\Service\CoupleLinks — null
 *                           when the record says "couple" but links nobody
 * @var string $lead         optional words before the name: 'avec' by default; 'réglée par' on the
 *                           partner's side of an order, where who paid is what matters
 * @var bool   $missingIsGap optional, default true: a missing partner is a data problem to flag.
 *                           False where it is simply not filled in yet (a draft application).
 */
$lead = $lead ?? 'avec';
$missingIsGap = $missingIsGap ?? true;
?>
<span class="couple-tag"><span class="badge-tag badge-tag-couple">Couple</span>
<?php if ($partner === null && $missingIsGap): ?><span class="badge-tag badge-warning" title="Enregistré en couple, mais aucun(e) conjoint(e) n'est rattaché(e).">conjoint(e) non identifié(e)</span>
<?php elseif ($partner === null): ?><span class="muted">conjoint(e) pas encore renseigné(e)</span>
<?php else: ?><span class="muted"><?= htmlspecialchars($lead, ENT_QUOTES) ?></span> <?= $this->fetch('partials/member_name.php', ['name' => $partner['name'], 'bjUserId' => $partner['bjUserId']]) ?>
<?php endif; ?></span>
