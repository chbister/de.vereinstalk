#!/bin/sh
# Flarum-Setup beim Containerstart, danach Übergabe an den
# webdevops-Entrypoint (/entrypoint -> supervisord).
#
# Verhalten, wenn /app/config.php fehlt:
#   - Datenbank enthält bereits Flarum-Tabellen -> nur config.php neu schreiben
#     (macht `docker compose down/up`-Zyklen ohne Datenverlust möglich,
#      da config.php bewusst NICHT in einem Volume liegt).
#   - Datenbank leer + FLARUM_AUTO_INSTALL=true -> `php flarum install -f`
#     unbeaufsichtigt ausführen (Werte aus .env, siehe .env.example).
#   - sonst -> normal starten, Web-Installer bleibt verfügbar.
set -eu

INSTALL_FILE=/tmp/flarum-install.yaml

if [ ! -f /app/config.php ]; then
  echo "[flarum] No config.php found, checking installation state ..."
  if php /usr/local/bin/flarum-setup.php wait-for-db; then
    if php /usr/local/bin/flarum-setup.php db-installed; then
      echo "[flarum] Database already installed, regenerating config.php ..."
      gosu application php /usr/local/bin/flarum-setup.php write-config
    elif [ "${FLARUM_AUTO_INSTALL:-false}" = "true" ]; then
      missing=""
      for var in FLARUM_ADMIN_USER FLARUM_ADMIN_PASSWORD FLARUM_ADMIN_EMAIL; do
        eval "val=\${$var:-}"
        if [ -z "$val" ]; then missing="$missing $var"; fi
      done
      if [ -n "$missing" ]; then
        echo "[flarum] ERROR: FLARUM_AUTO_INSTALL=true but missing:$missing" >&2
      elif [ "${#FLARUM_ADMIN_PASSWORD}" -lt 8 ]; then
        echo "[flarum] ERROR: FLARUM_ADMIN_PASSWORD must be at least 8 characters." >&2
      else
        echo "[flarum] Running unattended installation ..."
        gosu application php /usr/local/bin/flarum-setup.php write-install-file "$INSTALL_FILE"
        # Hinweis: -c erwartet einen Pfad relativ zu /app (Flarum hängt ihn an
        # den Base-Pfad an); ein absoluter Pfad würde zu /app//app/config.php.
        if gosu application php /app/flarum install -f "$INSTALL_FILE" -c config.php -n; then
          echo "[flarum] Installation successful."
          # Extensions nach Fresh-Install aktivieren: der Installer aktiviert nur
          # Core-Bundled-Extensions, keine Language-Packs. Kommagetrennte IDs
          # aus FLARUM_ENABLE_EXTENSIONS (nur hier, damit späteres manuelles
          # Deaktivieren im Admin-Panel Bestand hat).
          if [ -n "${FLARUM_ENABLE_EXTENSIONS:-}" ]; then
            OLD_IFS="$IFS"; IFS=','
            for ext in $FLARUM_ENABLE_EXTENSIONS; do
              ext=$(echo "$ext" | tr -d '[:space:]')
              if [ -z "$ext" ]; then
                continue
              fi
              if out=$(gosu application php /app/flarum extension:enable "$ext" -n 2>&1); then
                echo "[flarum] Extension enabled: $ext"
              elif echo "$out" | grep -q 'already enabled'; then
                # z. B. flarum-lang-english: vom Installer selbst aktiviert
                echo "[flarum] Extension already enabled: $ext"
              else
                echo "[flarum] WARNING: could not enable extension $ext: $out" >&2
              fi
            done
            IFS="$OLD_IFS"
          fi
        else
          echo "[flarum] ERROR: installation failed, web installer remains available." >&2
        fi
        rm -f "$INSTALL_FILE"
      fi
    else
      echo "[flarum] FLARUM_AUTO_INSTALL='${FLARUM_AUTO_INSTALL:-false}' (not 'true'), use the web installer."
    fi
  else
    echo "[flarum] WARNING: database unreachable, skipping setup." >&2
  fi
else
  echo "[flarum] config.php present, skipping setup."
fi

# Ausstehende Core-/Extension-Migrationen anwenden (z. B. nach Image-Updates
# mit neuer Flarum-Version; `composer update` führt diese NICHT aus).
# Erneutes Ausführen ist harmlos: bereits angewendete Migrationen laufen
# nicht nochmal. Ein Fehlschlag darf den Start nie blockieren.
if [ -f /app/config.php ]; then
  if gosu application php /app/flarum migrate -n; then
    echo "[flarum] Migrations applied."
  else
    echo "[flarum] WARNING: migrate failed, continuing anyway." >&2
  fi
fi

# Core-/Extension-Assets (JS/CSS/Fonts) bei jedem Start neu publizieren.
# /app/public/assets liegt auf einem Volume und würde sonst nach Rebuilds
# veraltete Dateien über den frischen Build legen (stille 404s, z. B. Fonts).
# Ein Fehlschlag darf den Start nie blockieren.
if [ -f /app/config.php ]; then
  if gosu application php /app/flarum assets:publish -n; then
    echo "[flarum] Assets published."
  else
    echo "[flarum] WARNING: assets:publish failed, continuing anyway." >&2
  fi
fi

# Theme-Farben und Willkommensnachricht aus der Umgebung übernehmen.
# Nur gesetzte Werte werden geschrieben und nur bei Abweichung — leere Vars
# lassen manuelle Admin-Änderungen in Ruhe. Farben brauchen gültiges Hex-Format
# (#rgb oder #rrggbb); im Nachrichtentext wird die Zeichenfolge \n zum Zeilen-
# umbruch. Farbänderungen markieren Assets als dirty (CSS-Rebuild beim nächsten
# Request), Textänderungen wirken sofort.
if [ -f /app/config.php ] && { [ -n "${FLARUM_THEME_PRIMARY:-}" ] || [ -n "${FLARUM_THEME_SECONDARY:-}" ] || [ -n "${FLARUM_WELCOME_TITLE:-}" ] || [ -n "${FLARUM_WELCOME_MESSAGE:-}" ] || [ -n "${FLARUM_DESCRIPTION:-}" ]; }; then
  gosu application php /app/flarum tinker <<'TINKER_EOF' 2>&1 | grep -E 'THEME (SET|KEEP|SKIP|DIRTY)|WELCOME (SET|KEEP)|DESCRIPTION (SET|KEEP)' || true
$s = app(Flarum\Settings\SettingsRepositoryInterface::class);
$dirty = false;
foreach (['theme_primary_color' => getenv('FLARUM_THEME_PRIMARY'), 'theme_secondary_color' => getenv('FLARUM_THEME_SECONDARY')] as $key => $val) {
    if ($val === false || $val === '') { continue; }
    $val = strtolower(trim($val));
    if (! preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $val)) { echo "THEME SKIP $key invalid: $val\n"; continue; }
    if ($s->get($key) !== $val) { $s->set($key, $val); echo "THEME SET $key $val\n"; $dirty = true; }
    else { echo "THEME KEEP $key\n"; }
}
foreach (['welcome_title' => getenv('FLARUM_WELCOME_TITLE'), 'welcome_message' => getenv('FLARUM_WELCOME_MESSAGE')] as $key => $val) {
    if ($val === false || $val === '') { continue; }
    $val = str_replace('\n', "\n", trim($val));
    if ($s->get($key) !== $val) { $s->set($key, $val); echo "WELCOME SET $key\n"; }
    else { echo "WELCOME KEEP $key\n"; }
}
foreach (['forum_description' => getenv('FLARUM_DESCRIPTION')] as $key => $val) {
    if ($val === false || $val === '') { continue; }
    $val = trim($val);
    if ($s->get($key) !== $val) { $s->set($key, $val); echo "DESCRIPTION SET $key\n"; }
    else { echo "DESCRIPTION KEEP $key\n"; }
}
// SettingsRepository::set() feuert kein Saved-Event (das macht nur der Admin-
// API-Controller) — Assets daher hier explizit als dirty markieren, damit der
// nächste Request das Theme-CSS neu baut (nur bei tatsächlicher Änderung).
if ($dirty) {
    foreach (['flarum.assets.forum', 'flarum.assets.admin'] as $name) {
        (new Flarum\Frontend\RecompileFrontendAssets(app($name), app(Flarum\Locale\LocaleManager::class), app('events'), $s))->markDirty();
    }
    echo "THEME DIRTY\n";
}
TINKER_EOF
fi

exec /entrypoint "$@"
