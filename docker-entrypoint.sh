#!/bin/sh
set -eu

if [ "${COOKIE_SECURE:-0}" != "1" ]; then
  echo "COOKIE_SECURE=1 is required behind HTTPS." >&2
  exit 1
fi

if [ -z "${SECRET_KEY:-}" ] || [ "${SECRET_KEY}" = "dev-only-change-this-secret-before-deploying" ] || [ "${#SECRET_KEY}" -lt 32 ]; then
  echo "Set a persistent SECRET_KEY with at least 32 characters before starting." >&2
  exit 1
fi

PORT="${PORT:-5000}"
case "$PORT" in
  ""|*[!0-9]*) echo "PORT must be a numeric TCP port." >&2; exit 1 ;;
esac

exec waitress-serve --listen="0.0.0.0:$PORT" --threads=4 app:app
