from __future__ import annotations

import hmac
import os
import re
import secrets
import sqlite3
import time
from datetime import datetime, timezone
from functools import wraps
from pathlib import Path

from flask import Flask, abort, g, jsonify, redirect, render_template, request, send_from_directory, session, url_for
from werkzeug.security import check_password_hash, generate_password_hash
from werkzeug.utils import secure_filename

BASE_DIR = Path(__file__).resolve().parent
DATA_DIR = Path(os.environ.get("SOCIALMEDIA_DATA", BASE_DIR / "instance"))
UPLOAD_DIR = DATA_DIR / "uploads"
DB_PATH = Path(os.environ.get("SOCIALMEDIA_DB", DATA_DIR / "socialmedia.db"))
ALLOWED_EXTENSIONS = {"png", "jpg", "jpeg", "webp"}
ROLES = {"owner", "admin", "editor", "viewer"}

app = Flask(__name__)
app.config.update(
    SECRET_KEY=os.environ.get("SECRET_KEY", "dev-only-change-this-secret-before-deploying"),
    MAX_CONTENT_LENGTH=8 * 1024 * 1024,
    SESSION_COOKIE_HTTPONLY=True,
    SESSION_COOKIE_SAMESITE="Lax",
    SESSION_COOKIE_SECURE=os.environ.get("COOKIE_SECURE", "0") == "1",
)
DATA_DIR.mkdir(parents=True, exist_ok=True)
UPLOAD_DIR.mkdir(parents=True, exist_ok=True)

SCHEMA = """
PRAGMA foreign_keys = ON;
CREATE TABLE IF NOT EXISTS companies (
 id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, slug TEXT NOT NULL UNIQUE,
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS users (
 id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
 name TEXT NOT NULL, email TEXT NOT NULL COLLATE NOCASE UNIQUE, password_hash TEXT NOT NULL,
 role TEXT NOT NULL DEFAULT 'owner' CHECK(role IN ('owner','admin','editor','viewer')),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_users_company ON users(company_id);
CREATE TABLE IF NOT EXISTS clients (
 id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER NOT NULL REFERENCES companies(id) ON DELETE CASCADE,
 company_name TEXT NOT NULL, trade_name TEXT, segment TEXT, description TEXT, products_services TEXT,
 audience TEXT, address TEXT, phone TEXT, whatsapp TEXT, website TEXT, instagram TEXT, facebook TEXT,
 business_goals TEXT, status TEXT NOT NULL DEFAULT 'active' CHECK(status IN ('active','inactive')),
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_clients_company ON clients(company_id, status, company_name);
CREATE TABLE IF NOT EXISTS client_branding (
 id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL UNIQUE REFERENCES clients(id) ON DELETE CASCADE,
 logo_path TEXT, primary_color TEXT NOT NULL DEFAULT '#3158d8', secondary_color TEXT NOT NULL DEFAULT '#f57a45',
 accent_color TEXT NOT NULL DEFAULT '#16a68a', typography TEXT, visual_style TEXT, communication_tone TEXT,
 slogan TEXT, graphic_elements TEXT, brand_manual TEXT, updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS client_assets (
 id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL REFERENCES clients(id) ON DELETE CASCADE,
 filename TEXT NOT NULL, original_name TEXT NOT NULL, asset_type TEXT NOT NULL DEFAULT 'reference',
 created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_assets_client ON client_assets(client_id);
"""


def get_db():
    if "db" not in g:
        g.db = sqlite3.connect(DB_PATH)
        g.db.row_factory = sqlite3.Row
        g.db.execute("PRAGMA foreign_keys = ON")
        g.db.execute("PRAGMA busy_timeout = 5000")
    return g.db


@app.teardown_appcontext
def close_db(_error=None):
    db = g.pop("db", None)
    if db is not None:
        db.close()


def init_db():
    db = sqlite3.connect(DB_PATH)
    db.executescript(SCHEMA)
    db.close()


init_db()


def csrf_token():
    if "csrf_token" not in session:
        session["csrf_token"] = secrets.token_urlsafe(32)
    return session["csrf_token"]


app.jinja_env.globals["csrf_token"] = csrf_token


@app.before_request
def security_and_csrf():
    if request.method in {"POST", "PUT", "PATCH", "DELETE"}:
        token = request.form.get("csrf_token") or request.headers.get("X-CSRF-Token")
        expected = session.get("csrf_token", "")
        if not token or not expected or not hmac.compare_digest(token, expected):
            if request.path.startswith("/api/"):
                return jsonify(error="Sessão expirada. Atualize a página e tente novamente."), 400
            abort(400, "Token de segurança inválido. Atualize a página e tente novamente.")
    if "user_id" in session:
        user = get_db().execute("SELECT * FROM users WHERE id=?", (session["user_id"],)).fetchone()
        if user:
            g.user = user
            g.company = get_db().execute("SELECT * FROM companies WHERE id=?", (user["company_id"],)).fetchone()
            g.client_count = get_db().execute("SELECT COUNT(*) FROM clients WHERE company_id=?", (user["company_id"],)).fetchone()[0]
        else:
            session.clear()


@app.after_request
def response_security_headers(response):
    response.headers["X-Content-Type-Options"] = "nosniff"
    response.headers["X-Frame-Options"] = "SAMEORIGIN"
    response.headers["Referrer-Policy"] = "strict-origin-when-cross-origin"
    response.headers["Content-Security-Policy"] = "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; connect-src 'self'; frame-ancestors 'self'"
    return response


def login_required(fn):
    @wraps(fn)
    def wrapped(*args, **kwargs):
        if not getattr(g, "user", None):
            if request.path.startswith("/api/"):
                return jsonify(error="Faça login para continuar."), 401
            return redirect(url_for("login", next=request.path))
        return fn(*args, **kwargs)
    return wrapped


def roles_required(*roles):
    def decorator(fn):
        @wraps(fn)
        @login_required
        def wrapped(*args, **kwargs):
            if g.user["role"] not in roles:
                if request.path.startswith("/api/"):
                    return jsonify(error="Você não tem permissão para esta ação."), 403
                abort(403)
            return fn(*args, **kwargs)
        return wrapped
    return decorator


def clean(value, limit=5000):
    return (value or "").strip()[:limit]


def valid_color(value):
    return bool(re.fullmatch(r"#[0-9a-fA-F]{6}", value or ""))


def client_for_tenant(client_id):
    return get_db().execute("SELECT * FROM clients WHERE id=? AND company_id=?", (client_id, g.user["company_id"])).fetchone()


def client_payload(client):
    branding = get_db().execute("SELECT * FROM client_branding WHERE client_id=?", (client["id"],)).fetchone()
    assets = get_db().execute("SELECT * FROM client_assets WHERE client_id=? ORDER BY id DESC", (client["id"],)).fetchall()
    return dict(client), dict(branding) if branding else {}, [dict(a) for a in assets]


def upload_file(file, client_id, kind):
    if not file or not file.filename:
        return None
    original = file.filename
    safe = secure_filename(original)
    ext = safe.rsplit(".", 1)[-1].lower() if "." in safe else ""
    if ext not in ALLOWED_EXTENSIONS:
        raise ValueError("Use um arquivo PNG, JPG ou WEBP.")
    signatures = {"png": lambda data: data.startswith(b"\x89PNG\r\n\x1a\n"), "jpg": lambda data: data.startswith(b"\xff\xd8\xff"), "jpeg": lambda data: data.startswith(b"\xff\xd8\xff"), "webp": lambda data: len(data) >= 12 and data[:4] == b"RIFF" and data[8:12] == b"WEBP"}
    header = file.stream.read(16)
    file.stream.seek(0)
    if not signatures[ext](header):
        raise ValueError("O conteúdo não corresponde a uma imagem PNG, JPG ou WEBP válida.")
    filename = f"{client_id}-{kind}-{secrets.token_hex(12)}.{ext}"
    file.save(UPLOAD_DIR / filename)
    return filename, original[:255]


def form_client_data():
    fields = ["company_name", "trade_name", "segment", "description", "products_services", "audience", "address", "phone", "whatsapp", "website", "instagram", "facebook", "business_goals"]
    data = {field: clean(request.form.get(field), 4000 if field in {"description", "products_services", "audience", "business_goals"} else 500) for field in fields}
    data["company_name"] = data["company_name"][:200]
    return data


@app.route("/")
def index():
    return redirect(url_for("dashboard") if getattr(g, "user", None) else url_for("login"))


@app.route("/register", methods=["GET", "POST"])
def register():
    if getattr(g, "user", None):
        return redirect(url_for("dashboard"))
    error = None
    if request.method == "POST":
        name, email, company = clean(request.form.get("name"), 100), clean(request.form.get("email"), 160).lower(), clean(request.form.get("company"), 120)
        password = request.form.get("password", "")
        if not name or not company or not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", email):
            error = "Preencha nome, agência e um e-mail válido."
        elif len(password) < 8:
            error = "A senha precisa ter pelo menos 8 caracteres."
        else:
            db = get_db()
            slug = re.sub(r"[^a-z0-9]+", "-", company.lower()).strip("-") or "agencia"
            slug = f"{slug}-{secrets.token_hex(3)}"
            try:
                cur = db.execute("INSERT INTO companies(name,slug) VALUES(?,?)", (company, slug))
                company_id = cur.lastrowid
                cur = db.execute("INSERT INTO users(company_id,name,email,password_hash,role) VALUES(?,?,?,?, 'owner')", (company_id, name, email, generate_password_hash(password)))
                db.commit()
                session.clear()
                session["user_id"] = cur.lastrowid
                csrf_token()
                return redirect(url_for("dashboard"))
            except sqlite3.IntegrityError:
                db.rollback()
                error = "Este e-mail já está cadastrado."
    return render_template("auth.html", mode="register", error=error)


# In-memory throttling is a small baseline for local/single-instance use. Production should use a shared store.
LOGIN_ATTEMPTS = {}


@app.route("/login", methods=["GET", "POST"])
def login():
    if getattr(g, "user", None):
        return redirect(url_for("dashboard"))
    error = None
    if request.method == "POST":
        email = clean(request.form.get("email"), 160).lower()
        bucket = request.remote_addr or "unknown"
        attempts, since = LOGIN_ATTEMPTS.get(bucket, (0, time.time()))
        if time.time() - since > 300:
            attempts, since = 0, time.time()
        if attempts >= 10:
            error = "Muitas tentativas. Aguarde alguns minutos e tente novamente."
        else:
            user = get_db().execute("SELECT * FROM users WHERE email=?", (email,)).fetchone()
            if user and check_password_hash(user["password_hash"], request.form.get("password", "")):
                LOGIN_ATTEMPTS.pop(bucket, None)
                session.clear()
                session["user_id"] = user["id"]
                csrf_token()
                return redirect(url_for("dashboard"))
            LOGIN_ATTEMPTS[bucket] = (attempts + 1, since)
            error = "E-mail ou senha incorretos."
    return render_template("auth.html", mode="login", error=error)


@app.post("/logout")
@login_required
def logout():
    session.clear()
    return redirect(url_for("login"))


@app.get("/dashboard")
@login_required
def dashboard():
    db = get_db()
    counts = db.execute("SELECT COUNT(*) total, SUM(CASE WHEN status='active' THEN 1 ELSE 0 END) active FROM clients WHERE company_id=?", (g.user["company_id"],)).fetchone()
    clients = db.execute("SELECT c.*, b.primary_color, b.logo_path FROM clients c LEFT JOIN client_branding b ON c.id=b.client_id WHERE c.company_id=? ORDER BY c.updated_at DESC LIMIT 5", (g.user["company_id"],)).fetchall()
    today = datetime.now(timezone.utc)
    weekdays = ["segunda-feira", "terça-feira", "quarta-feira", "quinta-feira", "sexta-feira", "sábado", "domingo"]
    months = ["janeiro", "fevereiro", "março", "abril", "maio", "junho", "julho", "agosto", "setembro", "outubro", "novembro", "dezembro"]
    date_label = f"{weekdays[today.weekday()]}, {today.day:02d} de {months[today.month - 1]}"
    return render_template("dashboard.html", counts=counts, clients=clients, date_label=date_label)


@app.get("/clients")
@login_required
def clients_page():
    rows = get_db().execute("SELECT c.*, b.primary_color, b.logo_path FROM clients c LEFT JOIN client_branding b ON c.id=b.client_id WHERE c.company_id=? ORDER BY c.company_name COLLATE NOCASE", (g.user["company_id"],)).fetchall()
    return render_template("clients.html", clients=rows)


@app.route("/clients/new", methods=["GET", "POST"])
@roles_required("owner", "admin", "editor")
def client_new():
    error = None
    if request.method == "POST":
        data = form_client_data()
        if not data["company_name"]:
            error = "O nome da empresa é obrigatório."
        else:
            db = get_db()
            cur = db.execute("INSERT INTO clients(company_id,company_name,trade_name,segment,description,products_services,audience,address,phone,whatsapp,website,instagram,facebook,business_goals) VALUES(:company_id,:company_name,:trade_name,:segment,:description,:products_services,:audience,:address,:phone,:whatsapp,:website,:instagram,:facebook,:business_goals)", {**data, "company_id": g.user["company_id"]})
            client_id = cur.lastrowid
            db.execute("INSERT INTO client_branding(client_id) VALUES(?)", (client_id,))
            db.commit()
            return redirect(url_for("client_detail", client_id=client_id) + "?created=1")
    return render_template("client_form.html", mode="new", client={}, branding={}, error=error)


@app.route("/clients/<int:client_id>", methods=["GET", "POST"])
@login_required
def client_detail(client_id):
    client = client_for_tenant(client_id)
    if not client:
        abort(404)
    db = get_db()
    branding = db.execute("SELECT * FROM client_branding WHERE client_id=?", (client_id,)).fetchone()
    assets = db.execute("SELECT * FROM client_assets WHERE client_id=? ORDER BY id DESC", (client_id,)).fetchall()
    client = dict(client)
    branding = dict(branding) if branding else {}
    error = None
    if request.method == "POST": 
        if g.user["role"] not in {"owner", "admin", "editor"}:
            abort(403)
        data = form_client_data()
        if not data["company_name"]:
            error = "O nome da empresa é obrigatório."
        else:
            db.execute("UPDATE clients SET company_name=:company_name,trade_name=:trade_name,segment=:segment,description=:description,products_services=:products_services,audience=:audience,address=:address,phone=:phone,whatsapp=:whatsapp,website=:website,instagram=:instagram,facebook=:facebook,business_goals=:business_goals,updated_at=CURRENT_TIMESTAMP WHERE id=:id AND company_id=:company_id", {**data, "id": client_id, "company_id": g.user["company_id"]})
            colors = [clean(request.form.get(key), 7) for key in ("primary_color", "secondary_color", "accent_color")]
            defaults = ["#3158d8", "#f57a45", "#16a68a"]
            colors = [value if valid_color(value) else defaults[index] for index, value in enumerate(colors)]
            db.execute("UPDATE client_branding SET primary_color=?,secondary_color=?,accent_color=?,typography=?,visual_style=?,communication_tone=?,slogan=?,graphic_elements=?,brand_manual=?,updated_at=CURRENT_TIMESTAMP WHERE client_id=?", (*colors, clean(request.form.get("typography"), 250), clean(request.form.get("visual_style"), 2000), clean(request.form.get("communication_tone"), 2000), clean(request.form.get("slogan"), 500), clean(request.form.get("graphic_elements"), 2000), clean(request.form.get("brand_manual"), 4000), client_id))
            db.commit()
            return redirect(url_for("client_detail", client_id=client_id) + "?saved=1")
        client = {**dict(client), **data}
        branding = {**dict(branding), **request.form}
    return render_template("client_detail.html", client=client, branding=branding, assets=assets, error=error)


@app.post("/clients/<int:client_id>/delete")
@roles_required("owner", "admin")
def client_delete(client_id):
    client = client_for_tenant(client_id)
    if not client:
        abort(404)
    db = get_db()
    assets = db.execute("SELECT filename FROM client_assets WHERE client_id=? UNION SELECT logo_path AS filename FROM client_branding WHERE client_id=?", (client_id, client_id)).fetchall()
    db.execute("DELETE FROM clients WHERE id=? AND company_id=?", (client_id, g.user["company_id"]))
    db.commit()
    for asset in assets:
        if asset["filename"]:
            try:
                (UPLOAD_DIR / asset["filename"]).unlink(missing_ok=True)
            except OSError:
                pass
    return redirect(url_for("clients_page") + "?deleted=1")


@app.post("/clients/<int:client_id>/branding/logo")
@roles_required("owner", "admin", "editor")
def upload_logo(client_id):
    if not client_for_tenant(client_id):
        abort(404)
    try:
        result = upload_file(request.files.get("file"), client_id, "logo")
    except ValueError as exc:
        return redirect(url_for("client_detail", client_id=client_id) + "?error=" + str(exc))
    if not result:
        return redirect(url_for("client_detail", client_id=client_id))
    filename, _original = result
    db = get_db()
    old = db.execute("SELECT logo_path FROM client_branding WHERE client_id=?", (client_id,)).fetchone()["logo_path"]
    db.execute("UPDATE client_branding SET logo_path=?,updated_at=CURRENT_TIMESTAMP WHERE client_id=?", (filename, client_id))
    db.commit()
    if old:
        (UPLOAD_DIR / old).unlink(missing_ok=True)
    return redirect(url_for("client_detail", client_id=client_id) + "?logo=uploaded")


@app.post("/clients/<int:client_id>/assets")
@roles_required("owner", "admin", "editor")
def upload_asset(client_id):
    if not client_for_tenant(client_id):
        abort(404)
    try:
        result = upload_file(request.files.get("file"), client_id, "ref")
    except ValueError as exc:
        return redirect(url_for("client_detail", client_id=client_id) + "?error=" + str(exc))
    if result:
        filename, original = result
        get_db().execute("INSERT INTO client_assets(client_id,filename,original_name) VALUES(?,?,?)", (client_id, filename, original))
        get_db().commit()
    return redirect(url_for("client_detail", client_id=client_id) + "?asset=uploaded")


@app.post("/clients/<int:client_id>/assets/<int:asset_id>/delete")
@roles_required("owner", "admin", "editor")
def delete_asset(client_id, asset_id):
    if not client_for_tenant(client_id):
        abort(404)
    db = get_db()
    asset = db.execute("SELECT * FROM client_assets WHERE id=? AND client_id=?", (asset_id, client_id)).fetchone()
    if not asset:
        abort(404)
    db.execute("DELETE FROM client_assets WHERE id=?", (asset_id,))
    db.commit()
    (UPLOAD_DIR / asset["filename"]).unlink(missing_ok=True)
    return redirect(url_for("client_detail", client_id=client_id) + "?asset=deleted")


@app.get("/uploads/<path:filename>")
@login_required
def uploaded_file(filename):
    db = get_db()
    permitted = db.execute("SELECT 1 FROM client_branding b JOIN clients c ON b.client_id=c.id WHERE b.logo_path=? AND c.company_id=? UNION SELECT 1 FROM client_assets a JOIN clients c ON a.client_id=c.id WHERE a.filename=? AND c.company_id=?", (filename, g.user["company_id"], filename, g.user["company_id"])).fetchone()
    if not permitted:
        abort(404)
    return send_from_directory(UPLOAD_DIR, filename, max_age=3600)


@app.route("/team", methods=["GET", "POST"])
@roles_required("owner", "admin")
def team_page():
    db = get_db()
    error = None
    if request.method == "POST":
        name = clean(request.form.get("name"), 100)
        email = clean(request.form.get("email"), 160).lower()
        password = request.form.get("password", "")
        requested_role = request.form.get("role", "editor")
        allowed = {"editor", "viewer"} | ({"admin"} if g.user["role"] == "owner" else set())
        if not name or not re.fullmatch(r"[^@\s]+@[^@\s]+\.[^@\s]+", email):
            error = "Informe nome e um e-mail válido."
        elif len(password) < 8:
            error = "A senha temporária precisa ter pelo menos 8 caracteres."
        elif requested_role not in allowed:
            error = "Você não pode atribuir esse nível de acesso."
        else:
            try:
                db.execute("INSERT INTO users(company_id,name,email,password_hash,role) VALUES(?,?,?,?,?)", (g.user["company_id"], name, email, generate_password_hash(password), requested_role))
                db.commit()
                return redirect(url_for("team_page") + "?added=1")
            except sqlite3.IntegrityError:
                db.rollback()
                error = "Este e-mail já está cadastrado."
    members = db.execute("SELECT id,name,email,role,created_at FROM users WHERE company_id=? ORDER BY CASE role WHEN 'owner' THEN 0 WHEN 'admin' THEN 1 WHEN 'editor' THEN 2 ELSE 3 END,name", (g.user["company_id"],)).fetchall()
    return render_template("team.html", members=members, error=error)


@app.get("/health")
def health():
    return {"status": "ok", "module": "stage-01", "database": "sqlite"}


@app.errorhandler(403)
def forbidden(_error):
    return render_template("error.html", code=403, message="Você não tem permissão para acessar este recurso."), 403


@app.errorhandler(404)
def not_found(_error):
    return render_template("error.html", code=404, message="Não encontramos esta página ou cliente."), 404


@app.errorhandler(413)
def too_large(_error):
    return render_template("error.html", code=413, message="A imagem ultrapassa o limite de 8 MB."), 413


if __name__ == "__main__":
    app.run(host="0.0.0.0", port=int(os.environ.get("PORT", 5000)), debug=os.environ.get("FLASK_DEBUG", "0") == "1")
