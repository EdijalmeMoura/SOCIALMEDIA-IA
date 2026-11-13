FROM python:3.12-slim

ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1 \
    SOCIALMEDIA_DATA=/data \
    COOKIE_SECURE=1 \
    PORT=5000

WORKDIR /app

COPY requirements.txt ./
RUN pip install --no-cache-dir -r requirements.txt \
    && groupadd --system app \
    && useradd --uid 10001 --gid app --no-create-home --home-dir /nonexistent --shell /usr/sbin/nologin app \
    && mkdir -p /data \
    && chown app:app /data

COPY app.py meta_integration.py installer.py ./
COPY templates ./templates
COPY static ./static
COPY docs ./docs
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint
RUN chmod 0555 /usr/local/bin/docker-entrypoint \
    && chown -R app:app /app

USER app
EXPOSE 5000

HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD python -c "import os, urllib.request; urllib.request.urlopen('http://127.0.0.1:' + os.environ.get('PORT', '5000') + '/health', timeout=3).read()" || exit 1

ENTRYPOINT ["/usr/local/bin/docker-entrypoint"]
