<?php

declare(strict_types=1);

/**
 * Migration runner (CLI only).
 *
 *   php app/bin/migrate.php           apply pending migrations
 *   php app/bin/migrate.php --status  list applied/pending migrations
 *
 * Migrations are plain SQL files in app/migrations/, applied in filename
 * order and recorded in schema_migrations. MySQL DDL is not transactional,
 * so files should be written to be safe to re-run manually if one fails
 * midway (prefer CREATE TABLE IF NOT EXISTS).
 */

if (PHP_SAPI !== 'cli') {
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$settings = require dirname(__DIR__) . '/config/settings.php';
$db = new App\Support\Db($settings['db']);

try {
    $pdo = $db->pdo();
} catch (Throwable $e) {
    fwrite(STDERR, "Connexion base de données impossible : {$e->getMessage()}\n");
    exit(1);
}

$pdo->exec(
    'CREATE TABLE IF NOT EXISTS schema_migrations (
        filename   VARCHAR(255) NOT NULL PRIMARY KEY,
        applied_at DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
);

$applied = $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob($settings['paths']['migrations'] . '/*.sql') ?: [];
sort($files);

$pending = array_filter($files, fn (string $f) => !in_array(basename($f), $applied, true));

if (in_array('--status', $argv, true)) {
    foreach ($files as $f) {
        $name = basename($f);
        echo(in_array($name, $applied, true) ? "[appliquée] " : "[en attente] ") . $name . "\n";
    }
    exit(0);
}

if ($pending === []) {
    echo "Aucune migration en attente.\n";
    exit(0);
}

foreach ($pending as $file) {
    $name = basename($file);
    echo "Application de {$name}… ";
    $sql = file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        fwrite(STDERR, "fichier vide ou illisible\n");
        exit(1);
    }
    try {
        $pdo->exec($sql);
        $stmt = $pdo->prepare('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, NOW())');
        $stmt->execute([$name]);
        echo "OK\n";
    } catch (Throwable $e) {
        fwrite(STDERR, "ÉCHEC : {$e->getMessage()}\n");
        exit(1);
    }
}

echo "Terminé.\n";
