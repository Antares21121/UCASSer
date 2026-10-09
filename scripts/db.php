<?php
// Small PDO helper. Never prints credentials or raw exception messages.
try {
    $cfg = require __DIR__.'/../config/environment.php';
    $db = $cfg['database'];
    $root = ($argv[1] ?? '') === 'create';
    $dsn = 'mysql:host='.$db['host'].';port='.$db['port'].';charset=utf8mb4';
    if (!$root) {
        $dsn .= ';dbname='.$db['database'];
    }
    $pdo = new PDO($dsn, $root ? 'root' : $db['username'], $root ? getenv('DB_ROOT_PASSWORD') : $db['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    if ($root) {
        foreach ([$db['database'], $db['username']] as $identifier) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $identifier)) {
                throw new RuntimeException('Invalid identifier');
            }
        }
        // Only creates a NEW database. Existing database is an error, never reset.
        $name = $db['database'];
        $pdo->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        $user = $pdo->quote($db['username'])."@'localhost'";
        $pdo->exec('CREATE USER IF NOT EXISTS '.$user.' IDENTIFIED BY '.$pdo->quote($db['password']));
        $pdo->exec('GRANT ALL PRIVILEGES ON `'.$name.'`.* TO '.$user);
        echo "New database created\n";
    } elseif (($argv[1] ?? '') === 'empty') {
        $count = $pdo->query('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()')->fetchColumn();
        if ((int) $count !== 0) {
            fwrite(STDERR, "Database is not empty; operation refused\n");
            exit(2);
        }
    } elseif (($argv[1] ?? '') === 'installed') {
        $prefix = $db['prefix'];
        if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) {
            throw new RuntimeException('Invalid prefix');
        }
        $users = $pdo->query('SELECT COUNT(*) FROM `'.$prefix.'users`')->fetchColumn();
        if ((int) $users < 1) {
            throw new RuntimeException('No initialized user');
        }
        echo "Database and initialized users OK\n";
    } else {
        $pdo->query('SELECT 1');
        echo "Database connection OK\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Database check failed (credentials redacted); check host, port, grants and database state\n");
    exit(1);
}
