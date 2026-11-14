#!/usr/bin/env python3
"""CGI entry point para cPanel sem "Setup Python App" (Passenger).

Funciona em duas situações:

* ao lado do projeto (``deploy/cpanel/app.cgi``), ou
* copiado para a pasta pública da aplicação
  (``~/public_html/edijalmemoura/socialai/app.cgi``).

O Apache/LiteSpeed entrega, no modo CGI, um ``SCRIPT_NAME`` que termina em
``/app.cgi`` e um ``PATH_INFO`` vazio. Como o produto é servido dentro de uma
subpasta, este arquivo reconstrói o ambiente WSGI que a aplicação espera:
``SCRIPT_NAME`` = subpasta e ``PATH_INFO`` = caminho interno. Sem isso, todas as
URLs geradas por ``url_for`` sairiam com ``/app.cgi`` no meio.

Requer Flask + Pillow + cryptography + waitress instalados no interpretador do
cPanel ("Setup Python App" > Add packages) e permissão de execução (0755).
"""
import os
import re
import sys
from pathlib import Path
from urllib.parse import urlsplit

BASE_DIR = Path(__file__).resolve().parent
# O projeto pode estar na própria pasta do script ou um nível acima (deploy/).
PROJECT_DIR = BASE_DIR if (BASE_DIR / "app.py").exists() else BASE_DIR.parent
sys.path.insert(0, str(PROJECT_DIR))

# O nome deste script no caminho que o servidor web monta.
_EXECUTAVEL = re.compile(r"/(?:app|index)\.cgi", re.IGNORECASE)


def _normalizar_caminho(script_name: str, request_uri: str, path_info: str):
    """Deriva ``(subpasta, caminho_interno)`` a partir do ambiente do CGI.

    O servidor pode entregar o script de três jeitos; todos terminam em
    ``("/edijalmemoura/socialai", "/install")``:

    * ``SCRIPT_NAME=/pasta/app.cgi`` + ``PATH_INFO=/install``
    * ``SCRIPT_NAME=/pasta/app.cgi/install`` (rewrite sem split do Apache)
    * ``SCRIPT_NAME=/pasta/app.cgi`` + ``REQUEST_URI=/pasta/app.cgi/install``
    """
    prefixo = (script_name or "").strip()
    resto = ""
    encontrado = None
    for encontrado in _EXECUTAVEL.finditer(prefixo):
        pass
    if encontrado:
        prefixo, resto = prefixo[: encontrado.start()], prefixo[encontrado.end():]
    if prefixo and not prefixo.startswith("/"):
        prefixo = "/" + prefixo
    prefixo = prefixo.rstrip("/")

    if path_info:
        caminho = resto + path_info
    else:
        # Alguns servidores não preenchem PATH_INFO quando o .htaccess reescreve
        # tudo para o CGI; a URI original é a fonte confiável nesse caso (e já
        # contém o que veio depois do executável).
        caminho = urlsplit(request_uri or "").path
        if prefixo and caminho.startswith(prefixo):
            caminho = caminho[len(prefixo):]
        executavel = _EXECUTAVEL.match(caminho)
        if executavel:
            caminho = caminho[executavel.end():]
    if not caminho.startswith("/"):
        caminho = "/" + caminho
    return prefixo, caminho


_SUBPASTA, _CAMINHO = _normalizar_caminho(
    os.environ.get("SCRIPT_NAME", ""),
    os.environ.get("REQUEST_URI", ""),
    os.environ.get("PATH_INFO", ""),
)

if _SUBPASTA:
    os.environ.setdefault("SOCIALMEDIA_BASE_PATH", _SUBPASTA)
    os.environ["SCRIPT_NAME"] = _SUBPASTA
os.environ["PATH_INFO"] = _CAMINHO
os.environ.setdefault("SOCIALMEDIA_DATA", str(PROJECT_DIR / "dados"))
os.environ.setdefault("COOKIE_SECURE", "1")

from wsgiref.handlers import CGIHandler  # noqa: E402

from app import app  # noqa: E402

if __name__ == "__main__":
    CGIHandler().run(app)
