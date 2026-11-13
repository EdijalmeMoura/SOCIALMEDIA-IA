#!/bin/sh
set -eu

# The browser installer can bootstrap the instance without a pre-provisioned
# SECRET_KEY. That grace period only exists while the instance has no account
# and no installation record: afterwards the container fails closed.
DATA_DIR="${SOCIALMEDIA_DATA:-/data}"
DB_PATH="${SOCIALMEDIA_DB:-$DATA_DIR/socialmedia.db}"
# Mirror installer.InstallerPaths.state_path(): the record lives next to the
# private configuration file, which SOCIALMEDIA_INSTALLER_ENV can relocate.
ENV_FILE="${SOCIALMEDIA_INSTALLER_ENV:-${SOCIALMEDIA_ENV_FILE:-$DATA_DIR/installer.env}}"
STATE_PATH="$(dirname "$ENV_FILE")/installer-state.json"

installation_pending() {
  [ -f "$STATE_PATH" ] && return 1
  [ -f "$DB_PATH" ] || return 0
  python - "$DB_PATH" <<'PY'
import sqlite3
import sys

try:
    connection = sqlite3.connect(f"file:{sys.argv[1]}?mode=ro", uri=True, timeout=5)
    accounts = connection.execute("SELECT COUNT(*) FROM users").fetchone()[0]
    connection.close()
except sqlite3.Error:
    accounts = 0
sys.exit(0 if accounts == 0 else 1)
PY
}

secret_is_valid() {
  [ -n "${SECRET_KEY:-}" ] || return 1
  [ "$SECRET_KEY" != "dev-only-change-this-secret-before-deploying" ] || return 1
  [ "${#SECRET_KEY}" -ge 32 ] || return 1
  return 0
}

if ! secret_is_valid; then
  if installation_pending; then
    # Ephemeral secret, only for the bootstrap process. The installer replaces
    # it with a persistent SECRET_KEY in the private config file on success.
    SECRET_KEY="$(python -c 'import secrets; print(secrets.token_urlsafe(48))')"
    export SECRET_KEY
    echo "Browser installer mode: no persistent SECRET_KEY yet. Open /install to bootstrap this instance." >&2
    echo "The generated configuration is written to the data volume with 0600 permissions." >&2
  else
    echo "Set a persistent SECRET_KEY with at least 32 characters before starting." >&2
    exit 1
  fi
fi

if [ "${COOKIE_SECURE:-0}" != "1" ]; then
  echo "COOKIE_SECURE=1 is required behind HTTPS." >&2
  exit 1
fi

PORT="${PORT:-5000}"
case "$PORT" in
  ""|*[!0-9]*) echo "PORT must be a numeric TCP port." >&2; exit 1 ;;
esac

# Automatic installation: define SOCIALMEDIA_AUTO_INSTALL=1 together with the
# SOCIALMEDIA_OWNER_* / SOCIALMEDIA_AGENCY_NAME / SOCIALMEDIA_PUBLIC_ORIGIN
# variables and the first boot creates the workspace without any browser step.
if [ "${SOCIALMEDIA_AUTO_INSTALL:-0}" = "1" ]; then
  missing=""
  for variable in SOCIALMEDIA_AGENCY_NAME SOCIALMEDIA_OWNER_NAME SOCIALMEDIA_OWNER_EMAIL \
                  SOCIALMEDIA_OWNER_PASSWORD SOCIALMEDIA_PUBLIC_ORIGIN; do
    eval "value=\${$variable:-}"
    [ -n "$value" ] || missing="$missing $variable"
  done
  if [ -n "$missing" ]; then
    echo "SOCIALMEDIA_AUTO_INSTALL=1 requires:$missing" >&2
    echo "Fix the variables or unset SOCIALMEDIA_AUTO_INSTALL and finish in /install." >&2
    exit 1
  fi
  if installation_pending; then
    echo "Automatic installation enabled: creating the first workspace..." >&2
  else
    echo "Automatic installation skipped: this instance is already installed." >&2
  fi
fi

if [ "${1:-}" = "--check" ]; then
  echo "Configuration OK."
  exit 0
fi

exec waitress-serve --listen="0.0.0.0:$PORT" --threads=4 app:app
