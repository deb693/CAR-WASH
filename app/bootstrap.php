<?php
declare(strict_types=1);
ini_set('display_errors','0');ini_set('log_errors','1');error_reporting(E_ALL);

define('PROJECT_ROOT', dirname(__DIR__));
define('PUBLIC_ROOT', is_dir(PROJECT_ROOT.'/public_html') ? PROJECT_ROOT.'/public_html' : PROJECT_ROOT.'/public');

function config(string $key, mixed $default = null): mixed
{
    static $values;
    if ($values === null) {
        $path = PROJECT_ROOT . '/.env.local';
        $values = is_file($path) ? parse_ini_file($path, false, INI_SCANNER_RAW) : [];
        if ($values === false) throw new RuntimeException('Invalid environment configuration.');
    }
    $environment = getenv($key);
    return $environment !== false ? $environment : ($values[$key] ?? $default);
}

function demo_mode(): bool
{
    return (bool)setting(database(),'demo_mode',true);
}

function database(): PDO
{
    static $pdo;
    if (!$pdo) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            config('DB_HOST', '127.0.0.1'), config('DB_PORT', '3307'), config('DB_DATABASE', 'carcare'));
        $pdo = new PDO($dsn, (string)config('DB_USERNAME', 'carcare'), (string)config('DB_PASSWORD', ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
        // Capacity checks after a day lock must see a competing transaction's commit.
        $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    }
    return $pdo;
}

function rows(PDO $db, string $sql, array $parameters = []): array
{
    $statement = $db->prepare($sql);
    $statement->execute($parameters);
    return $statement->fetchAll();
}

function row(PDO $db, string $sql, array $parameters = []): ?array
{
    return rows($db, $sql, $parameters)[0] ?? null;
}

function execute(PDO $db, string $sql, array $parameters = []): void
{
    $db->prepare($sql)->execute($parameters);
}

final class ApiError extends RuntimeException
{
    public function __construct(public int $status, public string $errorCode, string $message, public array $fields = [])
    { parent::__construct($message); }
}

function fail(int $status, string $code, string $message, array $fields = []): never
{
    throw new ApiError($status, $code, $message, $fields);
}

function text_field(array $data, string $key, int $max = 200, bool $required = true): string
{
    $value = $data[$key] ?? '';
    if (!is_string($value) || strlen($value) > $max || ($required && trim($value) === '')) {
        fail(422, 'validation_error', 'Please check the highlighted details.', [$key => 'Enter a valid value.']);
    }
    return trim($value);
}

function json_encode_safe(mixed $value): string
{
    return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
}

require __DIR__ . '/Catalogue.php';
require __DIR__ . '/Settings.php';
require __DIR__ . '/Auth.php';
require __DIR__ . '/Migrations.php';
require __DIR__ . '/AdminCatalogue.php';
require __DIR__ . '/AdminSettings.php';
require __DIR__ . '/Notifications.php';
require __DIR__ . '/AdminRequests.php';
require __DIR__ . '/AdminRouter.php';
require __DIR__ . '/Website.php';
require __DIR__ . '/Homepage.php';
require __DIR__ . '/Backup.php';
require __DIR__ . '/Setup.php';
require __DIR__ . '/Pricing.php';
require __DIR__ . '/Scheduling.php';
require __DIR__ . '/Requests.php';

