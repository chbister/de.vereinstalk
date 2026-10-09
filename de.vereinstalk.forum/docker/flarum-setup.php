<?php

/*
 * Helper für die unbeaufsichtigte Flarum-Installation im Docker-Container.
 *
 * Modi:
 *   wait-for-db               Wartet (max. 90s), bis MariaDB erreichbar ist
 *   db-installed              Exit 0, wenn Flarum-Tabellen bereits existieren
 *   write-install-file <pfad> Schreibt die YAML-Datei für `php flarum install -f`
 *   write-config              Schreibt /app/config.php für eine bereits
 *                             installierte Datenbank neu (gleiche Struktur wie
 *                             Flarum\Install\Steps\StoreConfig)
 *
 * Konfiguration ausschließlich über Umgebungsvariablen (siehe .env.example).
 */

require '/app/vendor/autoload.php';

use Flarum\Install\BaseUrl;
use Flarum\Install\DatabaseConfig;
use Symfony\Component\Yaml\Yaml;

function env(string $key, string $default = ''): string
{
    $val = getenv($key);

    return $val === false ? $default : $val;
}

function validHexColor(string $value): string
{
    $value = strtolower(trim($value));

    return preg_match('/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $value) ? $value : '';
}

function welcomeText(string $value): string
{
    return str_replace('\n', "\n", trim($value));
}

function dbConfig(): DatabaseConfig
{
    return new DatabaseConfig(
        'mariadb',
        env('FLARUM_DB_HOST', 'mariadb'),
        (int) (env('FLARUM_DB_PORT', '3306') ?: 3306),
        env('MARIADB_DATABASE', 'flarum'),
        'public',
        env('MARIADB_USER', 'flarum'),
        env('MARIADB_PASSWORD'),
        env('FLARUM_DB_PREFIX')
    );
}

function pdo(): PDO
{
    return new PDO(
        sprintf(
            'mysql:host=%s;port=%s;dbname=%s',
            env('FLARUM_DB_HOST', 'mariadb'),
            env('FLARUM_DB_PORT', '3306') ?: '3306',
            env('MARIADB_DATABASE', 'flarum')
        ),
        env('MARIADB_USER', 'flarum'),
        env('MARIADB_PASSWORD'),
        [PDO::ATTR_TIMEOUT => 5]
    );
}

$mode = $argv[1] ?? 'help';

try {
    switch ($mode) {
        case 'wait-for-db':
            $deadline = time() + 90;
            while (true) {
                try {
                    pdo();
                    echo "Database reachable.\n";
                    exit(0);
                } catch (Throwable $e) {
                    if (time() >= $deadline) {
                        fwrite(STDERR, 'Database not reachable after 90s: '.$e->getMessage()."\n");
                        exit(1);
                    }
                    sleep(2);
                }
            }
            break;

        case 'db-installed':
            // True, wenn die Flarum-Tabelle <prefix>settings bereits existiert.
            $stmt = pdo()->prepare(
                'SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ?'
            );
            $stmt->execute([env('MARIADB_DATABASE', 'flarum'), env('FLARUM_DB_PREFIX').'settings']);
            exit($stmt->fetch() ? 0 : 1);
            break;

        case 'write-install-file':
            $path = $argv[2] ?? '/tmp/flarum-install.yaml';
            $data = [
                'debug' => filter_var(env('FLARUM_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
                'baseUrl' => env('FLARUM_URL', 'http://localhost:8081'),
                'databaseConfiguration' => [
                    'driver' => 'mariadb',
                    'host' => env('FLARUM_DB_HOST', 'mariadb'),
                    'port' => (int) (env('FLARUM_DB_PORT', '3306') ?: 3306),
                    'database' => env('MARIADB_DATABASE', 'flarum'),
                    'username' => env('MARIADB_USER', 'flarum'),
                    'password' => env('MARIADB_PASSWORD'),
                    'prefix' => env('FLARUM_DB_PREFIX'),
                ],
                'adminUser' => [
                    'username' => env('FLARUM_ADMIN_USER'),
                    'password' => env('FLARUM_ADMIN_PASSWORD'),
                    'email' => env('FLARUM_ADMIN_EMAIL'),
                ],
                'settings' => array_filter([
                    'forum_title' => env('FLARUM_TITLE', 'Vereinstalk'),
                    'forum_description' => trim(env('FLARUM_DESCRIPTION')),
                    'default_locale' => strtolower(trim(env('FLARUM_DEFAULT_LOCALE', 'de'))),
                    'theme_primary_color' => validHexColor(env('FLARUM_THEME_PRIMARY')),
                    'theme_secondary_color' => validHexColor(env('FLARUM_THEME_SECONDARY')),
                    'welcome_title' => welcomeText(env('FLARUM_WELCOME_TITLE')),
                    'welcome_message' => welcomeText(env('FLARUM_WELCOME_MESSAGE')),
                ]),
            ];
            file_put_contents($path, Yaml::dump($data));
            chmod($path, 0600);
            echo "Install file written.\n";
            break;

        case 'write-config':
            $config = [
                'debug' => filter_var(env('FLARUM_DEBUG', 'false'), FILTER_VALIDATE_BOOLEAN),
                'database' => dbConfig()->toArray(),
                'url' => (string) BaseUrl::fromString(env('FLARUM_URL', 'http://localhost:8081')),
                'paths' => ['api' => 'api', 'admin' => 'admin'],
                'headers' => ['poweredByHeader' => true, 'referrerPolicy' => 'same-origin'],
                'queue' => ['driver' => 'sync'],
            ];
            file_put_contents('/app/config.php', '<?php return '.var_export($config, true).';');
            echo "config.php regenerated.\n";
            break;

        default:
            fwrite(STDERR, "Usage: flarum-setup.php {wait-for-db|db-installed|write-install-file <path>|write-config}\n");
            exit(2);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'ERROR: '.$e->getMessage()."\n");
    exit(1);
}
