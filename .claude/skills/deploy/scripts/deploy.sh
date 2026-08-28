#!/usr/bin/env bash
# Builds a production vendor/ in an isolated staging copy (never touches the
# local dev vendor/ used for testing), uploads app/ + members/ + pricing_data/
# to the Ionos server, runs migrations, and checks /sante.
#
# Deliberately never touches, on the remote: secrets.php, uploads/, app_logs/.
# Those are server-only state, not part of the deployable code artifact.
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

echo "==> Staging a clean copy of app/ + members/ (excluding dev-only state)"
rsync -a \
  --exclude='.git' --exclude='.claude' --exclude='graphify-out' \
  --exclude='app/vendor' --exclude='app/.phpunit.cache' --exclude='app/.phpunit.result.cache' \
  --exclude='secrets.php' --exclude='uploads' --exclude='app_logs' --exclude='pricing_data' \
  "$REPO_ROOT"/app "$REPO_ROOT"/members "$STAGE"/

echo "==> composer install --no-dev --optimize-autoloader (in staging only)"
(cd "$STAGE/app" && composer install --no-dev --optimize-autoloader --quiet)

echo "==> Uploading app/"
rsync -az --delete -e "ssh -i $KEY" "$STAGE"/app/ "$HOST":"$REMOTE_BASE"/app/

echo "==> Uploading members/"
rsync -az --delete -e "ssh -i $KEY" "$STAGE"/members/ "$HOST":"$REMOTE_BASE"/members/

if [ -d "$REPO_ROOT/pricing_data" ] && ls "$REPO_ROOT"/pricing_data/*.php >/dev/null 2>&1; then
  echo "==> Syncing pricing_data/ (season pricing tables, gitignored but not server-only)"
  scp -i "$KEY" "$REPO_ROOT"/pricing_data/*.php "$HOST":"$REMOTE_BASE"/pricing_data/
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
