<?php
/** @var array[] $passkeys newest first */
?>
<h1>Clés d'accès</h1>
<p class="muted">Une clé d'accès (passkey) permet de vous connecter avec Touch ID, Windows Hello ou une
    clé de sécurité, sans passer par un lien envoyé par email. Elle reste utilisable en plus du lien de
    connexion habituel — enregistrez-en une par appareil que vous utilisez pour administrer le site.</p>

<div id="passkey-register-error" class="alert" hidden></div>
<div data-passkey-unsupported-hide>
    <label for="passkey-label">Nom de cette clé (pour la reconnaître dans la liste)</label>
    <input type="text" id="passkey-label" placeholder="ex. MacBook, iPhone…" maxlength="100">
    <button type="button" id="passkey-register-button" class="btn" data-csrf="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
        Ajouter une clé d'accès
    </button>
</div>
<p class="muted" data-passkey-unsupported-show hidden>
    Ce navigateur ne prend pas en charge les clés d'accès.
</p>

<?php if ($passkeys === []): ?>
    <p>Aucune clé d'accès enregistrée pour le moment.</p>
<?php else: ?>
    <div class="table-scroll">
        <table class="details">
            <tr><th>Nom</th><th>Ajoutée le</th><th>Dernière utilisation</th><th></th></tr>
            <?php foreach ($passkeys as $p): ?>
                <tr>
                    <td><?= htmlspecialchars($p['label'], ENT_QUOTES) ?></td>
                    <td><?= date('d/m/Y H:i', strtotime($p['created_at'])) ?></td>
                    <td><?= $p['last_used_at'] !== null ? date('d/m/Y H:i', strtotime($p['last_used_at'])) : '—' ?></td>
                    <td>
                        <form method="post" action="/admin/reglages/passkeys/<?= (int) $p['id'] ?>/supprimer" class="form-inline">
                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
                            <button type="submit" class="btn-danger" onclick="return confirm('Supprimer cette clé d\'accès ?');">Supprimer</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
<?php endif; ?>

<p><a href="/admin">← Administration</a></p>
