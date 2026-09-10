<?php
/**
 * @var array $bjUser, $exception (nullable), $active (nullable), $order (nullable), $creditNote (nullable), $assessment (nullable)
 * @var string $residence, $grantable, $csrf
 * @var App\Service\Season $season
 * @var App\Service\Season[] $seasons
 */
$money = fn (float $v): string => number_format($v, 2, ',', ' ') . ' €';
$gridLabel = fn (string $r): string => $r === 'garennois' ? 'Garennois' : 'Hors commune';
$name = trim(($bjUser['lastname'] ?? '') . ' ' . ($bjUser['firstname'] ?? ''));
$error = $_GET['erreur'] ?? '';
?>
<h1><?= htmlspecialchars($name, ENT_QUOTES) ?>
    <?= $this->fetch('partials/garennois_badge.php', [
        'residence' => $residence,
        'pricingResidence' => $active['pricing_residence'] ?? $residence,
    ]) ?></h1>
<p class="muted">Exception de tarif — saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?>.
    Une exception vaut pour une seule saison et doit être réaccordée chaque année.</p>

<?php if ($error === 'motif'): ?>
    <div class="alert">Le motif est obligatoire : il justifie l'exception dans le journal et sur l'avoir.</div>
<?php elseif ($error === 'avoir'): ?>
    <div class="alert">L'avoir n'a pas pu être émis — voir le motif indiqué ci-dessous.</div>
<?php endif; ?>

<table class="details">
    <tr><th>Email</th><td><?= htmlspecialchars((string) ($bjUser['email'] ?? ''), ENT_QUOTES) ?></td></tr>
    <tr><th>Code postal</th><td><?= htmlspecialchars((string) ($bjUser['postalcode'] ?? ''), ENT_QUOTES) ?>
        — résidence : <?= htmlspecialchars($gridLabel($residence), ENT_QUOTES) ?></td></tr>
    <tr><th>Tarif appliqué</th><td><strong><?= htmlspecialchars($gridLabel((string) ($active['pricing_residence'] ?? $residence)), ENT_QUOTES) ?></strong></td></tr>
</table>

<form method="get" class="form form-wide filters-inline">
    <fieldset>
        <legend>Saison</legend>
        <label for="saison">Saison</label>
        <select id="saison" name="saison" onchange="this.form.submit()">
            <?php foreach ($seasons as $s): ?>
                <option value="<?= $s->startYear ?>" <?= $s->startYear === $season->startYear ? 'selected' : '' ?>>
                    <?= htmlspecialchars($s->label(), ENT_QUOTES) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <noscript><button type="submit" class="btn-small">Afficher</button></noscript>
    </fieldset>
</form>

<h2>Exception</h2>
<?php if ($active !== null): ?>
    <p>Exception en vigueur — tarif <strong><?= htmlspecialchars($gridLabel((string) $active['pricing_residence']), ENT_QUOTES) ?></strong>
        pour la saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?>.<br>
        Motif : <?= htmlspecialchars((string) $active['reason'], ENT_QUOTES) ?><br>
        <span class="muted">Accordée par <?= htmlspecialchars((string) $active['granted_by'], ENT_QUOTES) ?>
            le <?= date('d/m/Y', strtotime((string) $active['granted_at'])) ?>.</span></p>

    <form method="post" action="/admin/exceptions-tarif/membre/<?= (int) $bjUser['user_id'] ?>" class="form form-wide">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="season" value="<?= $season->startYear ?>">
        <input type="hidden" name="action" value="revoke">
        <label for="revoke_reason">Motif de la révocation</label>
        <textarea id="revoke_reason" name="reason" rows="2" maxlength="500"></textarea>
        <button type="submit" class="btn-small" onclick="return confirm('Révoquer l\'exception pour cette saison ?')">Révoquer l'exception</button>
    </form>
<?php else: ?>
    <?php if ($exception !== null): ?>
        <p class="muted">Exception révoquée le <?= date('d/m/Y', strtotime((string) $exception['revoked_at'])) ?>
            par <?= htmlspecialchars((string) $exception['revoked_by'], ENT_QUOTES) ?><?=
            (string) $exception['revoke_reason'] !== '' ? ' — ' . htmlspecialchars((string) $exception['revoke_reason'], ENT_QUOTES) : '' ?>.</p>
    <?php endif; ?>
    <p class="muted">Ce membre est actuellement facturé au tarif <?= htmlspecialchars($gridLabel($residence), ENT_QUOTES) ?>,
        d'après son code postal. Accorder l'exception le fera passer au tarif
        <strong><?= htmlspecialchars($gridLabel($grantable), ENT_QUOTES) ?></strong> pour cette saison, sur toutes les formules
        <?php if ($grantable === 'garennois'): ?>— y compris l'abonnement Midi, réservé aux Garennois<?php endif; ?>.</p>

    <form method="post" action="/admin/exceptions-tarif/membre/<?= (int) $bjUser['user_id'] ?>" class="form form-wide">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="season" value="<?= $season->startYear ?>">
        <input type="hidden" name="action" value="grant">
        <input type="hidden" name="pricing_residence" value="<?= htmlspecialchars($grantable, ENT_QUOTES) ?>">
        <label for="grant_reason">Motif de l'exception *</label>
        <textarea id="grant_reason" name="reason" rows="2" maxlength="500" required></textarea>
        <button type="submit">Accorder le tarif <?= htmlspecialchars($gridLabel($grantable), ENT_QUOTES) ?></button>
    </form>
<?php endif; ?>

<h2>Régularisation</h2>
<?php if ($order === null): ?>
    <p class="muted">Ce membre n'a pas encore réglé sa saison <?= htmlspecialchars($season->label(), ENT_QUOTES) ?> :
        le tarif accordé s'appliquera directement à son renouvellement, sans avoir.</p>
<?php elseif ($creditNote !== null): ?>
    <p>Avoir <strong><?= htmlspecialchars((string) $creditNote['number'], ENT_QUOTES) ?></strong> émis le
        <?= date('d/m/Y', strtotime((string) $creditNote['issued_at'])) ?> —
        <strong><?= htmlspecialchars($money((float) $creditNote['amount']), ENT_QUOTES) ?></strong>
        à rembourser au membre.<br>
        <span class="muted">Émis par <?= htmlspecialchars((string) $creditNote['issued_by'], ENT_QUOTES) ?>,
            envoyé par email avec le PDF en pièce jointe.</span></p>
<?php elseif ($active === null): ?>
    <p class="muted">Accordez d'abord l'exception : l'avoir en découle.</p>
<?php elseif ($assessment !== null && $assessment['eligible']): ?>
    <p>La commande #<?= (int) $order['id'] ?> a été réglée
        <?= htmlspecialchars($money((float) $order['amount']), ENT_QUOTES) ?> au tarif
        <?= htmlspecialchars($gridLabel((string) ($order['pricing_residence'] ?: $order['residence'])), ENT_QUOTES) ?>,
        avant que l'exception ne soit accordée.
        Différence à rembourser : <strong><?= htmlspecialchars($money((float) $assessment['amount']), ENT_QUOTES) ?></strong>.</p>

    <form method="post" action="/admin/exceptions-tarif/membre/<?= (int) $bjUser['user_id'] ?>" class="form form-wide">
        <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        <input type="hidden" name="season" value="<?= $season->startYear ?>">
        <input type="hidden" name="action" value="credit_note">
        <label for="credit_reason">Motif porté sur l'avoir</label>
        <textarea id="credit_reason" name="reason" rows="2" maxlength="500"><?= htmlspecialchars((string) $active['reason'], ENT_QUOTES) ?></textarea>
        <button type="submit" onclick="return confirm('Émettre un avoir de <?= htmlspecialchars($money((float) $assessment['amount']), ENT_QUOTES) ?> ? Cette pièce est numérotée et envoyée au membre.')">
            Émettre l'avoir
        </button>
    </form>
<?php else: ?>
    <p class="muted">Pas d'avoir possible sur la commande #<?= (int) $order['id'] ?> :
        <?= htmlspecialchars((string) ($assessment['problem'] ?? ''), ENT_QUOTES) ?></p>
<?php endif; ?>

<p><a href="/admin/exceptions-tarif?saison=<?= $season->startYear ?>">← Exceptions de tarif</a>
   · <a href="/admin/membres">Annuaire des membres</a></p>
