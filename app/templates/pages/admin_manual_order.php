<?php
/**
 * « Paiement hors application » (AdminManualOrderController).
 *
 * @var array $bjUser       the member, from Balle Jaune
 * @var array $form         the values to show, as typed (see formValues())
 * @var App\Service\Season[] $seasons  seasons whose price list is published
 * @var App\Service\Season $season     the one selected
 * @var array{residence: string, pricingResidence: string} $residences
 * @var array $subscriptions catalogue formulas available at the grid charged
 * @var ?array $partner      the partner's BJ user, when one is linked
 * @var ?array $pack         the season's ticket pack
 * @var ?array $priced       after Calculer: grid lines + signature
 * @var ?array $refusal      why this can't be recorded: message + orderId
 * @var string[] $errors
 * @var string $csrf
 *
 * Without JavaScript every purchase's fieldset stays visible and the total is
 * the server's; the script only hides what doesn't apply and adds up the
 * amounts as they are typed.
 */
use App\Service\ManualOrderService;

$e = fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES);
$id = (int) $bjUser['user_id'];
$name = trim(($bjUser['lastname'] ?? '') . ' ' . ($bjUser['firstname'] ?? ''));
$money = fn (float $v): string => number_format($v, 2, ',', ' ') . ' €';
$typed = fn (float $v): string => number_format($v, 2, ',', '');
$gridLabel = fn (string $r): string => $r === 'garennois' ? 'Garennois' : 'Hors commune';
$checked = fn (bool $on): string => $on ? 'checked' : '';
$purchase = $form['purchase'];
$hasException = $residences['pricingResidence'] !== $residences['residence'];
?>
<h1>Paiement hors application</h1>
<p class="muted">Pour <a href="/admin/membres/<?= $id ?>"><?= $e($name) ?></a> : enregistre ce qu'il ou elle a réglé
    directement au club (espèces, chèque, virement, carte sur un autre terminal, coupons sport…) et émet la facture
    numérotée. Rien n'est modifié dans Balle Jaune : l'adhésion, la licence ou les crédits s'y saisissent toujours à la main.</p>

<?php if ($errors !== []): ?>
    <div class="alert"><?php foreach ($errors as $error): ?><p><?= $e($error) ?></p><?php endforeach; ?></div>
<?php endif; ?>
<?php if ($refusal !== null): ?>
    <div class="alert"><?= $e($refusal['message']) ?>
        <?php if ($refusal['orderId'] !== null): ?>
            Voir la <a href="/admin/commandes/<?= (int) $refusal['orderId'] ?>">commande #<?= (int) $refusal['orderId'] ?></a> :
            si c'est une erreur, elle peut y être supprimée tant que sa facture est la dernière émise.
        <?php endif; ?></div>
<?php endif; ?>

<form method="post" action="/admin/membres/<?= $id ?>/paiement-hors-appli" class="form form-wide" id="manual-order-form">
    <input type="hidden" name="csrf" value="<?= $e($csrf) ?>">

    <fieldset>
        <legend>Achat</legend>
        <?php foreach (ManualOrderService::PURCHASES as $value => $label): ?>
            <label class="choice"><input type="radio" name="purchase" value="<?= $e($value) ?>" <?= $checked($purchase === $value) ?>> <?= $e($label) ?></label>
        <?php endforeach; ?>
    </fieldset>

    <div class="grid2">
        <div><label for="season">Saison</label>
            <select id="season" name="season">
                <?php foreach ($seasons as $s): ?>
                    <option value="<?= $s->startYear ?>" <?= $s->startYear === $season->startYear ? 'selected' : '' ?>><?= $e($s->label()) ?></option>
                <?php endforeach; ?>
            </select></div>
        <div><label for="effective_date">Date d'effet</label>
            <input type="date" id="effective_date" name="effective_date" value="<?= $e($form['effective_date']) ?>" aria-describedby="effective-hint">
            <span class="muted" id="effective-hint">Vide = date du paiement. Compte pour le prorata à partir d'octobre.</span></div>
    </div>

    <fieldset data-purchase="<?= ManualOrderService::MEMBERSHIP ?>">
        <legend>Adhésion</legend>
        <label for="subscription">Formule</label>
        <select id="subscription" name="subscription">
            <option value="">—</option>
            <?php foreach ($subscriptions as $key => $s): ?>
                <option value="<?= $e($key) ?>" <?= $form['subscription'] === $key ? 'selected' : '' ?>><?= $e($s['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <div>
            <label class="choice"><input type="radio" name="tarif" value="renouvellement" <?= $checked($form['tarif'] === 'renouvellement') ?>> Tarif renouvellement</label>
            <label class="choice"><input type="radio" name="tarif" value="premiere" <?= $checked($form['tarif'] === 'premiere') ?>> Tarif 1ère inscription</label>
        </div>
        <p class="muted">Résidence : <?= $e($gridLabel($residences['residence'])) ?> (<?= $e($bjUser['postalcode'] ?? '—') ?>).
            <?= $hasException
                ? 'Facturé au tarif <strong>' . $e($gridLabel($residences['pricingResidence'])) . '</strong> : exception accordée pour la saison ' . $e($season->label()) . '.'
                : 'Pas d\'exception de tarif pour la saison ' . $e($season->label()) . '.' ?>
            <a href="/admin/exceptions-tarif/membre/<?= $id ?>">Exception de tarif</a></p>

        <label class="choice"><input type="checkbox" name="competitor" value="1" <?= $checked($form['competitor']) ?>> Compétiteur (licence fédérale au lieu de Pass)</label>
        <label class="choice"><input type="checkbox" name="licence_removed" value="1" <?= $checked($form['licence_removed']) ?>> Licence retirée (aucune licence facturée)</label>
        <label for="licence_removal_reason">Motif du retrait de licence</label>
        <input id="licence_removal_reason" name="licence_removal_reason" maxlength="500" value="<?= $e($form['licence_removal_reason']) ?>">

        <label for="lessons">Cours collectifs</label>
        <select id="lessons" name="lessons">
            <?php foreach ([0 => 'Aucun', 1 => '1 personne', 2 => '2 personnes (couple)'] as $n => $label): ?>
                <option value="<?= $n ?>" <?= $form['lessons'] === $n ? 'selected' : '' ?>><?= $label ?></option>
            <?php endforeach; ?>
        </select>
        <label class="choice"><input type="checkbox" name="summer_pack" value="1" <?= $checked($form['summer_pack']) ?>> Pack été (cotisation forfaitaire de fin de saison)</label>

        <?php if ($partner !== null): ?>
            <label class="choice"><input type="checkbox" name="couple" value="1" <?= $checked($form['couple']) ?>>
                En couple avec <?= $e(trim(($partner['firstname'] ?? '') . ' ' . ($partner['lastname'] ?? ''))) ?> (un seul règlement pour les deux)</label>
            <label class="choice"><input type="checkbox" name="partner_competitor" value="1" <?= $checked($form['partner_competitor']) ?>> Conjoint(e) compétiteur(trice)</label>
            <label class="choice"><input type="checkbox" name="partner_licence_removed" value="1" <?= $checked($form['partner_licence_removed']) ?>> Licence du/de la conjoint(e) retirée</label>
            <label for="partner_licence_removal_reason">Motif du retrait (conjoint(e))</label>
            <input id="partner_licence_removal_reason" name="partner_licence_removal_reason" maxlength="500" value="<?= $e($form['partner_licence_removal_reason']) ?>">
        <?php else: ?>
            <p class="muted">Aucun(e) conjoint(e) rattaché(e) dans Balle Jaune : adhésion individuelle uniquement.</p>
        <?php endif; ?>
    </fieldset>

    <fieldset data-purchase="<?= ManualOrderService::LESSONS ?>">
        <legend>Cours collectifs seuls</legend>
        <p class="muted">Tarif de la saison, réduit au prorata selon la date d'effet. Le membre est inscrit sur la liste des cours collectifs.</p>
    </fieldset>

    <fieldset data-purchase="<?= ManualOrderService::CREDITS ?>">
        <legend>Crédits</legend>
        <?php if ($pack !== null && $pack['price'] !== null): ?>
            <label for="packs">Nombre de packs — <?= $e($pack['label']) ?>, <?= $money((float) $pack['price']) ?> le pack</label>
            <input type="number" id="packs" name="packs" min="1" max="20" value="<?= (int) $form['packs'] ?>">
        <?php else: ?>
            <p class="muted">Pas de Formule Tickets en vente pour la saison <?= $e($season->label()) ?>.</p>
        <?php endif; ?>
        <p class="muted">Les crédits ne sont pas ajoutés dans Balle Jaune : ajoutez-les à la main sur la fiche du membre.</p>
    </fieldset>

    <fieldset>
        <legend>Paiement</legend>
        <div class="grid2">
            <div><label for="payment_date">Date du paiement</label>
                <input type="date" id="payment_date" name="payment_date" required max="<?= date('Y-m-d') ?>" value="<?= $e($form['payment_date']) ?>"></div>
            <div><label for="payment_mode">Mode de paiement</label>
                <select id="payment_mode" name="payment_mode">
                    <option value="">—</option>
                    <?php foreach (ManualOrderService::PAYMENT_METHODS as $key => $label): ?>
                        <option value="<?= $e($key) ?>" <?= $form['payment_mode'] === $key ? 'selected' : '' ?>><?= $e($label) ?></option>
                    <?php endforeach; ?>
                </select></div>
        </div>
        <p class="muted">La date et le mode de paiement sont imprimés sur la facture.</p>
        <label for="note">Note (facultative, visible seulement sur la commande)</label>
        <textarea id="note" name="note" rows="2" maxlength="500"><?= $e($form['note']) ?></textarea>
    </fieldset>

    <?php if ($priced !== null && $refusal === null): ?>
        <h2>Montants</h2>
        <p class="muted">Pré-remplis au tarif. Changez un montant si le club a convenu d'un autre prix : la facture indique alors
            le tarif et la réduction.</p>
        <input type="hidden" name="signature" value="<?= $e($priced['signature']) ?>">
        <table class="details" id="manual-order-lines">
            <tr><th>Ligne</th><th>Tarif</th><th>Montant facturé</th></tr>
            <?php foreach ($priced['lines'] as $i => $line): ?>
                <?php $value = $form['amounts'][$i] ?? ''; ?>
                <tr>
                    <td><?= $e($line['label']) ?></td>
                    <td class="nowrap"><?= $money((float) $line['amount']) ?></td>
                    <td><input name="amounts[]" inputmode="decimal" class="amount" aria-label="Montant facturé — <?= $e($line['label']) ?>"
                        value="<?= $e($value !== '' ? $value : $typed((float) $line['amount'])) ?>" size="8"></td>
                </tr>
            <?php endforeach; ?>
            <tr><th>Total</th><td></td><td><strong id="manual-order-total"><?= $money(array_sum(array_map(
                fn (array $l, int $i): float => ($form['amounts'][$i] ?? '') !== '' && is_numeric(str_replace(',', '.', $form['amounts'][$i]))
                    ? (float) str_replace(',', '.', $form['amounts'][$i]) : (float) $l['amount'],
                $priced['lines'], array_keys($priced['lines'])))) ?></strong></td></tr>
        </table>
    <?php endif; ?>

    <div class="form-inline">
        <button type="submit" name="step" value="calculer" class="btn-outline">Calculer</button>
        <?php if ($priced !== null && $refusal === null): ?>
            <button type="submit" name="step" value="creer">Créer la commande et la facture</button>
        <?php endif; ?>
    </div>
</form>
<p><a href="/admin/membres/<?= $id ?>">← Fiche adhérent</a></p>

<script>
(function () {
    var form = document.getElementById('manual-order-form');
    var sections = [].slice.call(form.querySelectorAll('fieldset[data-purchase]'));
    function showPurchase() {
        var chosen = form.querySelector('input[name="purchase"]:checked');
        sections.forEach(function (s) { s.hidden = !chosen || s.dataset.purchase !== chosen.value; });
    }
    form.querySelectorAll('input[name="purchase"]').forEach(function (r) { r.addEventListener('change', showPurchase); });
    showPurchase();

    var total = document.getElementById('manual-order-total');
    if (!total) { return; }
    var amounts = [].slice.call(form.querySelectorAll('input.amount'));
    function addUp() {
        var sum = amounts.reduce(function (acc, input) {
            var v = parseFloat(input.value.replace(/\s/g, '').replace(',', '.'));
            return acc + (isNaN(v) ? 0 : v);
        }, 0);
        total.textContent = sum.toLocaleString('fr-FR', {minimumFractionDigits: 2, maximumFractionDigits: 2}) + ' €';
    }
    amounts.forEach(function (input) { input.addEventListener('input', addUp); });
})();
</script>
