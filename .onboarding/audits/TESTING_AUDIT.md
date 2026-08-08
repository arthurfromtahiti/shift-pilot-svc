# Tests — Audit

> Confiance : medium — les constats sont fondés sur la lecture statique du code de test et de la configuration CI ; les résultats d'exécution PHPUnit et le statut réel de la CI ne sont pas vérifiables sans run identifié.

## Compréhension globale

La suite de tests est constituée d'un unique fichier `tests/OrdersTest.php` (56 lignes, 5 méthodes) exécuté via PHPUnit 10.5. Les tests couvrent exclusivement la classe `Orders` et la méthode `Db::schemaVersion` — mais uniquement dans le scénario sans `bin/migrate.php`. La CI est configurée pour s'exécuter à chaque push sur `main`/`staging` et pull request ; les résultats d'exécution réels ne sont pas visibles dans ce checkout. Aucune couverture de tests visible pour le routeur HTTP, le migrateur CLI, ou les comportements d'erreur.

## Résumé exécutif

La qualité des tests existants est bonne — isolation par base temporaire, reconstruction depuis les vraies migrations, assertions précises incluant un test non trivial sur `schemaVersion`. Nuance importante : `SVC_DB_PATH` reste défini dans l'environnement du processus entre les tests (aucun `tearDown()` ne le réinitialise ni ne supprime le fichier temporaire), ce qui crée un résidu d'environnement entre tests. Les zones non testées sont significatives pour un service en production : le routeur `public/index.php` n'a aucun test d'intégration, `bin/migrate.php` n'a aucun test (ni sauvegarde, ni rollback, ni dry-run, ni collision de préfixes), et les comportements d'erreur clés ne sont pas couverts. Pour un banc d'essai, cette couverture partielle est cohérente avec l'objectif. Elle deviendrait insuffisante si le service était étendu.

## Constats détaillés

**VÉRIFIÉ_CODE — Isolation par base temporaire.** `tests/OrdersTest.php:17-22` crée une base temporaire (`tempnam(sys_get_temp_dir(), 'svc') . '.db'`), la déclare via `putenv('SVC_DB_PATH=...')`, et rejoue toutes les migrations via `glob(dirname(__DIR__) . '/migrations/*.sql')`. Chaque `setUp` repart d'un état connu sans effet de bord sur `data/app.db`. Cette stratégie signifie aussi que les tests valident implicitement que les migrations sont syntaxiquement exécutables par PDO (contrairement au dry-run CI qui ne les exécute pas).

**VÉRIFIÉ_CODE — Résidu d'environnement entre tests.** `setUp` appelle `putenv('SVC_DB_PATH=...')` avec un nouveau chemin temporaire, mais aucun `tearDown()` n'efface cette variable ni ne supprime le fichier. Entre deux tests, `SVC_DB_PATH` reste défini à la valeur du `setUp` précédent jusqu'à ce que le prochain `setUp` la remplace. Le fichier temporaire créé dans `setUp` n'est pas supprimé — résidu filesystem lors d'exécutions fréquentes. En pratique, PHPUnit crée une nouvelle instance de la classe de test par méthode, donc `setUp` est appelé avant chaque test ; mais l'absence de `tearDown()` laisse des fichiers orphelins.

**VÉRIFIÉ_CODE — Reconstruction depuis les vraies migrations.** Le `setUp` rejoue les fichiers `migrations/*.sql` via `$pdo->exec()` sans intermédiaire. Si une migration était syntaxiquement invalide, le `setUp` échouerait et les tests échoueraient — mécanisme de validation indirect utile, différent du dry-run CI.

**VÉRIFIÉ_CODE — Test `testVersionDeSchemaLueEnBase` : portée précise.** `tests/OrdersTest.php:52-54` vérifie que `Db::schemaVersion()` retourne `0` après un `setUp` qui a exécuté directement le SQL de `001_init.sql` via `$pdo->exec()`. Ce comportement est non trivial : `schema_migrations` est créée par `001_init.sql`, mais aucune ligne n'y est insérée par le SQL initial — c'est `bin/migrate.php` qui insère la ligne de registre. Ce test vérifie uniquement ce cas précis (schéma appliqué, pas via bin/migrate.php → schemaVersion = 0). Il ne couvre pas le comportement de `Db::schemaVersion()` après une migration réelle via `bin/migrate.php`.

**VÉRIFIÉ_CODE — CI : déclencheur configuré, résultat non vérifiable.** `.github/workflows/ci.yml:3-6` configure les déclencheurs `push [main, staging]` et `pull_request`. `.github/workflows/ci.yml:17-20` ordonne le dry-run puis `composer test`. Ce sont des faits de configuration (VÉRIFIÉ_CODE). Que la suite soit effectivement verte sur le dernier run, que le déploiement bloque sur suite rouge, sont des faits d'exécution (`INCONNU` sans run ID identifié dans ce checkout).

**VÉRIFIÉ_CODE — `public/index.php` : aucune couverture de tests visible.** Le routeur HTTP n'est couvert par aucun test. Les comportements suivants ne sont pas vérifiés : `/health` retourne `{"status":"ok"}`, `/version` fusionne correctement le fichier et la base, `/orders` retourne une liste triée, `/orders/{id}` retourne 404 sur identifiant absent, tout chemin inconnu retourne 404, le `Content-Type` est bien posé. En l'absence de tests d'intégration HTTP, un refactoring du routeur ne détecterait aucune régression.

**VÉRIFIÉ_CODE — `bin/migrate.php` : aucune couverture de tests visible.** Le migrateur CLI n'a aucun test. Les comportements suivants ne sont pas vérifiés : la sauvegarde est créée avant toute écriture, un échec de `copy()` arrête le script avec exit 1, un rollback laisse la base inchangée, le dry-run n'écrit rien en base, les migrations sont appliquées dans l'ordre, une migration déjà enregistrée n'est pas ré-appliquée, deux fichiers avec le même préfixe numérique déclenchent une erreur. Pour le fichier le plus risqué du projet, l'absence de tests est la dette la plus significative.

**VÉRIFIÉ_CODE — Comportements d'erreur : aucune couverture de tests visible.** Aucun test ne couvre : `Db::connect()` échoue (chemin inexistant), `json_decode` retourne `null` sur `deployed-version.json` invalide, `Orders::find()` sur un identifiant null ou négatif, `Orders::all()` sur une table absente.

**VÉRIFIÉ_CODE — Une seule version PHP testée.** `.github/workflows/ci.yml:8-11` utilise PHP 8.1. `composer.json:4` déclare `"php": ">=8.1"`. Aucune matrice de versions (8.2, 8.3) n'est configurée. Pour un banc d'essai ciblant 8.1, c'est cohérent mais laisse non vérifiée la compatibilité avec les versions supérieures.

**VÉRIFIÉ_CODE — PHPUnit 10.5, configuration correcte.** `phpunit.xml` pointe sur `tests/` avec bootstrap `vendor/autoload.php`. Aucune couverture de code configurée (`<coverage>` absent) — la CI ne produit pas de rapport de couverture. Cohérent avec l'objectif de banc d'essai.

## Forces

- Isolation par base temporaire et `SVC_DB_PATH` : les tests ne dépendent jamais de l'état de `data/app.db` (`tests/OrdersTest.php:17-22`).
- Reconstruction depuis les vraies migrations en `setUp` : cohérence garantie entre le schéma de test et le schéma de production, et validation implicite de l'exécutabilité du SQL (`tests/OrdersTest.php:20-22`).
- Test `testVersionDeSchemaLueEnBase` : vérifie un invariant non trivial (schéma appliqué hors `bin/migrate.php` → schemaVersion = 0) (`tests/OrdersTest.php:52-54`).
- La CI est configurée pour exécuter la suite à chaque push sur `main`/`staging` et PR, et pour bloquer le déploiement si les tests échouent (configuration vérifiée, exécution non vérifiable sans run) (`.github/workflows/ci.yml`, `deploy.yml:31-32`).

## Dettes techniques

- **`bin/migrate.php` : aucune couverture de tests visible** : le fichier le plus risqué du projet n'a aucun test — sauvegarde, rollback, dry-run, ordre des migrations, détection de préfixe invalide ou dupliqué (`bin/migrate.php`).
- **`public/index.php` : aucune couverture de tests visible** : le routeur n'a aucun test d'intégration HTTP — régression silencieuse possible sur tout refactoring (`public/index.php`).
- **Comportements d'erreur : aucune couverture visible** : crash sur JSON invalide, connexion échouée, `schemaVersion` sur base vide (`public/index.php:16-19,27`, `src/Db.php:17`).
- **`tearDown()` absent** : fichiers temporaires et variable d'environnement résiduels entre tests (`tests/OrdersTest.php`).

## Zones critiques

- **`bin/migrate.php`** : aucune couverture de tests visible pour le code le plus risqué du projet — une régression dans la logique de sauvegarde ou de transaction serait invisible jusqu'à l'incident.
- **`public/index.php:16-27`** : le chemin de code menant à TypeError sur JSON invalide n'est pas couvert — se découvrirait en production.

## Risques

- **Régression non détectée dans `bin/migrate.php`** : toute modification du migrateur est validée uniquement par une exécution manuelle ou un déploiement réel. (`bin/migrate.php`, aucune couverture de tests visible)
- **TypeError non détectée sur JSON invalide** : le chemin de code `public/index.php:17-27` n'est couvert par aucun test — se découvrirait uniquement lors d'un incident de déploiement ou d'une corruption de fichier. (`public/index.php:27`)
- **Refactoring aveugle du routeur** : sans test d'intégration HTTP, un refactoring de `public/index.php` ne serait validé que par exécution manuelle. (`public/index.php`)
- **Collision de préfixes non testée** : le défaut de `bin/migrate.php:24-30` (doublon de numéro fait disparaître un fichier silencieusement) n'est couvert par aucun test.

## Recommandations priorisées

1. **Ajouter des tests fonctionnels pour `bin/migrate.php`** — couvrir au minimum : sauvegarde créée avant écriture, exit 1 sur échec de copie, rollback sur erreur SQL, dry-run sans écriture, application idempotente, rejet de doublons de préfixe — `tests/MigrateTest.php` (nouveau fichier).
2. **Ajouter des tests d'intégration HTTP pour `public/index.php`** — démarrer PHP built-in server ou appeler le routeur directement avec un `$_SERVER` simulé — couvrir `/health`, `/version`, `/orders`, `/orders/{id}`, 404, JSON invalide dans `deployed-version.json` — `tests/RouterTest.php` (nouveau fichier).
3. **Ajouter `tearDown()`** — supprimer le fichier temporaire créé dans `setUp` et réinitialiser `SVC_DB_PATH` — `tests/OrdersTest.php`.
4. **Configurer la couverture de code** dans `phpunit.xml` — rendre visible la couverture réelle même si elle n'est pas bloquante en CI.

## Questions ouvertes

- Des tests d'intégration HTTP sont-ils dans le périmètre prévu pour ce banc d'essai, ou hors scope par conception ?
- La couverture de `bin/migrate.php` est-elle intentionnellement absente (banc d'essai, code simple) ou une dette à combler ?
