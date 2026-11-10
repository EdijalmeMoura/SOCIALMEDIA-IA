"""Production WSGI entry point for hosting that supports Python/Passenger."""
import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent
sys.path.insert(0, str(ROOT))

secret = os.environ.get("SECRET_KEY", "")
if len(secret) < 32 or secret == "dev-only-change-this-secret-before-deploying":
    raise RuntimeError("Configure a persistent SECRET_KEY of at least 32 characters.")
if os.environ.get("COOKIE_SECURE") != "1":
    raise RuntimeError("Configure HTTPS and set COOKIE_SECURE=1.")
data_dir = os.environ.get("SOCIALMEDIA_DATA", "")
if not data_dir or not Path(data_dir).is_absolute():
    raise RuntimeError("Set SOCIALMEDIA_DATA to an absolute, private persistent directory.")

from app import app as application  # noqa: E402
