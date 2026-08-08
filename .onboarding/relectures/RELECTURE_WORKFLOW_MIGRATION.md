# Relecture — WORKFLOW_MIGRATION.md

## Verdict global
Acceptable avec réserves — les affirmations précédemment trop absolues sont désormais correctement bornées : le dry-run n'exécute aucun fichier de migration, mais exécute les opérations de connexion/lecture, et la sauvegarde est une copie post-connexion qui peut donc être vide si la base n'existait pas.

## Problèmes bloquants
Aucun. `WORKFLOW_MIGRATION.md:14,26,52` distingue explicitement l'absence d'exécution des fichiers de migration des `PRAGMA`/`SELECT` de `Db::connect()` et `Db::schemaVersion()` (`bin/migrate.php:17-18`, `src/Db.php:17,20,27-32`). `WORKFLOW_MIGRATION.md:14,60` documente aussi le cas où `Db::connect()` crée une base vide avant `copy()` (`bin/migrate.php:56`).

## Problèmes mineurs
- `bin/migrate.php:70-74` affiche encore « la base est inchangée » après un échec ; l'analyse le nuance correctement à `WORKFLOW_MIGRATION.md:67`, mais devrait signaler ce message opérationnel trompeur comme écart observable.
- La convention `NNN_*.sql` est documentée mais non validée par le script (`bin/migrate.php:20-30`) : le tri est lexicographique et les doublons de préfixe peuvent s'écraser dans `$aFaire`. Le risque d'ordre variable est désormais décrit ; il doit rester distinct de la convention documentaire.
- `WORKFLOW_MIGRATION.md:9` contient la coquille « mute » ; employer « modifie ».

## Points vérifiés et corrects
- `bin/migrate.php:20-30,62` : tri lexicographique et conservation de l'ordre d'insertion de `$aFaire`, sans le présenter à tort comme un ordre numérique.
- `bin/migrate.php:24-31` : filtrage strict des préfixes supérieurs à `MAX(version)`.
- `bin/migrate.php:50-59` : copie obligatoire avant les mutations SQL sur une base déjà existante ; échec de `copy()` → sortie 1.
- `bin/migrate.php:63-68,70-75` : SQL et registre dans la même transaction ; rollback de la migration courante et arrêt à la première erreur.
- `composer.json:8-11` et `.github/workflows/ci.yml:17-20` : alias et ordre dry-run puis tests.
- `.github/workflows/deploy.yml:35-36` : déploiement appelle le mode réel après les tests.
- `.gitignore:3` : `data/backups/` est ignoré.

## Recommandations de correction
- Maintenir la formulation bornée du dry-run et du cas de base absente lors des prochaines modifications.
- Conserver les réserves déjà documentées sur l'ordre lexicographique, les préfixes de largeur variable et les doublons de préfixe.
- Maintenir `data/app.db` comme chemin par défaut, avec `SVC_DB_PATH` comme cible effective possible.
