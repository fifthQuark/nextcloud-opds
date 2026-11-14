#!/usr/bin/env bash
# Create appinfo/signature.json for an app directory using a Nextcloud container.
#
# Nextcloud only installs App Store apps whose files are signed with a
# certificate issued by Nextcloud (see PUBLISHING.md). The signature is
# produced by `occ integrity:sign-app`, which needs a running Nextcloud, so
# this script starts a throwaway Nextcloud container (docker or podman),
# signs the given directory inside it and copies signature.json back.
#
# Usage: scripts/sign-app.sh <app-dir> <private-key.pem> <certificate.crt> [nextcloud-image]
set -euo pipefail

APP_DIR=${1:?app directory}
KEY=${2:?private key}
CERT=${3:?certificate}
IMAGE=${4:-docker.io/library/nextcloud:31-apache}
APP_ID=$(sed -n 's:.*<id>\(.*\)</id>.*:\1:p' "$APP_DIR/appinfo/info.xml" | head -1)
NAME="nc-sign-$$"

if command -v docker >/dev/null 2>&1; then CTR=docker; elif command -v podman >/dev/null 2>&1; then CTR=podman; else
    echo "docker or podman is required" >&2; exit 1
fi

cleanup() { $CTR rm -f "$NAME" >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "Starting $IMAGE ..."
$CTR run -d --name "$NAME" \
    -e SQLITE_DATABASE=nextcloud -e NEXTCLOUD_ADMIN_USER=admin -e NEXTCLOUD_ADMIN_PASSWORD=signing-only-password \
    "$IMAGE" >/dev/null

for _ in $(seq 1 60); do
    if $CTR exec -u www-data "$NAME" php occ status 2>/dev/null | grep -q 'installed: true'; then break; fi
    sleep 3
done
$CTR exec -u www-data "$NAME" php occ status | grep -q 'installed: true' || { echo "Nextcloud did not come up" >&2; exit 1; }

rm -f "$APP_DIR/appinfo/signature.json"
$CTR cp "$APP_DIR" "$NAME:/tmp/$APP_ID"
$CTR cp "$KEY" "$NAME:/tmp/$APP_ID.key"
$CTR cp "$CERT" "$NAME:/tmp/$APP_ID.crt"
$CTR exec "$NAME" chown -R www-data:www-data "/tmp/$APP_ID" "/tmp/$APP_ID.key" "/tmp/$APP_ID.crt"
$CTR exec -u www-data "$NAME" php occ integrity:sign-app \
    --privateKey="/tmp/$APP_ID.key" --certificate="/tmp/$APP_ID.crt" --path="/tmp/$APP_ID"
$CTR cp "$NAME:/tmp/$APP_ID/appinfo/signature.json" "$APP_DIR/appinfo/signature.json"
echo "Wrote $APP_DIR/appinfo/signature.json"
