<?php
/**
 * @var array{subscriptions:string[], licences:string[], singletons:string[]} $fields
 * @var array $labels, $table, $errors
 * @var array<string,string> $singletons
 * @var int $maxLength
 * @var bool $saved
 * @var string $csrf
 */
$v = fn ($x): string => htmlspecialchars((string) $x, ENT_QUOTES);
$sub = fn (string $group, string $key): string => (string) ($table[$group][$key] ?? '');
$one = fn (string $key): string => (string) ($table[$key] ?? '');
$orphan = fn (string $key, array $known): bool => !isset($known[$key]) || $known[$key] === $key;
?>
<h1>Descriptions sur les factures</h1>
<p class="muted">Texte court imprimé sous chaque ligne de facture, en italique, sous la
    désignation. Il décrit la prestation — la désignation, elle, est composée automatiquement
    (formule, tarif, 1ère inscription ou renouvellement, type de licence) et n'est pas modifiable ici.</p>
<p class="muted">Laisser vide n'imprime rien. Une modification ne s'applique qu'aux factures
    <strong>émises ensuite</strong> : les factures déjà générées sont des PDF figés.</p>

<?php if ($saved): ?>
    <div class="alert alert-ok">Descriptions enregistrées.</div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= $v($e) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" action="/admin/reglages/descriptions-factures" class="form form-wide">
    <input type="hidden" name="csrf" value="<?= $v($csrf) ?>">

    <fieldset>
        <legend>Abonnements</legend>
        <p class="muted">Imprimé sous la ligne « Cotisation ».</p>
        <?php foreach ($fields['subscriptions'] as $key): ?>
            <label for="sub-<?= $v($key) ?>">
                <?= $v($labels['subscriptions'][$key] ?? $key) ?>
                <?php if ($orphan($key, $labels['subscriptions'])): ?>
                    <span class="muted">(formule absente du barème en cours — conservée)</span>
                <?php endif; ?>
            </label>
            <textarea id="sub-<?= $v($key) ?>" name="blurb[subscriptions][<?= $v($key) ?>]"
                      rows="2" maxlength="<?= (int) $maxLength ?>"><?= $v($sub('subscriptions', $key)) ?></textarea>
        <?php endforeach; ?>
    </fieldset>

    <fieldset>
        <legend>Licences</legend>
        <p class="muted">Imprimé sous chaque ligne de licence.</p>
        <?php foreach ($fields['licences'] as $kind): ?>
            <label for="lic-<?= $v($kind) ?>">
                <?= $v($labels['licences'][$kind] ?? $kind) ?>
                <?php if ($orphan($kind, $labels['licences'])): ?>
                    <span class="muted">(licence absente du barème en cours — conservée)</span>
                <?php endif; ?>
            </label>
            <textarea id="lic-<?= $v($kind) ?>" name="blurb[licences][<?= $v($kind) ?>]"
                      rows="2" maxlength="<?= (int) $maxLength ?>"><?= $v($sub('licences', $kind)) ?></textarea>
        <?php endforeach; ?>
    </fieldset>

    <fieldset>
        <legend>Autres</legend>
        <?php foreach ($fields['singletons'] as $key): ?>
            <label for="one-<?= $v($key) ?>"><?= $v($singletons[$key] ?? $key) ?></label>
            <textarea id="one-<?= $v($key) ?>" name="blurb[singletons][<?= $v($key) ?>]"
                      rows="2" maxlength="<?= (int) $maxLength ?>"><?= $v($one($key)) ?></textarea>
        <?php endforeach; ?>
    </fieldset>

    <button type="submit">Enregistrer</button>
</form>

<p class="muted"><?= (int) $maxLength ?> caractères maximum par description. Les retours à la ligne
    sont ramenés à un espace : chaque description s'imprime sur une seule ligne.</p>

<p><a href="/admin">← Administration</a></p>
