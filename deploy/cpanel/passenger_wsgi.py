"""Entry point para cPanel com "Setup Python App" (Passenger / LiteSpeed).

Passenger entrega o mount point em ``SCRIPT_NAME`` e o caminho interno em
``PATH_INFO``; ``app.py`` já respeita esse prefixo (todas as URLs, o cookie do
instalador e o callback Meta saem sob a subpasta). Este arquivo só garante:

* o projeto no ``sys.path``;
* dados persistentes fora da pasta pública (``dados/``);
* ``SOCIALMEDIA_BASE_PATH`` apenas como reserva, quando o Passenger não
  anunciar ``SCRIPT_NAME`` sozinho.

Ajuste ``SUBPASTA`` se publicar em outro endereço; deixe vazio quando a
aplicação for servida na raiz do domínio.
"""
import os
import sys
from pathlib import Path

SUBPASTA = os.environ.get("SOCIALMEDIA_SUBPATH", "/edijalmemoura/socialai")

BASE_DIR = Path(__file__).resolve().parent
if (BASE_DIR / "app.py").exists():
    PROJECT_DIR = BASE_DIR
else:  # arquivo em deploy/cpanel/
    PROJECT_DIR = BASE_DIR.parent
sys.path.insert(0, str(PROJECT_DIR))

os.environ.setdefault("SOCIALMEDIA_DATA", str(PROJECT_DIR / "dados"))
if SUBPASTA:
    os.environ.setdefault("SOCIALMEDIA_BASE_PATH", SUBPASTA.strip().rstrip("/"))
# HTTPS é terminado pelo LiteSpeed/Apache do cPanel.
os.environ.setdefault("COOKIE_SECURE", "1")

from app import app as application  # noqa: E402
