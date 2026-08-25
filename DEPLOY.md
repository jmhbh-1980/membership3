# Déploiement sur Ionos — Bad & Squash Membership v3

## 1. Arborescence sur le serveur

Reproduire l'arborescence du dépôt à la racine du compte Ionos (tout sauf
`members/` est hors webroot, donc jamais accessible par HTTP) :

```
/ (racine du compte, vue FTP)
├── members/          ← DOCUMENT_ROOT du domaine (index.php, .htaccess, assets/)
├── app/              ← src/, templates/, config/, migrations/, vendor/, bin/
├── secrets.php       ← copié depuis secrets.php.example, valeurs réelles
├── uploads/          ← créé automatiquement (documents des adhérents)
├── app_logs/         ← créé automatiquement (journal applicatif)
└── pricing_data/     ← barèmes tarifaires par saison (pricing.<saison>.php),
                         gérés depuis /admin/tarifs — non versionnés dans le dépôt
```

⚠️ Ne jamais écrire dans `logs/` à la racine : dossier réservé par Ionos.

⚠️ `pricing_data/` n'est pas déployé avec le dépôt (gitignored, éditable en
production via l'écran admin). Au tout premier déploiement, uploader une fois
manuellement le(s) fichier(s) `pricing.<saison>.php` de la saison en cours
(et de la suivante si déjà publiée), disponibles en local dans `pricing_data/`
— sans quoi l'app n'a aucun barème à charger.

## 2. Prérequis Ionos

- PHP **8.4** sélectionné pour le domaine (panneau Ionos → PHP).
- Une base **MySQL 5.7** créée dans le panneau ; noter hôte/nom/utilisateur/mot de passe.
- Le domaine pointe sur `members/`.

## 3. Étapes

1. **Vendor** : lancer `composer install --no-dev --optimize-autoloader` dans `app/`
   en local, puis téléverser `app/` complet (y compris `vendor/`) par SFTP.
2. **Webroot** : téléverser `members/` (index.php, .htaccess, assets/, robots.txt).
3. **Secrets** : copier `secrets.php.example` → `secrets.php` à la racine du compte,
   renseigner : `env => 'prod'`, base de données, clé API Balle Jaune, clé API +
   merchant code SumUp, mot de passe d'application Google Workspace
   (compte `nepasrepondre@bad-squash.org`), et un `app_key` aléatoire
   (`php -r "echo bin2hex(random_bytes(32));"`).
4. **Migrations** : `php app/bin/migrate.php` (via SSH Ionos, ou depuis un poste
   local pointant sur la base distante si l'accès distant MySQL est activé).
5. **Vérification** : ouvrir `https://<domaine>/sante` → tous les checks `ok`.
6. **Smoke test Balle Jaune** : `php app/bin/bj_smoke.php` → toutes les
   résolutions `OK` (les abonnements simplifiés doivent exister dans BJ pour
   chaque fichier présent dans `pricing_data/` :
   `_Abonnement Individuel - Heures Pleines`, `_Abonnement Individuel - Heures
   Creuses`, `_Abonnement Individuel - Midi`, `_Abonnement Individuel Jeune`,
   `FORMULE TICKETS-5`).

## 4. SumUp

- Créer une clé API (developer.sumup.com) et renseigner `sumup.api_key` +
  `sumup.merchant_code` dans `secrets.php`.
- Configurer le webhook de checkout vers `https://<domaine>/webhooks/sumup`.
- Sans clé API (`api_key` vide), l'application bascule en **mode simulation**
  (page de paiement factice, uniquement si `env => 'dev'`) — ne jamais laisser
  `env => 'dev'` en production.

## 5. Emails

- SMTP Gmail : `smtp.gmail.com:587` (STARTTLS), utilisateur
  `nepasrepondre@bad-squash.org`, **mot de passe d'application** (Google
  Workspace → Sécurité → Mots de passe d'application ; nécessite la validation
  en deux étapes sur le compte).
- Tous les envois sont tracés dans la table `email_log`.

## 6. Checklist de mise en service

- [ ] `/sante` : `app`, `log`, `db` = ok
- [ ] `bj_smoke.php` : résolutions OK
- [ ] Connexion par lien magique avec un compte admin réel
- [ ] Parcours d'inscription complet avec un email de test → validation admin →
      paiement SumUp réel de faible montant → utilisateur créé dans BJ
      (Visiteur, flag ⚑) → semelles OK → passage Membre
- [ ] Renouvellement du compte de test (TEST Jean-Marc)
- [ ] Achat de crédits sur le compte de test → +5 crédits
- [ ] Email reçu pour chaque étape (vérifier spam/DMARC)
- [ ] Remettre le compte de test dans son état initial

## 7. Sauvegardes

- Base MySQL : sauvegarde via le panneau Ionos (ou `mysqldump` planifié).
- `uploads/` : contient les pièces des adhérents (photos, justificatifs,
  certificats, attestations signées) — à inclure dans toute sauvegarde.
