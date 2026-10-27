<?php

namespace App\Install;

use PDO;
use Throwable;

/**
 * Installing Booklet without a terminal, for ordinary shared hosting: the
 * files are uploaded, the site is opened, and this page checks the server,
 * makes the database and writes the settings file (.env). Laravel takes over
 * from there (migrations, then First setup).
 *
 * It runs before Laravel starts (public/index.php asks it first), and only
 * while there's no .env: once the settings file is written it's never
 * reached again.
 *
 * Plain PHP on purpose: without a .env there's no app key, so nothing of
 * Laravel's that needs one (cookies, sessions) can run yet.
 */
final class WebInstaller
{
    public const PHP = '8.2.0';

    /** Needed to run at all. */
    public const EXTENSIONS = ['ctype', 'dom', 'fileinfo', 'filter', 'json', 'mbstring', 'openssl', 'pdo', 'session', 'tokenizer', 'xml'];

    /** Nice to have: what each one is for. */
    public const OPTIONAL = [
        'zip' => 'backups, and updating Booklet from the book',
        'gd' => 'smaller copies of pictures (or imagick)',
        'curl' => 'faster downloads from GitHub and other services',
        'intl' => 'dates and numbers in other languages',
    ];

    /** The file Laravel's finishing step looks for, with the one-time code in it. */
    public const TOKEN_FILE = 'storage/framework/booklet-install.json';

    public function __construct(private readonly string $base) {}

    /** Is there anything to do? (No settings file yet.) */
    public static function needed(string $base): bool
    {
        // Settings given by the server itself (containers, some hosts) count as installed too.
        return ! is_file(rtrim($base, '/').'/.env') && (string) getenv('APP_KEY') === '' && ($_SERVER['APP_KEY'] ?? '') === '';
    }

    /**
     * The page, or what happens after the form is sent.
     *
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    public function handle(array $server, array $post): array
    {
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $url = $this->siteUrl($server);
        $checks = $this->checks();
        $form = $this->defaults($post);
        $problem = null;

        if ($method === 'POST' && ($post['action'] ?? '') === 'install') {
            if (! $this->ready($checks)) {
                $problem = 'Some of the checks on the left need fixing first.';
            } else {
                try {
                    $token = $this->install($form, $url);

                    return ['status' => 303, 'headers' => ['Location' => $url.'/install/finish?token='.$token, 'Cache-Control' => 'no-store'], 'body' => ''];
                } catch (InstallProblem $e) {
                    $problem = $e->getMessage();
                }
            }
        }

        return [
            'status' => 200,
            'headers' => ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'no-store', 'X-Robots-Tag' => 'noindex', 'X-Frame-Options' => 'DENY'],
            'body' => $this->render(['checks' => $checks, 'ready' => $this->ready($checks), 'form' => $form, 'problem' => $problem, 'url' => $url, 'drivers' => $this->drivers()]),
        ];
    }

    // The server ---------------------------------------------------------------------------

    /**
     * What the server has. Each: label, ok, needed, text (what's wrong and how to fix it).
     *
     * @return array<int, array{label: string, ok: bool, needed: bool, text: string}>
     */
    public function checks(): array
    {
        $out = [];
        $php = version_compare(PHP_VERSION, self::PHP, '>=');
        $out[] = ['label' => 'PHP '.PHP_VERSION, 'ok' => $php, 'needed' => true,
            'text' => $php ? 'New enough (Booklet needs '.substr(self::PHP, 0, 3).' or newer).' : 'Booklet needs PHP '.substr(self::PHP, 0, 3).' or newer. Most hosting panels let you choose the PHP version (look for “PHP version” or “MultiPHP”).'];

        $missing = array_values(array_filter(self::EXTENSIONS, fn ($e) => ! extension_loaded($e)));
        $out[] = ['label' => 'PHP extensions', 'ok' => $missing === [], 'needed' => true,
            'text' => $missing === [] ? 'All there.' : 'Missing: '.implode(', ', $missing).'. Turn them on in your hosting panel’s PHP settings (“PHP extensions” or “Select PHP version”).'];

        $drivers = $this->drivers();
        $out[] = ['label' => 'A database', 'ok' => $drivers !== [], 'needed' => true,
            'text' => $drivers === [] ? 'PHP can’t reach a database: turn on pdo_sqlite (simplest) or pdo_mysql.' : 'Can use '.implode(' or ', array_map(fn ($d) => $d === 'sqlite' ? 'a database file (SQLite)' : 'MySQL or MariaDB', $drivers)).'.'];

        foreach (['storage', 'storage/framework', 'storage/logs', 'bootstrap/cache', '.'] as $dir) {
            $path = $this->path($dir);
            $ok = is_dir($path) && is_writable($path);
            $out[] = ['label' => $dir === '.' ? 'Booklet’s folder (for its settings file)' : 'The '.$dir.' folder', 'ok' => $ok, 'needed' => true,
                'text' => $ok ? 'Booklet can write here.' : 'Booklet needs to write here. In your file manager, give the folder permission 755 (or 775), and make sure it belongs to your account.'];
        }

        foreach (self::OPTIONAL as $ext => $for) {
            $ok = extension_loaded($ext) || ($ext === 'gd' && extension_loaded('imagick'));
            $out[] = ['label' => $ext, 'ok' => $ok, 'needed' => false,
                'text' => $ok ? 'For '.$for.'.' : 'Not on: Booklet works without it, but it’s used for '.$for.'.'];
        }

        return $out;
    }

    /** @param array<int, array{ok: bool, needed: bool}> $checks */
    public function ready(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['needed'] && ! $check['ok']) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> sqlite and/or mysql */
    public function drivers(): array
    {
        $have = class_exists(PDO::class) ? PDO::getAvailableDrivers() : [];

        return array_values(array_filter(['sqlite', 'mysql'], fn ($d) => in_array($d, $have, true)));
    }

    // Installing ---------------------------------------------------------------------------

    /** The form as sent (or as it starts). */
    public function defaults(array $post): array
    {
        $pick = fn (string $key, string $default = '') => trim(is_string($post[$key] ?? null) ? $post[$key] : $default);
        $drivers = $this->drivers();

        return [
            'driver' => in_array($post['driver'] ?? null, $drivers, true) ? $post['driver'] : ($drivers[0] ?? 'sqlite'),
            'host' => $pick('host', 'localhost'),
            'port' => $pick('port', '3306'),
            'database' => $pick('database'),
            'username' => $pick('username'),
            // A password is kept exactly as typed (spaces too).
            'password' => is_string($post['password'] ?? null) ? $post['password'] : '',
        ];
    }

    /**
     * Make the database, write .env, and leave a one-time code for the
     * finishing step. Returns that code.
     */
    public function install(array $form, string $url): string
    {
        if (is_file($this->path('.env'))) {
            throw new InstallProblem('Booklet is already installed here.');
        }

        $env = ['APP_NAME' => 'Booklet', 'APP_ENV' => 'production', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'APP_DEBUG' => 'false', 'APP_URL' => $url, 'LOG_LEVEL' => 'warning'];

        if ($form['driver'] === 'mysql') {
            $this->connectMysql($form);
            $env += ['DB_CONNECTION' => 'mysql', 'DB_HOST' => $form['host'], 'DB_PORT' => $form['port'],
                'DB_DATABASE' => $form['database'], 'DB_USERNAME' => $form['username'], 'DB_PASSWORD' => $form['password']];
        } else {
            $file = $this->path('database/database.sqlite');
            if (! is_file($file) && @file_put_contents($file, '') === false) {
                throw new InstallProblem('Booklet couldn’t make its database file in the database folder. Give that folder permission 755 (or 775) and try again.');
            }
            try {
                (new PDO('sqlite:'.$file))->exec('PRAGMA user_version');
            } catch (Throwable $e) {
                throw new InstallProblem('The database file was made but can’t be opened: '.$e->getMessage());
            }
            $env += ['DB_CONNECTION' => 'sqlite'];
        }

        $template = is_file($this->path('.env.example')) ? (string) file_get_contents($this->path('.env.example')) : '';
        $contents = self::withValues($template, $env);

        // The one-time code first, so .env never exists without a way to finish.
        $token = bin2hex(random_bytes(20));
        if (@file_put_contents($this->path(self::TOKEN_FILE), json_encode(['token' => hash('sha256', $token), 'at' => time()])) === false) {
            throw new InstallProblem('Booklet couldn’t write to storage/framework. Give it permission 755 (or 775) and try again.');
        }
        $temp = $this->path('.env.'.bin2hex(random_bytes(4)));
        if (@file_put_contents($temp, $contents) === false || ! @rename($temp, $this->path('.env'))) {
            @unlink($temp);
            throw new InstallProblem('Booklet couldn’t write its settings file (.env) in its folder. Give the folder permission 755 (or 775) and try again.');
        }
        @chmod($this->path('.env'), 0640);

        return $token;
    }

    protected function connectMysql(array $form): void
    {
        foreach (['host' => 'the server', 'database' => 'the database’s name', 'username' => 'the username'] as $key => $what) {
            if ($form[$key] === '') {
                throw new InstallProblem('Fill in '.$what.'. Your hosting panel shows it under “MySQL databases”.');
            }
        }
        if (! preg_match('/^\d{1,5}$/', $form['port'])) {
            throw new InstallProblem('The port is a number (usually 3306).');
        }
        if (! preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $form['database'])) {
            throw new InstallProblem('A database name is letters, numbers and _ only.');
        }

        $dsn = 'mysql:host='.$form['host'].';port='.$form['port'].';charset=utf8mb4';
        $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 6];
        try {
            new PDO($dsn.';dbname='.$form['database'], $form['username'], $form['password'], $options);

            return;
        } catch (Throwable $e) {
            $code = (int) ($e instanceof \PDOException ? ($e->errorInfo[1] ?? 0) : 0);
            if ($code !== 1049) { // anything but "no such database"
                throw new InstallProblem(match ($code) {
                    1045 => 'The username or password wasn’t accepted. Check them in your hosting panel (MySQL databases), and that the user is added to the database.',
                    2002, 2005, 2006 => 'Booklet couldn’t reach the database server at '.$form['host'].'. On most hosting it’s “localhost”.',
                    default => 'The database said: '.$e->getMessage(),
                });
            }
        }

        // No such database yet: make it, if this user may.
        try {
            $pdo = new PDO($dsn, $form['username'], $form['password'], $options);
            $pdo->exec('CREATE DATABASE `'.$form['database'].'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        } catch (Throwable) {
            throw new InstallProblem('There’s no database called '.$form['database'].', and this user can’t make one. Make it in your hosting panel (MySQL databases), add the user to it, then try again.');
        }
    }

    /**
     * Put values into a .env (replacing a line, or a commented-out one, or adding it).
     *
     * @param  array<string, string>  $values
     */
    public static function withValues(string $env, array $values): string
    {
        foreach ($values as $key => $value) {
            $line = $key.'='.self::quote($value);
            $pattern = '/^#?\s*'.preg_quote($key, '/').'=.*$/m';
            $env = preg_match($pattern, $env) ? preg_replace_callback($pattern, fn () => $line, $env, 1) : rtrim($env)."\n".$line."\n";
        }

        return $env;
    }

    /** A value as .env reads it back exactly: plain, or in quotes when it has spaces or signs. */
    public static function quote(string $value): string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_.:\/+=@-]+$/', $value)) {
            return $value;
        }
        if (! str_contains($value, "'") && ! str_contains($value, "\n")) {
            return "'".$value."'"; // single quotes: read exactly as written
        }

        return '"'.str_replace(['\\', '"', '$', "\n"], ['\\\\', '\\"', '\\$', '\\n'], $value).'"';
    }

    // Addresses and drawing ------------------------------------------------------------------

    /**
     * Where the site is: the scheme, host and folder this page was opened at,
     * without /public (when the host sends everything there).
     */
    public function siteUrl(array $server): string
    {
        $https = ($server['HTTPS'] ?? '') !== '' && strtolower((string) $server['HTTPS']) !== 'off'
            || strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || (string) ($server['SERVER_PORT'] ?? '') === '443';
        $host = preg_replace('/[^A-Za-z0-9.:\[\]-]/', '', (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost')) ?: 'localhost';

        $script = str_replace('\\', '/', (string) ($server['SCRIPT_NAME'] ?? '/index.php'));
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/.');
        $uri = (string) parse_url((string) ($server['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
        // Opened as /public/… : the site is in that folder. Sent to public/ by the host: the folder above.
        if ($dir !== '' && ! str_starts_with($uri, $dir.'/') && $uri !== $dir && str_ends_with($dir, '/public')) {
            $dir = substr($dir, 0, -strlen('/public'));
        }

        return ($https ? 'https' : 'http').'://'.$host.$dir;
    }

    public function path(string $relative): string
    {
        return rtrim($this->base, '/').($relative === '.' ? '' : '/'.$relative);
    }

    protected function render(array $vars): string
    {
        $e = fn ($v) => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        extract($vars);
        ob_start();
        require dirname(__DIR__, 2).'/resources/install/page.php';

        return (string) ob_get_clean();
    }
}
