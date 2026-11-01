<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit(1);
$root = dirname(__DIR__);
$secretsFile = $root . '/.runtime/local-db-secrets.json';
$secrets = is_file($secretsFile) ? json_decode(file_get_contents($secretsFile), true, 512, JSON_THROW_ON_ERROR) : null;
$db = new PDO('mysql:host=127.0.0.1;port=3307;charset=utf8mb4', 'root', $secrets['root'] ?? '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if (in_array('--stop', $argv, true)) { $db->exec('SHUTDOWN'); echo "Local database stopped.\n"; exit; }
if (!$secrets) {
    $secrets = ['root' => bin2hex(random_bytes(24)), 'app' => bin2hex(random_bytes(24))];
    file_put_contents($secretsFile, json_encode($secrets, JSON_THROW_ON_ERROR));
    $db->exec("ALTER USER 'root'@'localhost' IDENTIFIED BY " . $db->quote($secrets['root']));
}
$db->exec('CREATE DATABASE IF NOT EXISTS carcare CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->exec('CREATE DATABASE IF NOT EXISTS carcare_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->exec('CREATE DATABASE IF NOT EXISTS carcare_restore_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$db->exec("CREATE USER IF NOT EXISTS 'carcare_local'@'localhost' IDENTIFIED BY " . $db->quote($secrets['app']));
$db->exec("GRANT ALL PRIVILEGES ON carcare.* TO 'carcare_local'@'localhost'");
$db->exec("GRANT ALL PRIVILEGES ON carcare_test.* TO 'carcare_local'@'localhost'");
$db->exec("GRANT ALL PRIVILEGES ON carcare_restore_test.* TO 'carcare_local'@'localhost'");
if (!is_file($root . '/.env.local')) {
    $config = file_get_contents($root . '/.env.example');
    $config = str_replace(['replace-with-a-random-64-character-value', 'DB_USERNAME=carcare', 'DB_PASSWORD=replace-me'], [bin2hex(random_bytes(32)), 'DB_USERNAME=carcare_local', 'DB_PASSWORD=' . $secrets['app']], $config);
    file_put_contents($root . '/.env.local', $config);
}
echo "Local application and test databases ready. Credentials are kept in ignored local files.\n";

