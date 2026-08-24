<?php
/** @var array $app, $minor, $old, $errors  @var ?array $attestation */
$outcome = (string) ($old['outcome'] ?? ($attestation['outcome'] ?? ''));
?>
<h1>Santé du mineur</h1>
<?= $this->fetch('partials/wizard_steps.php', ['steps' => $steps]) ?>
<p><?= htmlspecialchars($minor['firstname'] . ' ' . $minor['lastname'], ENT_QUOTES) ?> étant mineur(e),
la réglementation fédérale exige un questionnaire de santé.</p>

<?php if ($attestation !== null): ?>
    <div class="alert alert-ok">✔ Cette étape est déjà complétée
        (<?= $attestation['outcome'] === 'all_negative' ? 'attestation signée' : 'certificat médical fourni' ?>).
        Vous pouvez la refaire ci-dessous ou <a href="/inscription/<?= htmlspecialchars($app['token'], ENT_QUOTES) ?>/recapitulatif">continuer</a>.</div>
<?php endif; ?>

<?php if ($errors !== []): ?>
    <div class="alert"><ul><?php foreach ($errors as $e): ?><li><?= htmlspecialchars($e, ENT_QUOTES) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<ol>
    <li>Faites renseigner par votre enfant (avec votre aide si besoin) le
        <a href="/assets/docs/questionnaire-sante-mineur.pdf" target="_blank">questionnaire de santé du sportif mineur (PDF)</a>.
        Il reste confidentiel et <strong>ne doit pas être remis au club</strong>.</li>
    <li>Selon les réponses, choisissez l'une des deux options ci-dessous.</li>
</ol>

<form method="post" enctype="multipart/form-data" class="form form-wide" id="sante-form">
    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">

    <fieldset>
        <legend>Résultat du questionnaire</legend>
        <label class="choice"><input type="radio" name="outcome" value="all_negative" <?= $outcome === 'all_negative' ? 'checked' : '' ?> required>
            Toutes les réponses sont <strong>NON</strong> — je signe l'attestation en ligne</label>
        <label class="choice"><input type="radio" name="outcome" value="certificate" <?= $outcome === 'certificate' ? 'checked' : '' ?>>
            Au moins une réponse est <strong>OUI</strong> — je fournis un certificat médical de moins de 6 mois</label>
    </fieldset>

    <fieldset id="attestation-block" hidden>
        <legend>Attestation du représentant légal</legend>
        <label class="choice"><input type="checkbox" name="read_questionnaire" value="1">
            J'atteste que <?= htmlspecialchars($minor['firstname'], ENT_QUOTES) ?> a renseigné le questionnaire de santé
            et a répondu <strong>par la négative à l'ensemble des rubriques</strong>.</label>
        <div class="grid2">
            <div><label for="guardian_fullname">Représentant légal (nom, prénom) *</label>
                <input id="guardian_fullname" name="guardian_fullname" maxlength="100"
                       value="<?= htmlspecialchars((string) ($old['guardian_fullname'] ?? $minor['guardian_fullname']), ENT_QUOTES) ?>"></div>
            <div><label for="place">Fait à *</label>
                <input id="place" name="place" maxlength="100" value="<?= htmlspecialchars((string) ($old['place'] ?? $minor['city']), ENT_QUOTES) ?>"></div>
        </div>
        <label>Signature (tracez au doigt ou à la souris) *</label>
        <canvas id="sig-canvas" width="500" height="180"></canvas>
        <div><button type="button" class="btn btn-small" id="sig-clear">Effacer</button></div>
        <input type="hidden" name="signature" id="sig-data">
    </fieldset>

    <fieldset id="certificate-block" hidden>
        <legend>Certificat médical</legend>
        <p>Certificat médical de non contre-indication à la pratique du squash, daté de moins de 6 mois.</p>
        <input type="file" name="medical_certificate" accept="application/pdf,image/jpeg,image/png">
    </fieldset>

    <div class="wizard-nav">
        <?php if ($backUrl !== null): ?><a href="<?= htmlspecialchars($backUrl, ENT_QUOTES) ?>" class="btn btn-outline btn-small">← Précédent</a><?php endif; ?>
        <button type="submit">Valider et continuer</button>
    </div>
</form>

<script>
(function () {
    var radios = document.querySelectorAll('input[name="outcome"]');
    function refresh() {
        var value = (document.querySelector('input[name="outcome"]:checked') || {}).value;
        document.getElementById('attestation-block').hidden = value !== 'all_negative';
        document.getElementById('certificate-block').hidden = value !== 'certificate';
    }
    radios.forEach(function (r) { r.addEventListener('change', refresh); });
    refresh();

    var canvas = document.getElementById('sig-canvas');
    var ctx = canvas.getContext('2d');
    var drawing = false, dirty = false;
    ctx.lineWidth = 2; ctx.lineCap = 'round'; ctx.strokeStyle = '#1c2430';
    function pos(e) {
        var r = canvas.getBoundingClientRect();
        var p = e.touches ? e.touches[0] : e;
        return { x: (p.clientX - r.left) * canvas.width / r.width, y: (p.clientY - r.top) * canvas.height / r.height };
    }
    function start(e) { drawing = true; dirty = true; var p = pos(e); ctx.beginPath(); ctx.moveTo(p.x, p.y); e.preventDefault(); }
    function move(e) { if (!drawing) return; var p = pos(e); ctx.lineTo(p.x, p.y); ctx.stroke(); e.preventDefault(); }
    function end() { drawing = false; }
    canvas.addEventListener('mousedown', start); canvas.addEventListener('mousemove', move); window.addEventListener('mouseup', end);
    canvas.addEventListener('touchstart', start); canvas.addEventListener('touchmove', move); canvas.addEventListener('touchend', end);
    document.getElementById('sig-clear').addEventListener('click', function () {
        ctx.clearRect(0, 0, canvas.width, canvas.height); dirty = false;
    });
    document.getElementById('sante-form').addEventListener('submit', function () {
        document.getElementById('sig-data').value = dirty ? canvas.toDataURL('image/png') : '';
    });
})();
</script>
