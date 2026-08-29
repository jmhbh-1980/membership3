# Déploiement sur Ionos — Bad & Squash Membership v3

## 1. Arborescence sur le serveur

L'app vit dans un sous-dossier `membership3/` à la racine du compte Ionos
(pas directement à la racine — le compte héberge d'autres sites), avec un
**sous-domaine dédié** dont le document root pointe sur `membership3/members/`.
Tout sauf `members/` est hors webroot, donc jamais accessible par HTTP :

```
/ (racine du compte, vue FTP)
└── membership3/
    ├── members/          ← DOCUMENT_ROOT du sous-domaine (index.php, .htaccess, assets/)
    ├── app/              ← src/, templates/, config/, migrations/, vendor/, bin/
    ├── secrets.php       ← copié depuis secrets.php.example, valeurs réelles
    ├── uploads/          ← créé automatiquement (documents des adhérents)
    ├── app_logs/         ← créé automatiquement (journal applicatif)
    └── pricing_data/     ← barèmes tarifaires par saison (pricing.<saison>.php),
                             gérés depuis /admin/tarifs — non versionnés dans le dépôt
```

⚠️ Ne jamais écrire dans `logs/` à la racine du compte : dossier réservé par Ionos.

⚠️ `pricing_data/` n'est pas déployé avec le dépôt (gitignored, éditable en
production via l'écran admin). Au tout premier déploiement, uploader une fois
manuellement le(s) fichier(s) `pricing.<saison>.php` de la saison en cours
(et de la suivante si déjà publiée), disponibles en local dans `pricing_data/`
— sans quoi l'app n'a aucun barème à charger.

## 2. Prérequis Ionos

- PHP **8.4** sélectionné pour le sous-domaine (panneau Ionos → PHP).
- Une base **MySQL 5.7** créée dans le panneau ; noter hôte/nom/utilisateur/mot de passe.
- Un **sous-domaine** créé dans le panneau (ex. `membres.bad-squash.org`), document
  root = `membership3/members/`.

## 3. Étapes

Tous les chemins ci-dessous sont relatifs à `membership3/` à la racine du compte
(donc `app/` = `membership3/app/` côté serveur, etc.).

1. **Vendor** : lancer `composer install --no-dev --optimize-autoloader` dans `app/`
   en local, puis téléverser `app/` complet (y compris `vendor/`) par SFTP vers
   `membership3/app/`.
2. **Webroot** : téléverser `members/` (index.php, .htaccess, assets/, robots.txt)
   vers `membership3/members/`.
3. **Secrets** : copier `secrets.php.example` → `secrets.php` dans `membership3/`,
   renseigner : `env => 'prod'`, base de données, clé API Balle Jaune, clé API +
   merchant code SumUp, mot de passe d'application Google Workspace
   (compte `nepasrepondre@bad-squash.org`), et un `app_key` aléatoire
   (`php -r "echo bin2hex(random_bytes(32));"`).
4. **Migrations** : `php app/bin/migrate.php` (via SSH Ionos, ou depuis un poste
   local pointant sur la base distante si l'accès distant MySQL est activé).
5. **Vérification** : ouvrir `https://<sous-domaine>/sante` → tous les checks `ok`.
6. **Smoke test Balle Jaune** : `php app/bin/bj_smoke.php` → toutes les
   résolutions `OK` (les abonnements simplifiés doivent exister dans BJ pour
   chaque fichier présent dans `pricing_data/` :
   `_Abonnement Individuel - Heures Pleines`, `_Abonnement Individuel - Heures
   Creuses`, `_Abonnement Individuel - Midi`, `_Abonnement Individuel Jeune`,
   `FORMULE TICKETS-5`).

## 4. SumUp

- Créer une clé API (developer.sumup.com) et renseigner `sumup.api_key` +
  `sumup.merchant_code` dans `secrets.php`.
- Pas de réglage de webhook côté tableau de bord SumUp : l'API n'a qu'un
  `return_url` par checkout (même URL pour la redirection navigateur et la
  notification serveur-à-serveur), déjà positionné par le code sur
  `https://<sous-domaine>/paiement/retour/{reference}` — rien à configurer
  manuellement.
- Sans clé API (`api_key` vide), l'application bascule en **mode simulation**
  (page de paiement factice, uniquement si `env => 'dev'`) — ne jamais laisser
  `env => 'dev'` en production.

## 5. Emails

- SMTP Gmail : `smtp.gmail.com:587` (STARTTLS), utilisateur
  `nepasrepondre@bad-squash.org`, **mot de passe d'application** (Google
  Workspace → Sécurité → Mots de passe d'application ; nécessite la validation
  en deux étapes sur le compte).
- Tous les envois sont tracés dans la table `email_log`.

## 5bis. Coordonnées bancaires (virement)

Les coordonnées bancaires du club (affichées aux adhérents qui choisissent
de payer par virement, et sur les factures) sont désormais gérées depuis
`/admin/reglages/virement` — pas dans `secrets.php`. Après le tout premier
déploiement de cette fonctionnalité, la base ne contient encore aucune
valeur : renseigner l'IBAN/BIC/etc. une fois via cet écran (sinon les
factures affichent les anciennes valeurs de `secrets.php` si présentes, ou
des champs vides).

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
