# WORKFLOW_MIGRATION — Application des migrations de schéma SQLite

## Classification
- **Type** : `technical_flow`
- **Sous-type** : CLI — exécuteur de migrations SQL avec essai à blanc et sauvegarde obligatoire
- **Visibilité** : `technical`
- **Acteur principal** : Développeur, agent CI, ou `deploy.yml` (GitHub Actions)
- **Acteurs** : Opérateur (développeur / CI) ; `bin/migrate.php` ; `App\Db` ; `data/app.db` ; `data/backups/` ; `migrations/*.sql`
- **Criticité** : Haute — modifie `data/app.db`, fichier versionné dans le dépôt comme état de départ des migrations ; en usage local, une corruption du fichier versionné peut être commitée dans le repo ; en CI, `deploy.yml` n'écrit pas la base modifiée dans le dépôt (voir Risques)
- **Confiance** : high
- **Justification** : `bin/migrate.php` lu intégralement (77 lignes). `src/Db.php` lu intégralement. `migrations/001_init.sql` lu. `composer.json` (scripts) lu. README (section Migrations) lu. Comportement déterministe, aucun branchement non visible.

## Objectif
Permettre à un opérateur (développeur ou CI) d'appliquer les fichiers SQL de migration non encore enregistrés en base, en garantissant que **la base est sauvegardée avant l'exécution de tout fichier de migration** et que **chaque migration est atomique**. Cette garantie porte sur la copie effectuée à l'étape 10, après que `Db::connect()` (étape 3) a déjà ouvert — ou créé à vide — le fichier SQLite ; si la base était absente avant le lancement, la sauvegarde sera la copie d'un fichier vide, non d'une base réelle. Le mode essai à blanc affiche le SQL qui serait appliqué **sans exécuter aucun fichier de migration**, y compris en CI sur chaque PR — `Db::connect()` et `Db::schemaVersion()` sont néanmoins exécutés avant la sortie anticipée (ils exécutent `PRAGMA foreign_keys = ON` et des `SELECT` en lecture, `src/Db.php:20,27-31`) et peuvent créer le fichier SQLite s'il n'existait pas encore (voir Étape 8 du mode essai à blanc).

## Acteurs
- **Opérateur** : développeur en local, ou étape `Appliquer les migrations` du `deploy.yml` (GitHub Actions)
- **`bin/migrate.php`** : point d'entrée CLI, orchestre les deux modes
- **`App\Db`** (`src/Db.php`) : connexion PDO, chemin de base, lecture de `schemaVersion`
- **`data/app.db`** : base SQLite versionnée — chemin par défaut des mutations réelles (surchargeable via la variable d'environnement `SVC_DB_PATH`)
- **`data/backups/`** : dossier des sauvegardes horodatées (gitignoré — local ou CI uniquement)
- **`migrations/*.sql`** : fichiers de migration nommés `NNN_*.sql`

## Points d'entrée

- `php bin/migrate.php --dry-run` — essai à blanc : aucun fichier de migration exécuté ; `Db::connect()` et `Db::schemaVersion()` exécutent néanmoins `PRAGMA foreign_keys = ON` et des `SELECT` en lecture (`src/Db.php:20,27-31`) avant la sortie anticipée (peuvent créer le fichier de base si le chemin n'existait pas)
- `php bin/migrate.php` — application réelle : sauvegarde + application dans des transactions
- `composer migrate:dry` — alias Composer de `--dry-run` (`composer.json`, `scripts.migrate:dry`)
- `composer migrate` — alias Composer de l'application réelle (`composer.json`, `scripts.migrate`)
- Appelé par `deploy.yml` à l'étape `Appliquer les migrations` : `run: php bin/migrate.php` (sans `--dry-run`)

## Étapes principales

### Mode commun (les deux modes)

1. **Chargement de l'autoloader** : `require_once dirname(__DIR__) . '/vendor/autoload.php'` (`bin/migrate.php:12`).

2. **Détection du mode** : `$dryRun = in_array('--dry-run', $argv, true)` (`bin/migrate.php:16`) — booléen, vrai si `--dry-run` est présent en argument CLI.

3. **Connexion à la base** : `$pdo = Db::connect()` — SQLite sur `data/app.db` ou `SVC_DB_PATH` si défini (`src/Db.php:11-13`).

4. **Lecture de la version courante** : `$courante = Db::schemaVersion($pdo)` — vérifie l'existence de `schema_migrations` dans `sqlite_master`, puis lit `MAX(version)` ; retourne `0` si la table est absente (`src/Db.php:26-33`).

5. **Découverte des fichiers de migration** : `glob(dirname(__DIR__) . '/migrations/*.sql')` + `sort()` (`bin/migrate.php:20-21`) — tri lexicographique ; l'équivalence avec un ordre numérique n'est garantie que si les préfixes ont une largeur fixe (`NNN_`), convention non vérifiée par le script.

6. **Filtrage** : pour chaque fichier, extraction du préfixe numérique via `/(\d+)_/` ; seuls les fichiers dont le préfixe entier est **strictement supérieur** à `$courante` sont conservés dans `$aFaire` (`bin/migrate.php:24-31`).

7. **Affichage** : version courante en base + liste des fichiers à appliquer (ou « aucune migration à appliquer » + `exit(0)`) (`bin/migrate.php:33-37`).

### Mode essai à blanc (`--dry-run`)

8. **Sortie anticipée** : affiche le contenu SQL de chaque fichier à appliquer puis `exit(0)` (`bin/migrate.php:41-48`). **Aucun fichier de migration n'est exécuté** — `Db::connect()` et `Db::schemaVersion()` ont cependant déjà exécuté `PRAGMA foreign_keys = ON` et des `SELECT` (`src/Db.php:20,27-31`) avant d'atteindre cette sortie anticipée ; si le chemin de la base (`data/app.db` ou `SVC_DB_PATH`) n'existait pas, PDO l'a créé à vide lors de la connexion (`src/Db.php:17`). La garantie porte sur l'absence d'exécution des fichiers de migration, non sur l'absence absolue d'effet disque ou de requêtes de connexion/lecture.

### Mode application réelle (sans `--dry-run`)

8. **Création du dossier de sauvegardes** : `mkdir($dossier, 0o775, true)` si `data/backups/` n'existe pas (`bin/migrate.php:51-53`).

9. **Calcul du nom de sauvegarde** : `app-<YYYYMMDD-HHmmss UTC>-avant-v<N>.db` où `<N>` est `max(array_keys($aFaire))` — la version **cible** la plus haute à appliquer (`bin/migrate.php:55`).

10. **Sauvegarde obligatoire** : `copy(Db::path(), $sauvegarde)` (`bin/migrate.php:56`). Si la copie échoue : message sur `STDERR` + `exit(1)`. **Aucune migration n'est appliquée sans sauvegarde réussie.** Cette garantie s'applique lorsque la base existait déjà avant l'exécution. Si la base n'existait pas, `Db::connect()` (étape 3) a créé le fichier SQLite à vide (`src/Db.php:17`) — la sauvegarde sera alors la copie d'un fichier vide, non d'une base réelle.

11. **Application transactionnelle** : pour chaque fichier de `$aFaire` dans l'ordre d'insertion — lequel est l'ordre lexicographique des chemins issu du `sort()` de l'étape 5 (`bin/migrate.php:62-76`) :
    - `$pdo->beginTransaction()`
    - `$pdo->exec(file_get_contents($f))` — exécute le SQL
    - `INSERT INTO schema_migrations (version, applied_at) VALUES (:v, :t)` avec horodatage UTC (`gmdate('c')`)
    - `$pdo->commit()`
    - En cas de `Throwable` : `$pdo->rollBack()` + messages sur `STDERR` + `exit(1)` → **la migration courante est rollbackée ; les migrations déjà commitées lors des itérations précédentes restent persistées**. 
    - ⚠️ **Mise en garde sur le message d'erreur** : Le message STDERR affiché (`bin/migrate.php:73`) dit « la base est inchangée ; sauvegarde disponible ». Ce message est **trompeur lorsque plusieurs migrations sont en attente** : si les migrations 2 et 3 sont à appliquer et que la migration 2 échoue, la migration 1 s'est bien appliquée pendant les itérations précédentes — elle reste persistée. Le message devrait lire « _la migration courante est annulée ; les migrations précédentes de cette exécution restent appliquées_ ». Cet énoncé correctif s'ajoute à la documentation par souci de clarté ; le code du script demeure tel quel.

12. **Confirmation** : affiche `"version de schéma finale : N"` via `Db::schemaVersion($pdo)` relu en base (`bin/migrate.php:77`).

## Règles métier

- **La sauvegarde n'est pas optionnelle** : échec de `copy()` → `exit(1)` immédiat, aucune migration appliquée (`bin/migrate.php:56-59`).

- **Seules les migrations non encore enregistrées sont appliquées** : condition `(int) $m[1] > $courante` — le préfixe numérique doit être strictement supérieur à `MAX(version)` dans `schema_migrations` (`bin/migrate.php:27`).

- **Chaque migration est atomique dans sa propre transaction** : `beginTransaction` / `commit` / `rollBack` — en cas d'échec, seule la migration courante est rollbackée ; les migrations déjà commitées lors des itérations précédentes restent persistées. Le script quitte à la première erreur (`exit(1)`) (`bin/migrate.php:63-75`).

- **Le registre `schema_migrations` est mis à jour dans la même transaction que le SQL** : l'INSERT est dans le même `beginTransaction`/`commit` que l'`exec` du SQL (`bin/migrate.php:66-68`).

- **Le nom de sauvegarde encode la version cible maximale**, pas la version de départ : `avant-v<max(array_keys($aFaire))>` (`bin/migrate.php:55`).

- **`--dry-run` en CI (ci.yml)** : l'essai à blanc est exécuté à chaque `push` sur `main`/`staging` et chaque PR, avant les tests PHPUnit (`.github/workflows/ci.yml:17-18`). Il affiche le contenu des fichiers de migration sans les exécuter — aucune validation de syntaxe SQL n'est effectuée.

## Données

- **`data/app.db`** : base SQLite versionnée, chemin par défaut des mutations (surchargeable via `SVC_DB_PATH`). Tables : `orders` (données métier), `schema_migrations` (registre des versions appliquées).
- **`migrations/*.sql`** : source de vérité du schéma. Un seul fichier actuellement : `001_init.sql` (crée `schema_migrations`, `orders`, insère 5 lignes fictives).
- **`data/backups/app-<horodatage>-avant-v<N>.db`** : copie horodatée créée avant chaque application réelle. **Gitignoré** (`.gitignore`) — fichiers locaux ou CI uniquement, non versionnés.
- **`SVC_DB_PATH`** (variable d'environnement) : override du chemin de base — utilisé par les tests PHPUnit pour pointer sur un fichier temporaire (`src/Db.php:11`).

## Intégrations

- **`deploy.yml`** appelle `php bin/migrate.php` (sans `--dry-run`) à l'étape `Appliquer les migrations`, après les tests et avant la publication de `version.json` (`.github/workflows/deploy.yml:35-36`). La `schemaVersion` lue juste après reflète l'état post-migration.
- **`ci.yml`** appelle `php bin/migrate.php --dry-run` avant les tests PHPUnit (`.github/workflows/ci.yml:17-18`).
- Aucune autre intégration externe.

## Risques

- **Sauvegardes dans `data/backups/` gitignoré** : les sauvegardes sont locales à la machine ou à l'exécuteur CI (éphémère). Après un run CI, elles disparaissent. En cas de corruption de `data/app.db` découverte après le run, la sauvegarde n'est plus disponible. (`.gitignore` confirmé — `data/backups/` absent du repo.)

- **`data/app.db` modifié par CI mais non commité** : `deploy.yml` applique les migrations contre `data/app.db` dans le workspace de l'exécuteur, mais ne commite pas la base modifiée sur `main`/`staging`. Si un développeur ajoute un fichier `002_*.sql` sans mettre à jour manuellement `data/app.db`, la base versionnée sera en retard par rapport au schéma appliqué lors du déploiement. Le prochain checkout aura une `data/app.db` à l'ancien schéma.

- **Nom de sauvegarde = version cible, pas version finale réelle** : si `$aFaire` contient les versions 2 et 3 et que la migration 2 échoue, le fichier de sauvegarde s'appelle `avant-v3.db` bien que la base ait seulement reçu la migration 1 puis partiel de la migration 2 (rollbackée). Le nom peut être trompeur lors d'une restauration — il indique la version cible **prévue**, non celle réellement appliquée avant l'erreur.

- **`--dry-run` ne valide pas la syntaxe SQL** : `file_get_contents($f)` est affiché, pas exécuté. Une erreur SQL dans un fichier de migration passerait le dry-run de CI et échouerait seulement à l'application réelle (en déploiement).

- **Aucune vérification d'intégrité de la sauvegarde** : `copy()` peut réussir sur un fichier SQLite corrompu. Aucune vérification post-copie (ex. `PRAGMA integrity_check`).

## Questions ouvertes

- **Mise à jour de `data/app.db` après migration en CI** : le workflow de déploiement modifie la base en CI mais ne commit pas le résultat. Est-ce intentionnel (la base versionnée = état avant migrations, les migrations s'appliquent à chaque déploiement) ou un oubli ? Dans l'état actuel, `data/app.db` est déjà à `v1` et `001_init.sql` est la seule migration : il n'y a pas de décalage visible. Cela deviendrait problématique à la deuxième migration.

- **Pérennité des sauvegardes** : si `data/backups/` est gitignoré et que CI est éphémère, qui est responsable de conserver une sauvegarde avant un déploiement qui toucherait `data/app.db` réellement servi ?

## Preuves
- `bin/migrate.php` — lu intégralement
- `src/Db.php` — lu intégralement
- `migrations/001_init.sql` — lu intégralement
- `composer.json` — lu (scripts `migrate`, `migrate:dry`)
- `.github/workflows/ci.yml` — lu (step `Essai à blanc des migrations`)
- `.github/workflows/deploy.yml` — lu (step `Appliquer les migrations`)
- `README.md` — lu (section « Migrations »)
