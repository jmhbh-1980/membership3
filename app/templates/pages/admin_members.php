<?php /** @var string $search  @var array[] $users  @var array $namesById  @var array $validSeasons */ ?>
<h1>Adhérents</h1>

<form method="get" class="form">
    <label for="q">Recherche (nom, email, licence, ville…)</label>
    <input id="q" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES) ?>" autofocus>
    <button type="submit" class="btn-small">Rechercher</button>
</form>

<?php if ($search !== ''): ?>
    <?php if ($users === []): ?>
        <p>Aucun adhérent trouvé pour « <?= htmlspecialchars($search, ENT_QUOTES) ?> ».</p>
    <?php else: ?>
        <table class="details">
            <tr><th>Nom</th><th>Email</th><th>Abonnement</th><th>Fin</th><th>Payé</th><th>Saisons validées</th><th>Licence</th><th>Crédits</th></tr>
            <?php foreach ($users as $u): ?>
                <?php $vs = $validSeasons[(int) $u['user_id']] ?? ['seasons' => [], 'mismatch' => false]; ?>
                <tr>
                    <td><?= htmlspecialchars($u['lastname'] . ' ' . $u['firstname'], ENT_QUOTES) ?><?= $this->fetch('partials/garennois_badge.php', ['residence' => $u['residence'] ?? '']) ?></td>
                    <td><?= htmlspecialchars($u['email'], ENT_QUOTES) ?></td>
                    <td><?= htmlspecialchars($namesById[(int) $u['subscription_id']] ?? '—', ENT_QUOTES) ?></td>
                    <td><?= ($u['subscription_date_end'] ?? '') !== '' && $u['subscription_date_end'] !== '0000-00-00' ? date('d/m/Y', strtotime($u['subscription_date_end'])) : '—' ?></td>
                    <td><?= ($u['subscription_paid'] ?? 0) ? '✔' : '✗' ?></td>
                    <td><?= $vs['seasons'] !== [] ? htmlspecialchars(implode(', ', $vs['seasons']), ENT_QUOTES) : '—' ?>
                        <?php if ($vs['mismatch']): ?>
                            <span class="muted" title="La date de fin BJ ne couvre pas la saison la plus récente enregistrée par l'appli — a probablement été modifiée manuellement dans Balle Jaune.">⚠</span>
                        <?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($u['license_number'] !== '' ? $u['license_number'] : '—', ENT_QUOTES) ?><?= !empty($u['flag']) ? ' ⚑' : '' ?></td>
                    <td><?= number_format((float) ($u['book_card_tickets'] ?? 0), 0, ',', ' ') ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        <p class="muted">⚑ = licence à enregistrer auprès de la fédération. ⚠ = la fin d'abonnement BJ ne couvre pas la saison enregistrée par l'appli.</p>
    <?php endif; ?>
<?php endif; ?>
<p><a href="/admin">← Administration</a></p>
