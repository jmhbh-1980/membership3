#!/usr/bin/env bash
# Builds a production vendor/ in an isolated staging copy (never touches the
# local dev vendor/ used for testing), uploads app/ + members/ to the Ionos
# server, seeds any missing pricing_data/ file, runs migrations, checks /sante.
#
# Deliberately never touches, on the remote: secrets.php, uploads/, app_logs/,
# backups/. Those are server-only state, not part of the deployable code
# artifact. pricing_data/ is a fifth kind: admins edit it in production, so it
# is only ever seeded, never overwritten (see the block near the end for why).
set -euo pipefail

REPO_ROOT="$(git rev-parse --show-toplevel)"
KEY="$HOME/.ssh/id_ed25519_membership3_ionos"
HOST="u53488617@home285380596.1and1-data.host"
REMOTE_BASE="membership3"
HEALTH_URL="https://members.bad-squash.org/sante"

if [ ! -f "$KEY" ]; then
  echo "Missing deploy key at $KEY." >&2
  echo "Generate it once with:" >&2
  echo "  ssh-keygen -t ed25519 -f $KEY -N '' -C membership3-deploy" >&2
  echo "Then authorize it (will prompt for the server password once):" >&2
  echo "  ssh-copy-id -i $KEY.pub $HOST" >&2
  exit 1
fi

STAGE="$(mktemp -d)"
MAINTENANCE_ON=0
cleanup() {
  rm -rf "$STAGE"
  if [ "$MAINTENANCE_ON" = "1" ]; then
    echo "==> Deploy stopped early with maintenance mode still ON — the site is showing the maintenance page to everyone (including admins)." >&2
    echo "    Fix the issue above, then either re-run this script or manually run:" >&2
    echo "      ssh -i $KEY $HOST 'cd $REMOTE_BASE/app && /usr/bin/php8.4-cli bin/maintenance.php off'" >&2
  fi
}
trap cleanup EXIT

# bin/maintenance.php ships as part of the deployable code, so the very
# first deploy after it's introduced can't call it yet — it isn't on the
# server. Detect that one-time bootstrap case and skip straight to a plain
# sync instead of failing; every deploy after this one has the file and gets
# full maintenance-mode protection.
if ssh -i "$KEY" "$HOST" "test -f $REMOTE_BASE/app/bin/maintenance.php"; then
  echo "==> Enabling maintenance mode and invalidating all sessions"
  ssh -i "$KEY" "$HOST" "cd $REMOTE_BASE/app && /usr/bin/php8.4-cli bin/maintenance.php on"
  MAINTENANCE_ON=1

  echo "==> Waiting for any in-flight order fulfillment to clear"
  if ! ssh -i "$KEY" "$HOST" "cd $REMOTE_BASE/app && /usr/bin/php8.4-cli bin/maintenance.php wait-clear --timeout=60 --interval=2"; then
    echo "Timed out waiting for in-flight orders — nothing has been deployed yet, so restoring normal operation instead of proceeding." >&2
    ssh -i "$KEY" "$HOST" "cd $REMOTE_BASE/app && /usr/bin/php8.4-cli bin/maintenance.php off"
    MAINTENANCE_ON=0
    exit 1
  fi
else
  echo "==> bin/maintenance.php not present on the server yet — bootstrap deploy, proceeding without maintenance-mode protection (this deploy installs that capability for every deploy after it)."
fi

echo "==> Staging a clean copy of app/ + members/ (excluding dev-only state)"
rsync -a \
  --exclude='.git' --exclude='.claude' --exclude='graphify-out' \
  --exclude='app/vendor' --exclude='app/.phpunit.cache' --exclude='app/.phpunit.result.cache' \
  --exclude='secrets.php' --exclude='uploads' --exclude='app_logs' --exclude='pricing_data' --exclude='backups' \
  "$REPO_ROOT"/app "$REPO_ROOT"/members "$STAGE"/

echo "==> composer install --no-dev --optimize-autoloader (in staging only)"
(cd "$STAGE/app" && composer install --no-dev --optimize-autoloader --quiet)

echo "==> Uploading app/"
rsync -az --delete -e "ssh -i $KEY" "$STAGE"/app/ "$HOST":"$REMOTE_BASE"/app/

echo "==> Uploading members/"
rsync -az --delete -e "ssh -i $KEY" "$STAGE"/members/ "$HOST":"$REMOTE_BASE"/members/

# pricing_data/ is SERVER-AUTHORITATIVE, unlike everything else this script
# uploads. Both files in it are edited in production by admins — the season
# barèmes via /admin/tarifs, the invoice blurbs via
# /admin/reglages/descriptions-factures — and the directory is gitignored, so
# the deploying machine's copy is nobody's source of truth. A blanket scp here
# silently replaced a week of admin edits with whatever happened to be on that
# laptop, which is why each file is now only *seeded* when the server hasn't
# got it: a brand-new season file lands, an existing one is left alone.
#
# Set PUSH_PRICING_DATA=1 to overwrite the server's copies deliberately — the
# rare case where local really is the newer version.
if [ -d "$REPO_ROOT/pricing_data" ] && ls "$REPO_ROOT"/pricing_data/*.php >/dev/null 2>&1; then
  ssh -i "$KEY" "$HOST" "mkdir -p $REMOTE_BASE/pricing_data"

  if [ "${PUSH_PRICING_DATA:-0}" = "1" ]; then
    echo "==> PUSH_PRICING_DATA=1 — OVERWRITING pricing_data/ on the server with local copies"
    scp -i "$KEY" "$REPO_ROOT"/pricing_data/*.php "$HOST":"$REMOTE_BASE"/pricing_data/
  else
    echo "==> Seeding pricing_data/ (only files the server doesn't have yet)"
    remote_files="$(ssh -i "$KEY" "$HOST" "ls -1 $REMOTE_BASE/pricing_data/ 2>/dev/null || true")"
    seeded=0
    kept=0
    for local_file in "$REPO_ROOT"/pricing_data/*.php; do
      name="$(basename "$local_file")"
      case "$name" in
        # A half-finished barème from someone's laptop has no business
        # appearing in the production editor as if an admin had started it.
        *.draft.php)
          echo "    skipped $name (local draft)"
          continue
          ;;
      esac
      if printf '%s\n' "$remote_files" | grep -Fxq "$name"; then
        kept=$((kept + 1))
      else
        scp -q -i "$KEY" "$local_file" "$HOST":"$REMOTE_BASE"/pricing_data/
        echo "    seeded $name (absent from the server)"
        seeded=$((seeded + 1))
      fi
    done
    echo "    $seeded seeded, $kept left as-is on the server (edited there, not here)."
  fi
fi

echo "==> Running migrations"
ssh -i "$KEY" "$HOST" "cd $REMOTE_BASE/app && /usr/bin/php8.4-cli bin/migrate.php"

echo "==> Health check: $HEALTH_URL"
HTTP_CODE=$(curl -sS -o /tmp/membership3_sante.json -w "%{http_code}" "$HEALTH_URL")
cat /tmp/membership3_sante.json
echo
if [ "$HTTP_CODE" != "200" ]; then
  echo "Health check returned HTTP $HTTP_CODE — deploy likely broken. Maintenance mode stays ON so nobody hits it; investigate before turning it off." >&2
  exit 1
fi
if grep -q '"ko"' /tmp/membership3_sante.json; then
  echo "Health check reports a 'ko' component. Maintenance mode stays ON so nobody hits it; investigate before turning it off." >&2
  exit 1
fi

echo "==> Health check clean — disabling maintenance mode"
ssh -i "$KEY" "$HOST" "cd $REMOTE_BASE/app && /usr/bin/php8.4-cli bin/maintenance.php off"
MAINTENANCE_ON=0

echo "==> Deploy complete."
