<?php /** @var array{name:string, code_banque:string, account_number:string, bic:string, iban:string} $bank, string $csrf */ ?>
<h1>Coordonnées bancaires</h1>
<p class="muted">Ces coordonnées sont communiquées aux adhérents qui choisissent de payer par virement, et affichées
    sur les factures émises par le club.</p>

<form method="post" action="/admin/reglages/virement" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <label for="name">Nom du bénéficiaire</label>
    <input type="text" id="name" name="name" maxlength="150" value="<?= htmlspecialchars($bank['name'], ENT_QUOTES) ?>">

    <label for="iban">IBAN</label>
    <input type="text" id="iban" name="iban" maxlength="34" value="<?= htmlspecialchars($bank['iban'], ENT_QUOTES) ?>">

    <label for="bic">BIC</label>
    <input type="text" id="bic" name="bic" maxlength="11" value="<?= htmlspecialchars($bank['bic'], ENT_QUOTES) ?>">

    <label for="code_banque">Code banque</label>
    <input type="text" id="code_banque" name="code_banque" maxlength="50" value="<?= htmlspecialchars($bank['code_banque'], ENT_QUOTES) ?>">

    <label for="account_number">Numéro de compte</label>
    <input type="text" id="account_number" name="account_number" maxlength="50" value="<?= htmlspecialchars($bank['account_number'], ENT_QUOTES) ?>">

    <button type="submit">Enregistrer</button>
</form>

<p><a href="/admin">← Administration</a></p>
