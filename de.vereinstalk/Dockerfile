# Statische Marketingseite (vereinstalk.de) als eigenständiges Image.
# Basis: nginx:alpine, Content + vHost-Config werden einkopiert, damit das
# Image ohne Bind-Mounts läuft (Voraussetzung für GHCR-Build + PROD-Deploy).
# Lokale DEV-Mounts aus docker-compose.yml überlagern diese Dateien bei
# Bedarf (Live-Edit ohne Rebuild).

FROM nginx:alpine

COPY nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docs/ /usr/share/nginx/html/

EXPOSE 80
