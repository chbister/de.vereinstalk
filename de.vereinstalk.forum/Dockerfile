# Flarum auf Basis von webdevops/php-nginx
# Doku: https://docs.flarum.org/install/#nginx
# Basis: https://dockerfile.readthedocs.io/en/latest/content/DockerImages/dockerfiles/php-nginx.html
#
# Das webdevops-Image bringt Nginx + PHP-FPM + Supervisor mit.
# Flarums Webroot ist /app/public -> via WEB_DOCUMENT_ROOT gesetzt.
# Flarums Nginx-Regeln (.nginx.conf) liegen in docker/nginx-flarum.conf
# und werden als vhost-Include eingebunden.

FROM webdevops/php-nginx:8.4

ENV WEB_DOCUMENT_ROOT=/app/public \
    WEB_DOCUMENT_INDEX=index.php \
    COMPOSER_ALLOW_SUPERUSER=1 \
    PHP_DATE_TIMEZONE=UTC \
    PHP_MEMORY_LIMIT=512M \
    PHP_MAX_EXECUTION_TIME=300 \
    PHP_POST_MAX_SIZE=50M \
    PHP_UPLOAD_MAX_FILESIZE=50M \
    SERVICE_NGINX_CLIENT_MAX_BODY_SIZE=50m

WORKDIR /app

# Flarum 2.x via Composer installieren (braucht PHP 8.3+ mit
# curl, dom, fileinfo, gd, json, mbstring, openssl, pdo_mysql,
# tokenizer, zip, session -> im webdevops-Image bereits enthalten).
# Installation in /tmp, danach nach /app kopieren, damit ein
# ggf. vorhandener /app-Inhalt des Basis-Images nicht stört.
RUN rm -rf /tmp/flarum-build \
    && composer create-project flarum/flarum:^2.0 /tmp/flarum-build --stability=beta --no-interaction --no-progress \
    && rm -rf /app/* \
    && cp -a /tmp/flarum-build/. /app/ \
    && rm -rf /tmp/flarum-build \
    && php -m | grep -Ei 'curl|dom|fileinfo|gd|mbstring|openssl|pdo_mysql|tokenizer|zip|session' \
    && chown -R application:application /app \
    && chmod 775 -R /app/storage /app/public/assets

# FoF-Extensions (Aktivierung per CLI nach dem Start, Zustand liegt in der DB).
# Hinweis: fof/passport ist NICHT enthalten — letzte Version 1.1.1 braucht
# flarum/core ^1.2.0 und ist inkompatibel mit 2.x (kein 2.x-Release vorhanden).
RUN composer require fof/share-social fof/drafts:"*" fof/moderator-notes fof/polls fof/oauth --no-interaction --no-progress --no-audit \
    && chown -R application:application /app/vendor /app/composer.json /app/composer.lock

# Sprachpakete: alle Packs der flarum-lang-Organisation (Stand 2026-10-09),
# plus Englisch von flarum/lang-english. Aktiviert werden sie per CLI
# nach dem Start (Zustand liegt in der DB) bzw. im Admin-Panel.
# Ausgenommen: about + utils (keine Sprachpakete), malaysian (archiviert).
RUN composer require \
      flarum-lang/afrikaans flarum-lang/albanian flarum-lang/arabic flarum-lang/armenian flarum-lang/asturian flarum-lang/azerbaijani \
      flarum-lang/basque flarum-lang/belarusian flarum-lang/bengali flarum-lang/bosnian flarum-lang/brazilian flarum-lang/breton \
      flarum-lang/bulgarian flarum-lang/burmese flarum-lang/catalan flarum-lang/chinese-simplified flarum-lang/chinese-traditional flarum-lang/croatian \
      flarum-lang/czech flarum-lang/danish flarum-lang/dutch flarum-lang/esperanto flarum-lang/estonian flarum-lang/filipino \
      flarum-lang/finnish flarum-lang/french flarum-lang/galician flarum-lang/georgian flarum-lang/german flarum-lang/greek \
      flarum-lang/hebrew flarum-lang/hindi flarum-lang/hungarian flarum-lang/icelandic flarum-lang/indonesian flarum-lang/irish \
      flarum-lang/italian flarum-lang/japanese flarum-lang/kabyle flarum-lang/kannada flarum-lang/kazakh flarum-lang/khmer \
      flarum-lang/korean flarum-lang/kurdish-central flarum-lang/kurdish-northern flarum-lang/latvian flarum-lang/lithuanian flarum-lang/macedonian \
      flarum-lang/malayalam flarum-lang/marathi flarum-lang/nepali flarum-lang/norwegian-bokmal flarum-lang/norwegian-nynorsk flarum-lang/occitan \
      flarum-lang/persian flarum-lang/polish flarum-lang/portuguese flarum-lang/punjabi flarum-lang/romanian flarum-lang/russian \
      flarum-lang/sardinian flarum-lang/serbian-cyrillic flarum-lang/serbian-latin flarum-lang/sinhala flarum-lang/slovak flarum-lang/slovenian \
      flarum-lang/spanish flarum-lang/spanish-argentina flarum-lang/spanish-mexico flarum-lang/swedish flarum-lang/tagalog flarum-lang/tajik \
      flarum-lang/tamil flarum-lang/tatar flarum-lang/telugu flarum-lang/thai flarum-lang/toki-pona flarum-lang/turkish \
      flarum-lang/turkmen flarum-lang/ukrainian flarum-lang/urdu flarum-lang/uyghur flarum-lang/uzbek flarum-lang/vietnamese \
      flarum-lang/welsh flarum/lang-english \
      --no-interaction --no-progress --no-audit \
    && chown -R application:application /app/vendor /app/composer.json /app/composer.lock \
    # Kaputte ioncube-Referenz entfernen (ini verweist auf nicht vorhandene .so,
    # verursacht sonst "Failed loading ..."-Warnungen bei jedem PHP-Aufruf)
    && rm -f /usr/local/etc/php/conf.d/00-ioncube.ini

# Backport: Upstream-Fix für unaufgelöste `=>`-Referenzen im en-Katalog
# (in v2.0.0-rc.8 noch nicht enthalten, siehe docker/patches-Skript).
COPY docker/patches/apply-flarum-translator-fix.php /tmp/apply-flarum-translator-fix.php
RUN php /tmp/apply-flarum-translator-fix.php \
    && php -l /app/vendor/flarum/core/src/Locale/Translator.php \
    && rm /tmp/apply-flarum-translator-fix.php

# Flarum-URL-Rewriting für Nginx (entspricht `include /path/to/flarum/.nginx.conf;`)
COPY docker/nginx-flarum.conf /opt/docker/etc/nginx/vhost.common.d/10-flarum.conf

# Unbeaufsichtigte Installation per Env (`php flarum install -f`, siehe .env.example).
# Eigener Entrypoint, der das Setup ausführt und danach an /entrypoint übergibt.
COPY docker/entrypoint.sh /usr/local/bin/flarum-entrypoint.sh
COPY docker/flarum-setup.php /usr/local/bin/flarum-setup.php
RUN chmod +x /usr/local/bin/flarum-entrypoint.sh
# Hinweis: Das Setzen von ENTRYPOINT löscht den CMD des Basis-Images,
# daher supervisord hier erneut als Default-CMD festlegen.
ENTRYPOINT ["/usr/local/bin/flarum-entrypoint.sh"]
CMD ["supervisord"]

EXPOSE 80 443
