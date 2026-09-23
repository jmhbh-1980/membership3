<?php
/** @var array[] $plans  each enriched with 'name', 'nextDue' (the schedule entry still pending, if any), 'isCouple' and 'partner' */
?>
<h1>Paiements échelonnés en cours</h1>
<p class="muted">Informatif : installment 1 est déjà réglé (c'est ce qui active le plan) — rien à valider ici.
    Un plan passe à « en échec » automatiquement si un prélèvement échoue ; l'adhérent reçoit alors un lien de paiement manuel.</p>

<?php if ($plans === []): ?>
    <p>Aucun paiement échelonné en cours.</p>
<?php else: ?>
    <div class="table-scroll">
    <table class="details">
        <tr><th>Adhérent</th><th>Saison</th><th>Plan</th><th>Prochaine échéance</th><th>Montant</th><th></th></tr>
        <?php foreach ($plans as $p): ?>
            <tr>
                <td><?= $this->fetch('partials/member_name.php', ['name' => $p['name'], 'bjUserId' => $p['bj_user_id']]) ?>
                    <?php if ($p['isCouple']): ?><br><?= $this->fetch('partials/couple_partner.php', ['partner' => $p['partner']]) ?><?php endif; ?></td>
                <td class="nowrap"><?= (int) $p['season_start_year'] ?>-<?= (int) $p['season_start_year'] + 1 ?></td>
                <td class="nowrap"><?= (int) $p['installment_count'] ?>x</td>
                <td class="nowrap">
                    <?php if ($p['nextDue'] !== null): ?>
                        Versement <?= (int) $p['nextDue']['number'] ?>/<?= (int) $p['installment_count'] ?> — <?= date('d/m/Y', strtotime($p['nextDue']['due_date'])) ?>
                    <?php else: ?>
                        <span class="muted">Toutes les échéances réglées</span>
                    <?php endif; ?>
                </td>
                <td class="nowrap"><?= $p['nextDue'] !== null ? number_format((float) $p['nextDue']['amount'], 2, ',', ' ') . ' €' : '—' ?></td>
                <td class="nowrap"><a href="https://ballejaune.com/admin#page=/admin/users&panel=/admin/users/update/id/<?= (int) $p['bj_user_id'] ?>" target="_blank" rel="noopener">Voir la fiche</a></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
<?php endif; ?>

<p><a href="/admin">← Administration</a></p>
