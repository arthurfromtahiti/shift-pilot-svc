# Tests — Audit

> Confiance : high

## Compréhension globale

La suite de tests est constituée d'un unique fichier `tests/OrdersTest.php` (56 lignes, 5 méthodes) exécuté via PHPUnit 10.5. Les tests couvrent exclusivement la classe `Orders` et la méthode `Db::schemaVersion`. L'isolation est exemplaire : chaque test repart d'une base SQLite temporaire reconstruite depuis les migrations réelles, sans jamais toucher `data/app.db`. La CI exécute la suite à chaque push et pull request sur `main` et `staging`. La couverture du routeur HTTP, du migrateur CLI, et des comportements d'erreur est nulle.

## Résumé exécutif

La qualité des tests existants est bonne — isolation correcte, reconstruction depuis les vraies migrations, assertions précises incluant un test non trivial sur `schemaVersion`. Les zones non testées sont significatives pour un service en production : le routeur `public/index.php` n'a aucun test d'intégration, `bin/migrate.php` n'a aucun test unitaire ou fonctionnel (ni sauvegarde, ni rollback, ni dry-run, ni validations de fichiers), et les comportements d'erreur clés (JSON invalide dans `deployed-version.json`, connexion SQLite échouée, méthodes HTTP non supportées) ne sont pas couverts. Pour un banc d'essai, cette couverture partielle est cohérente avec l'objectif. Elle deviendrait insuffisante si le service était étendu.

## Constats détaillés

**VÉRIFIÉ_CODE — Isolation exemplaire via `SVC_DB_PATH`.** `tests/OrdersTest.php:17-22` crée une base temporaire (`tempnam(sys_get_temp_dir(), 'svc') . '.db'`), la déclare via `putenv('SVC_DB_PATH=...')`, et rejoue toutes les migrations via `glob(dirname(__DIR__) . '/migrations/*.sql')`. Chaque test repart d'un état connu sans effet de bord sur `data/app.db`. Cette stratégie signifie aussi que les tests valident implicitement que les migrations sont syntaxiquement exécutables par PDO (contrairement au dry-run CI qui ne les exécute pas).

**VÉRIFIÉ_CODE — Reconstruction depuis les vraies migrations.** Le `setUp` rejoue les fichiers `migrations/*.sql` via `$pdo->exec()` sans intermédiaire. Si une migration était syntaxiquement invalide, le `setUp` échouerait et les tests échoueraient — ce qui est un mécanisme de validation indirect utile, différent du dry-run CI.

**VÉRIFIÉ_CODE — Test `testVersionDeSchemaLueEnBase` : invariant non trivial.** `tests/OrdersTest.php:52-54` vérifie que `Db::schemaVersion()` retourne `0` après un `setUp` qui a exécuté `001_init.sql` directement (sans passer par `bin/migrate.php`). Ce comportement est non trivial : `schema_migrations` est créée par `001_init.sql`, mais aucune ligne n'y est insérée par le SQL initial — c'est `bin/migrate.php` qui insère la ligne de registre. Ce test prouve que la distinction entre « schéma appliqué » et « migration enregistrée » est correctement gérée.

**VÉRIFIÉ_CODE — `tearDown()` absent.** Le fichier temporaire créé dans `setUp` (`$tmp`) n'est jamais supprimé. PHP et PHPUnit ne nettoient pas automatiquement les fichiers `/tmp`. En pratique, le système d'exploitation récupère ces fichiers, mais une suite qui tourne longtemps ou fréquemment pourrait accumuler des fichiers temporaires orphelins. Mineur pour ce projet.

**VÉRIFIÉ_CODE — `public/index.php` non testé.** Le routeur HTTP n'est couvert par aucun test. Les comportements suivants ne sont pas vérifiés : `/health` retourne `{"status":"ok"}`, `/version` fusionne correctement le fichier et la base, `/orders` retourne une liste triée, `/orders/{id}` retourne 404 sur identifiant absent, tout chemin inconnu retourne 404, le `Content-Type` est bien posé. En l'absence de tests d'intégration HTTP, un refactoring du routeur ne détecterait aucune régression.

**VÉRIFIÉ_CODE — `bin/migrate.php` non testé.** Le migrateur CLI n'a aucun test. Les comportements suivants ne sont pas vérifiés : la sauvegarde est créée avant toute écriture, un échec de `copy()` arrête le script avec exit 1, un rollback laisse la base inchangée, le dry-run n'écrit rien en base, les migrations sont appliquées dans l'ordre, une migration déjà enregistrée n'est pas ré-appliquée. Pour le fichier le plus risqué du projet, l'absence de tests est la dette la plus significative.

**VÉRIFIÉ_CODE — Comportements d'erreur non testés.** Aucun test ne couvre : `Db::connect()` échoue (chemin inexistant), `json_decode` retourne `null` sur `deployed-version.json` invalide, `Orders::find()` sur un identifiant null ou négatif, `Orders::all()` sur une table absente.

**VÉRIFIÉ_CODE — Une seule version PHP testée.** `.github/workflows/ci.yml:8-11` utilise PHP 8.1. `composer.json:4` déclare `"php": ">=8.1"`. Aucune matrice de versions (8.2, 8.3) n'est configurée. Pour un banc d'essai ciblant 8.1, c'est cohérent mais laisse non vérifiée la compatibilité avec les versions supérieures.

**VÉRIFIÉ_CODE — PHPUnit 10.5, configuration correcte.** `phpunit.xml` pointe sur `tests/` avec bootstrap `vendor/autoload.php`. Aucune couverture de code configurée (`<coverage>` absent) — la CI ne produit pas de rapport de couverture. Cohérent avec l'objectif de banc d'essai.

## Forces

- Isolation parfaite par base temporaire et `SVC_DB_PATH` : les tests ne dépendent jamais de l'état de `data/app.db` (`tests/OrdersTest.php:17-22`).
- Reconstruction depuis les vraies migrations en `setUp` : cohérence garantie entre le schéma de test et le schéma de production, et validation implicite de l'exécutabilité du SQL (`tests/OrdersTest.php:20-22`).
- Test `testVersionDeSchemaLueEnBase` : vérifie un invariant non trivial crucial pour le bon fonctionnement de `bin/migrate.php` (`tests/OrdersTest.php:52-54`).
- La CI exécute la suite à chaque push et PR, et bloque le déploiement sur suite rouge (`.github/workflows/ci.yml`, `deploy.yml:31-32`).

## Dettes techniques

- **`bin/migrate.php` non couvert** : le fichier le plus risqué du projet n'a aucun test — sauvegarde, rollback, dry-run, ordre des migrations, détection de préfixe invalide (`bin/migrate.php`).
- **`public/index.php` non couvert** : le routeur n'a aucun test d'intégration HTTP — régression silencieuse possible sur tout refactoring (`public/index.php`).
- **Comportements d'erreur non testés** : crash sur JSON invalide, connexion échouée, `schemaVersion` sur base vide (`public/index.php:16-19,27`, `src/Db.php:17`).
- **`tearDown()` absent** : fichiers temporaires orphelins en cas d'exécution longue (`tests/OrdersTest.php`).

## Zones critiques

- **`bin/migrate.php`** : aucun test pour le code le plus risqué du projet — une régression dans la logique de sauvegarde ou de transaction serait invisible jusqu'à l'incident.
- **`public/index.php:16-27`** : le chemin de crash sur JSON invalide n'est pas couvert — la `TypeError` fatale se découvrirait en production.

## Risques

- **Régression non détectée dans `bin/migrate.php`** : toute modification du migrateur (nouveau comportement, refactoring) est validée uniquement par une exécution manuelle ou un déploiement réel. (`bin/migrate.php`, absence de tests)
- **Crash non détecté sur JSON invalide** : la `TypeError` de `public/index.php:27` n'est couverte par aucun test — elle se découvrirait uniquement lors d'un incident de déploiement ou d'une corruption de fichier. (`public/index.php:27`)
- **Refactoring aveugle du routeur** : sans test d'intégration HTTP, un refactoring de `public/index.php` ne serait validé que par exécution manuelle. (`public/index.php`)

## Recommandations priorisées

1. **Ajouter des tests fonctionnels pour `bin/migrate.php`** — couvrir au minimum : sauvegarde créée avant écriture, exit 1 sur échec de copie, rollback sur erreur SQL, dry-run sans écriture, application idempotente — `tests/MigrateTest.php` (nouveau fichier).
2. **Ajouter des tests d'intégration HTTP pour `public/index.php`** — démarrer PHP built-in server ou appeler le routeur directement avec un `$_SERVER` simulé — couvrir `/health`, `/version`, `/orders`, `/orders/{id}`, 404, JSON invalide — `tests/RouterTest.php` (nouveau fichier).
3. **Ajouter `tearDown()`** — supprimer le fichier temporaire créé dans `setUp` — `tests/OrdersTest.php`.
4. **Configurer la couverture de code** dans `phpunit.xml` — rendre visible la couverture réelle même si elle n'est pas bloquante en CI.

## Questions ouvertes

- Des tests d'intégration HTTP sont-ils dans le périmètre prévu pour ce banc d'essai, ou hors scope par conception ?
- La couverture de `bin/migrate.php` est-elle intentionnellement absente (banc d'essai, code simple) ou une dette à combler ?
