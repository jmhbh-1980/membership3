---
name: deploy
description: Commits pending changes, integrates with origin/main, pushes to GitHub, and deploys the Bad & Squash membership3 app to production on Ionos (members.bad-squash.org). Use this whenever the user says to deploy, ship, push to production, put changes live, or release for this project — phrases like "deploy this", "ship it", "push and deploy", "update production", "let's go live with this", or just "deploy" on its own. Runs the whole pipeline end-to-end once invoked — invoking it is the go-ahead, don't ask for confirmation before pushing or deploying. Only stop and report if something is actually broken (merge conflict, failing tests, failed health check).
---

# Deploy membership3

This runs the full release pipeline for this project in one shot: commit →
integrate → push → deploy. The user has already decided, by invoking this
skill, that they want all of it to happen — don't pause to ask "should I
push now?" or "should I deploy now?" between steps. The only reason to stop
mid-pipeline is a real failure (conflict, broken tests, failed health check),
because pushing broken code or claiming a broken deploy succeeded is worse
than stopping to report it.

Full narrative context for this project's deployment model lives in
[DEPLOY.md](../../../DEPLOY.md) at the repo root — read it if anything below
is unclear about *why* a step works the way it does.

## Step 1 — Commit

Run `git status` and `git diff` to see what's actually changed. Stage files
by name, not `git add -A` — this repo's root also holds scratch directories
(`graphify-out/`) and this `.claude/` folder that shouldn't be swept into a
commit by accident. Skim the diff for anything that looks like a secret
before staging (this project's real credentials live in `secrets.php`, which
is gitignored, but double-check anyway).

Write a commit message describing *why*, matching the style already in
`git log` (short, plain-sentence body, no bullet-point changelog). If there's
nothing to commit, say so and move on to Step 2 — don't fabricate a commit.

## Step 2 — Integrate

```bash
cd app && ./vendor/bin/phpunit
cd .. && git fetch origin && git rebase origin/main
```

Run the test suite and the rebase (order doesn't matter, but do both).

- **If the rebase produces conflicts**: stop here. Run `git rebase --abort`,
  explain what conflicted and why to the user, and wait for direction —
  resolving conflicts automatically risks silently merging in the wrong
  logic, which is a worse outcome than a paused pipeline.
- **If phpunit fails**: stop here. Report which tests failed and why. Don't
  push code with a red test suite.

Only proceed to Step 3 once both are clean.

## Step 3 — Push

```bash
git push origin main
```

This repo pushes straight to `main` — there's no PR/review gate in this
workflow, which is exactly why Steps 1 and 2 need to be taken seriously
before this point.

## Step 4 — Deploy to Ionos

Run the bundled script:

```bash
.claude/skills/deploy/scripts/deploy.sh
```

This script (read it if you want the exact mechanics) does the parts that
are purely mechanical and benefit from not being re-derived by reasoning
each time:

1. Puts the site in maintenance mode (`bin/maintenance.php on`) and
   invalidates every open session, member and admin alike — see
   `App\Middleware\Maintenance` and `AuthService::clearIfInvalidated()`.
   Nobody can reach any route except `/sante` while a deploy is in flight,
   and nobody keeps using a pre-deploy session once it's over; both have to
   log in again with a fresh magic link. There's no admin bypass — that's
   deliberate, not an oversight.
2. Waits (`bin/maintenance.php wait-clear`, up to 60s) for any order stuck
   in `fulfilling` — the paid→fulfilling→fulfilled transition is the app's
   own idempotency claim (see CLAUDE.md), so this is the one DB-observable
   "a transaction is actively in flight" signal worth blocking on. If it
   times out, nothing has been touched yet, so the script turns maintenance
   back off and stops — that's a "try again shortly" situation, not a
   deploy failure.
3. Copies `app/` + `members/` into a throwaway staging directory — deploying
   always builds a *separate* `vendor/` there via
   `composer install --no-dev --optimize-autoloader`, because running that
   in the real local `app/vendor/` strips out `phpunit` and breaks local
   testing until `composer install` is re-run. This bit the first manual
   deploy of this app; the script exists specifically so it can't happen
   again.
4. Uploads `app/` and `members/` to `membership3/{app,members}/` on the
   server over SSH (key at `~/.ssh/id_ed25519_membership3_ionos` — if it's
   missing, the script prints the one-time `ssh-keygen` + `ssh-copy-id`
   commands to set it up; `ssh-copy-id` needs the server password typed by
   the human, not by Claude).
5. Syncs `pricing_data/*.php` if present locally (season pricing tables —
   gitignored, edited via `/admin/tarifs` in production, but still part of
   what a fresh deploy needs to seed).
6. Runs `php app/bin/migrate.php` on the server via the PHP 8.4 CLI binary
   (`/usr/bin/php8.4-cli` — the SSH shell's bare `php` on this host resolves
   to a very old default, not 8.4).
7. Curls `/sante` and fails loudly (non-zero exit) if it isn't a clean
   `200` with no `"ko"` component. On failure, maintenance mode is
   deliberately left ON — the site keeps showing the maintenance page
   instead of a broken app until someone investigates and either fixes
   forward or manually runs `bin/maintenance.php off`. There's no automatic
   code rollback today, so this is the safe default given that gap.
8. On a clean health check, turns maintenance mode back off
   (`bin/maintenance.php off`) — normal operation resumes, and everyone
   (including admins) needs a fresh login since Step 1 invalidated sessions.

If the script fails at any point after Step 1, its `EXIT` trap prints a
reminder that maintenance mode is still on and how to turn it off manually
— so a mid-pipeline failure never leaves the site silently stuck without
saying so.

The script never touches `secrets.php`, `uploads/`, or `app_logs/` on the
remote — those are server-only state (credentials, member documents, logs),
not part of what a deploy overwrites. If a deploy ever needs to change
`secrets.php` itself (new credential, rotated key), that's a manual,
one-off `scp` — deliberately not part of this automated path.

## Reporting back

When the pipeline finishes (or stops on a failure), tell the user plainly:
what got committed (message + hash), whether integrate/push happened
cleanly, and the final `/sante` output. If it stopped early, say at which
step and why — don't imply later steps ran when they didn't. If the site is
left in maintenance mode (health check failed, or any step failed after
maintenance was enabled), say so explicitly — that's a "the site is down for
everyone right now" fact, not a minor detail to bury in the log output.
