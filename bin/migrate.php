<?php
// Applique les migrations SQL non encore appliquées.
//
// Usage :
//   php bin/migrate.php --dry-run   → n'écrit RIEN, affiche ce qui serait appliqué
//   php bin/migrate.php             → sauvegarde la base PUIS applique
//
// La sauvegarde n'est pas optionnelle : toute application réelle produit d'abord
// une copie horodatée de la base dans data/backups/. C'est ce fichier qui prouve
// qu'une sauvegarde a eu lieu — pas une affirmation dans un commentaire.

require_once dirname(__DIR__) . '/vendor/autoload.php';

use App\Db;

$dryRun = in_array('--dry-run', $argv, true);
$pdo = Db::connect();
$courante = Db::schemaVersion($pdo);

$fichiers = glob(dirname(__DIR__) . '/migrations/*.sql') ?: [];
sort($fichiers);

$aFaire = [];
foreach ($fichiers as $f) {
    if (!preg_match('/(\d+)_/', basename($f), $m)) {
        continue;
    }
    if ((int) $m[1] > $courante) {
        $aFaire[(int) $m[1]] = $f;
    }
}

echo "version de schéma en base : {$courante}\n";
if ($aFaire === []) {
    echo "aucune migration à appliquer\n";
    exit(0);
}

echo "migrations à appliquer : " . implode(', ', array_map('basename', $aFaire)) . "\n";

if ($dryRun) {
    echo "\n--- ESSAI À BLANC — rien n'est écrit ---\n";
    foreach ($aFaire as $v => $f) {
        echo "\n### " . basename($f) . "\n" . file_get_contents($f) . "\n";
    }
    echo "--- fin de l'essai à blanc ---\n";
    exit(0);
}

// Sauvegarde obligatoire avant toute mutation.
$dossier = dirname(__DIR__) . '/data/backups';
if (!is_dir($dossier)) {
    mkdir($dossier, 0o775, true);
}
$sauvegarde = $dossier . '/app-' . gmdate('Ymd-His') . '-avant-v' . max(array_keys($aFaire)) . '.db';
if (!copy(Db::path(), $sauvegarde)) {
    fwrite(STDERR, "ÉCHEC : sauvegarde impossible, aucune migration appliquée\n");
    exit(1);
}
echo "sauvegarde écrite : " . basename($sauvegarde) . "\n";

foreach ($aFaire as $v => $f) {
    $pdo->beginTransaction();
    try {
        $statements = array_filter(array_map('trim', explode(';', (string) file_get_contents($f))));
        foreach ($statements as $stmt) {
            try {
                $pdo->exec($stmt);
            } catch (\Throwable $e) {
                // ALTER TABLE ADD COLUMN échoue si la colonne existe déjà : on saute uniquement
                // l'ALTER — les instructions suivantes (backfill UPDATE) s'exécutent quand même.
                if (preg_match('/^\s*ALTER\s+TABLE\s+\S+\s+ADD\s+COLUMN/i', $stmt)
                    && str_contains($e->getMessage(), 'duplicate column name')) {
                    continue;
                }
                throw $e;
            }
        }
        $st = $pdo->prepare('INSERT INTO schema_migrations (version, applied_at) VALUES (:v, :t)');
        $st->execute([':v' => $v, ':t' => gmdate('c')]);
        $pdo->commit();
        echo "appliquée : " . basename($f) . "\n";
    } catch (\Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, "ÉCHEC sur " . basename($f) . " : " . $e->getMessage() . "\n");
        fwrite(STDERR, "la base est inchangée ; sauvegarde disponible : " . basename($sauvegarde) . "\n");
        exit(1);
    }
}
echo "version de schéma finale : " . Db::schemaVersion($pdo) . "\n";
