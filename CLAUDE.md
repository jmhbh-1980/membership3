# Bad & Squash — Membership App v3

Full build plan (legs 0-9, decisions, open items): `~/.claude/plans/users-jeanmarc-downloads-attestation-de-calm-music.md`. All legs 0-9 are built and E2E-verified locally (2026-08-20); remaining before go-live: real SumUp credentials + webhook, Google app password, production deployment (see [DEPLOY.md](DEPLOY.md)).

## Stack & environment

- **Slim 4 + PHP 8.4 + MySQL 5.7**, Ionos shared hosting. Composer with `vendor/` deployed. Local PHP may be newer, but write 8.4-compatible code (Composer platform is pinned to 8.4).
- Repo root mirrors the Ionos **account root**: `members/` is the DOCUMENT_ROOT (front controller + assets only); `app/` (src, templates, config, migrations, vendor), `secrets.php`, `uploads/`, `app_logs/` all sit outside the webroot. Never write to a folder named `logs/` — Ionos reserves it.
- Local dev: `php -S 127.0.0.1:8823 -t members members/index.php`. Tests: `cd app && ./vendor/bin/phpunit`. Migrations: `php app/bin/migrate.php` (plain SQL files in `app/migrations/`, applied in filename order).
- MySQL 5.7: InnoDB, utf8mb4, no CTEs, explicit indexes on FKs. PDO prepared statements only.

## Language

- **All UI text in French (France)**; code, identifiers and comments in English.

## Domain rules (locked with the club — see plan for the full list)

- Season = 1 Sept → 31 Aug; BJ `subscription_date_end` gets a grace period to **15 Sept**. `App\Service\Season` owns all season math.
- **Balle Jaune is the source of truth** for members, memberships, credits, contacts (`ballejauneapi.md`). Simplified BJ catalogue: `_Abonnement Individuel - Heures Pleines`, `_Abonnement Individuel - Heures Creuses`, `FORMULE TICKETS-5`. Resolve names → IDs at runtime (`SubscriptionResolver`), never hardcode IDs. Always unwrap the `{success, data}` envelope (`data.user` is singular on GET one).
- **Exception: membership3 is the source of truth for licence type** (pass/fédérale/jeune/été) **and `cours collectifs`**, not BJ — by choice, not just by default. BJ has no structured field for either: licence type only ever lands as free text in `subscription_notes` (the real value is derived locally, `PricingService::licenceKindFor()` off `member_formulas.competitor`), and `GET /bookings` is read-only with no write endpoint, so group-lesson enrollment can only ever live in the local `lesson_enrollments` table. Don't try to push either into a BJ field (e.g. a repurposed `customN`) to make BJ "authoritative" — that was considered and declined.
- **BJ API quirks found live**: there is no suspend field on POST/PATCH users — "deactivated" members are created with the **Visiteur** ACL and promoted to **Membre** after payment + shoes check (`RoleResolver`). `license_year` must be exactly 4 digits and cannot be cleared once set. `POST /users` with an email set makes BJ send the member their credentials automatically.
- **Testing against BJ**: use the dedicated test member **TEST Jean-Marc** (user_id 1405147, jeanmarc.huibonhoa+ballejaunetest@gmail.com, role Membre). Snapshot its fields before mutating, restore after; trash (`DELETE /users/{id}`) any user you create.
- **All pricing lives in this app**: `app/config/pricing.<season>.php` + `App\Service\PricingService` (pure, unit-tested against the club's price table — keep it that way). Prorata: (complete months since 1 Sept)/12 off cotisation + lessons only; licences always full price.
- Auth = passwordless magic links; login identifier is the **email**, matched against BJ users. Licence number is never an identifier.
- BJ `flag=1` means "licence not registered yet with the federation". Staff subscription types (Membre du bureau, Admin, Planning, Professeur) are excluded from member lists/campaigns at query level; BJ admins never pay through the app.

## Conventions

- Single shared logger (`App\Support\Logger`) → `app_logs/membership.log`. Never log secrets or full payment data.
- Controllers get dependencies via PHP-DI; definitions needing `$settings` are registered explicitly in `app/src/bootstrap.php` (beware: `use Slim\App` makes bare `App\...` resolve to `Slim\App\...` — use `\App\...`).
- Templates: `slim/php-view` with layout `app/templates/layout/base.php`; escape everything with `htmlspecialchars(..., ENT_QUOTES)`.
- CSRF token on every POST form (exception: `/webhooks/sumup`, which re-verifies checkout status via the SumUp API instead); uploads validated (finfo MIME, 8 MB) and stored under `uploads/applications/{id}/` (outside webroot), served only through the admin-gated document route with a stored-name allowlist.
- **Order state machine** (`orders.status`): pending → paid → fulfilling → fulfilled. The paid→fulfilling transition is the idempotency claim — fulfillment (BJ writes) runs exactly once even when the SumUp webhook and return URL both fire. On fulfillment error the order returns to `paid` for retry.
- **Payments**: `SumUpService` (direct cURL, hosted checkout). No API key + `env=dev` = simulation mode (`/paiement/dev/{ref}`), used for local E2E.
- **Dev environment**: MySQL 5.7 runs in Docker/colima (`bs-mysql57`, ARM image `biarms/mysql:5.7`, port 3307, db/user/password `membership`). No SMTP password in dev → emails are logged to `app_logs/membership.log` (with extracted links) and `email_log` instead of sent.
- Local test fixtures & E2E curl flows: see git history / scratchpad patterns — login via magic link extracted from the app log; wizard, payment, renewal, credits all covered.
