#!/bin/sh
# Install dependencies into a local virtual environment; never modify existing data or secrets.
set -eu
cd "$(dirname "$0")/.."
PYTHON=${PYTHON:-python3}
if ! command -v "$PYTHON" >/dev/null 2>&1; then
  echo "Python não encontrado: $PYTHON" >&2
  exit 1
fi
"$PYTHON" -c 'import sys; assert sys.version_info >= (3, 11), "Python 3.11 ou superior é necessário"'
if [ ! -d .venv ]; then
  "$PYTHON" -m venv .venv
fi
.venv/bin/python -m pip install -r requirements.txt
printf '\nDependências instaladas. Configure o Python da aplicação para %s/.venv/bin/python e siga docs/deploy-plesk.md.\n' "$(pwd)"
