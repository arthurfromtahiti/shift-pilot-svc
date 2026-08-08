<?php

namespace App;

use PDO;

/** Connexion SQLite. Le fichier de base est versionné : les migrations sont donc réelles. */
final class Db
{
    public static function path(): string
    {
        return getenv('SVC_DB_PATH') ?: dirname(__DIR__) . '/data/app.db';
    }

    public static function connect(): PDO
    {
        $pdo = new PDO('sqlite:' . self::path());
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');
        return $pdo;
    }

    /** Version de schéma réellement appliquée, lue dans la base — jamais supposée. */
    public static function schemaVersion(PDO $pdo): int
    {
        $t = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name='schema_migrations'")->fetch();
        if (!$t) {
            return 0;
        }
        $row = $pdo->query('SELECT COALESCE(MAX(version), 0) AS v FROM schema_migrations')->fetch();
        return (int) $row['v'];
    }
}
