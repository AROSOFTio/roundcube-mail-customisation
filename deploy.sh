#!/usr/bin/env sh
set -eu

TARGET_DIR="${TARGET_DIR:-/data/roundcube-custom}"
COMPOSE_DIR="${COMPOSE_DIR:-/data/coolify/services/ppenu18zv8mkupp3zzmfairm}"
BACKUP_ROOT="${BACKUP_ROOT:-/data/roundcube-custom-backup}"

if [ ! -d "$TARGET_DIR/.git" ]; then
  echo "ERROR: $TARGET_DIR is not a git working copy." >&2
  exit 1
fi

if [ ! -f "$TARGET_DIR/password-recovery/config/config.json" ]; then
  echo "ERROR: live secret config is missing: $TARGET_DIR/password-recovery/config/config.json" >&2
  exit 1
fi

backup_dir="$BACKUP_ROOT/deploy-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup_dir"
rsync -a \
  --exclude '.git/' \
  "$TARGET_DIR/" "$backup_dir/"

cd "$TARGET_DIR"
git fetch origin main
git reset --hard origin/main

chmod 600 "$TARGET_DIR/password-recovery/config/config.json"

cd "$COMPOSE_DIR"
docker compose config >/tmp/roundcube-compose-post-deploy.yml
docker compose restart roundcube

echo "DEPLOYED_COMMIT=$(git -C "$TARGET_DIR" rev-parse --short HEAD)"
echo "BACKUP_DIR=$backup_dir"
