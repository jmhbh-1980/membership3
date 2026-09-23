<?php /** @var array<int, array[]> $bySeason */ ?>
<h1>Inscrits aux cours collectifs</h1>

<?php if ($bySeason === []): ?>
    <p>Aucune inscription aux cours collectifs pour le moment.</p>
<?php else: ?>
    <?php foreach ($bySeason as $year => $rows): ?>
        <h2>Saison <?= $year ?>-<?= $year + 1 ?> — <?= count($rows) ?> inscrit<?= count($rows) > 1 ? 's' : '' ?></h2>
        <div class="table-scroll">
        <table class="details">
            <tr><th>Nom</th><th>Email</th><th>Inscrit le</th></tr>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><?= $this->fetch('partials/member_name.php', ['name' => $r['lastname'] . ' ' . $r['firstname'], 'bjUserId' => $r['bj_user_id']]) ?><?= $this->fetch('partials/garennois_badge.php', ['residence' => $r['residence'] ?? '']) ?>
                        <?php if ($r['couple'] !== null): ?><br><?= $this->fetch('partials/couple_partner.php', ['partner' => $r['couple']['partner']]) ?><?php endif; ?></td>
                    <td><?= htmlspecialchars($r['email'], ENT_QUOTES) ?></td>
                    <td><?= date('d/m/Y', strtotime($r['created_at'])) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<p><a href="/admin">← Administration</a></p>
