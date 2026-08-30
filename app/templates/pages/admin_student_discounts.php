<?php
/** @var array[] $orders  each enriched with 'name' and 'breakdown' (OrderBreakdownService shape) */
$kinds = ['join' => 'Adhésion', 'renewal' => 'Renouvellement'];
?>
<h1>Réductions étudiant en attente</h1>
<p class="muted">Vérifiez le certificat de scolarité avant de valider — la réduction de 50 % s'applique à la
    cotisation et aux cours collectifs, jamais à la licence. Refuser laisse l'adhérent poursuivre au tarif plein
    ou transmettre un nouveau certificat.</p>

<?php if ($orders === []): ?>
    <p>Aucune demande en attente.</p>
<?php else: ?>
    <?php foreach ($orders as $o): ?>
        <fieldset>
            <table class="details">
                <tr><th>Type</th><td><?= $kinds[$o['kind']] ?? $o['kind'] ?></td></tr>
                <tr><th>Nom</th><td><?= $o['name'] !== '' ? htmlspecialchars($o['name'], ENT_QUOTES) : '—' ?></td></tr>
                <tr><th>Email</th><td><?= htmlspecialchars($o['email'], ENT_QUOTES) ?></td></tr>
                <tr><th>Certificat</th><td><a href="/admin/reduction-etudiant/<?= (int) $o['id'] ?>/certificat" target="_blank">Voir le certificat</a></td></tr>
                <?php foreach ($o['breakdown']['lines'] as $line): ?>
                    <tr><th><?= htmlspecialchars((string) $line['label'], ENT_QUOTES) ?></th><td><?= number_format((float) $line['amount'], 2, ',', ' ') ?> €</td></tr>
                <?php endforeach; ?>
                <tr><th><strong>Total</strong></th><td><strong><?= number_format((float) $o['amount'], 2, ',', ' ') ?> €</strong></td></tr>
                <tr><th>Demandée le</th><td><?= date('d/m/Y H:i', strtotime($o['created_at'])) ?></td></tr>
            </table>
            <form method="post" action="/admin/reduction-etudiant/<?= (int) $o['id'] ?>/decision" class="form form-wide">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                <label>Motif (transmis à l'adhérent en cas de refus)</label>
                <textarea name="reason" rows="2" maxlength="500"></textarea>
                <div>
                    <button type="submit" name="decision" value="approve">Approuver</button>
                    <button type="submit" name="decision" value="refuse" class="btn-danger">Refuser</button>
                </div>
            </form>
        </fieldset>
    <?php endforeach; ?>
<?php endif; ?>

<p><a href="/admin/commandes">← Commandes</a> · <a href="/admin">← Administration</a></p>
