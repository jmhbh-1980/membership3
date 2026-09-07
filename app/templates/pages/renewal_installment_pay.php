<?php
/** @var string $checkoutId, $reference  @var float $amount  @var int $installmentCount */
?>
<h1>Paiement — 1er versement</h1>
<p>Réglez votre 1<sup>er</sup> versement de <strong><?= number_format($amount, 2, ',', ' ') ?> €</strong> sur
    <?= $installmentCount ?>. Votre carte est enregistrée en toute sécurité par SumUp pour les prélèvements suivants —
    elle ne transite jamais par nos serveurs.</p>

<div id="sumup-card"></div>
<p id="installment-pay-error" class="alert" hidden></p>

<script type="text/javascript" src="https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js"></script>
<script type="text/javascript">
SumUpCard.mount({
    id: 'sumup-card',
    checkoutId: <?= json_encode($checkoutId) ?>,
    onResponse: function (type, body) {
        if (type === 'success') {
            window.location.href = '/paiement/retour/' + <?= json_encode($reference) ?>;
            return;
        }
        if (type === 'fail' || type === 'error') {
            var el = document.getElementById('installment-pay-error');
            el.textContent = 'Le paiement n\'a pas abouti. Vous pouvez réessayer, ou ';
            var link = document.createElement('a');
            link.href = '/espace/renouvellement';
            link.textContent = 'revenir au panier';
            el.appendChild(link);
            el.appendChild(document.createTextNode('.'));
            el.hidden = false;
        }
    },
});
</script>

<p><a href="/espace/renouvellement">← Annuler et revenir au panier</a></p>
