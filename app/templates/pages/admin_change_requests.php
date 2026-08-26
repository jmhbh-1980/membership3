<?php
/** @var array[] $requests  @var array $subscriptions  @var array $liveLabel  @var bool $archived  @var ?int $archivedCount */
?>
<h1><?= $archived ? 'Historique des changements d\'abonnement' : 'Demandes de changement d\'abonnement' ?></h1>

<?php if ($archived): ?>

    <?php if ($requests === []): ?>
        <p>Aucune demande archivée.</p>
    <?php else: ?>
        <?php foreach ($requests as $req): ?>
            <fieldset>
                <?= $this->fetch('partials/change_request_details.php', ['req' => $req, 'subscriptions' => $subscriptions, 'liveLabel' => $liveLabel]) ?>
            </fieldset>
        <?php endforeach; ?>
    <?php endif; ?>
    <p><a href="/admin/changements">← Demandes actives</a></p>

<?php else: ?>

    <?php
    $pending = array_filter($requests, fn (array $r) => $r['status'] === 'pending');
    $approved = array_filter($requests, fn (array $r) => $r['status'] === 'approved');
    ?>
    <h2>En attente</h2>
    <?php if ($pending === []): ?>
        <p>Aucune demande en attente.</p>
    <?php else: ?>
        <?php foreach ($pending as $req): ?>
            <fieldset>
                <?= $this->fetch('partials/change_request_details.php', ['req' => $req, 'subscriptions' => $subscriptions, 'liveLabel' => $liveLabel]) ?>
                <form method="post" action="/admin/changements/<?= (int) $req['id'] ?>/decision" class="form form-wide">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                    <label>Note (transmise à l'adhérent)</label>
                    <textarea name="note" rows="2" maxlength="500"></textarea>
                    <div>
                        <button type="submit" name="decision" value="approve">Accepter</button>
                        <button type="submit" name="decision" value="refuse" class="btn-danger">Refuser</button>
                    </div>
                </form>
            </fieldset>
        <?php endforeach; ?>
    <?php endif; ?>

    <h2>Approuvées — en attente de paiement (<?= count($approved) ?>)</h2>
    <p class="muted">Déjà acceptées, mais l'adhérent n'a pas encore payé. Rejeter une demande ici annule cette
        acceptation : l'adhérent reverra son parcours de renouvellement normal (formule, puis licence si besoin) au
        lieu de la formule déjà pré-remplie — utile pour débloquer un compte resté coincé sur une approbation devenue
        caduque.</p>
    <?php if ($approved === []): ?>
        <p>Aucune demande approuvée en attente de paiement.</p>
    <?php else: ?>
        <?php foreach ($approved as $req): ?>
            <fieldset>
                <?= $this->fetch('partials/change_request_details.php', ['req' => $req, 'subscriptions' => $subscriptions, 'liveLabel' => $liveLabel]) ?>
                <form method="post" action="/admin/changements/<?= (int) $req['id'] ?>/decision" class="form form-wide"
                      onsubmit="return confirm('Rejeter cette demande déjà approuvée ? Ceci annule la décision précédente : l\'adhérent verra de nouveau son parcours de renouvellement normal (formule et licence), au lieu de la formule déjà pré-remplie.');">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                    <label>Note (transmise à l'adhérent)</label>
                    <textarea name="note" rows="2" maxlength="500"></textarea>
                    <button type="submit" name="decision" value="refuse" class="btn-danger">Rejeter</button>
                </form>
            </fieldset>
        <?php endforeach; ?>
    <?php endif; ?>

    <p><a href="/admin/changements/archivees">Voir l'historique (<?= (int) $archivedCount ?>)</a></p>

<?php endif; ?>

<p><a href="/admin">← Administration</a></p>
