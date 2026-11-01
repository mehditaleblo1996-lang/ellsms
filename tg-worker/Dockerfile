# Telegram user-account service worker (docs/telegram-user-service.md). Python + Telethon; talks to
# Telegram and to the shared MySQL database only. Runs as an unprivileged user; reads campaign media
# from the read-only mount of storage/telegram-media at /data/media.
FROM python:3.12-slim
ENV PYTHONDONTWRITEBYTECODE=1 PYTHONUNBUFFERED=1 TZ=Asia/Tehran
RUN useradd --system --uid 10002 tgworker
WORKDIR /opt/tg-worker
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY tgworker ./tgworker
USER tgworker
HEALTHCHECK --interval=60s --timeout=10s --retries=3 CMD ["python", "-c", "import tgworker.config"]
ENTRYPOINT ["python", "-m", "tgworker.worker"]
