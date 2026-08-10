# Relecture — CARTE_DES_DOMAINES.md

## Verdict global
Bon — la carte est exploitable sans réserve bloquante. Les six domaines sont distincts, prouvés par le dépôt et couvrent les surfaces métier, HTTP, persistance, migrations, déploiement et vérification ; aucune catégorie plaquée ou omission fonctionnelle n'a été trouvée.

## Problèmes bloquants
Aucun.

## Problèmes mineurs
- Le contrôle runtime de `data/app.db` n'a pas pu être rejoué dans cet environnement, car les binaires `sqlite3` et `php` ne sont pas disponibles. La carte cite néanmoins précisément la base, le schéma et les tables ; ces affirmations restent cohérentes avec `migrations/001_init.sql`, `src/Db.php` et les requêtes de `src/Orders.php`.
- La carte marque `API HTTP & point d'entrée` et `Intégration continue & tests` comme ne dépendant pas de la base. C'est acceptable au sens fonctionnel (ces domaines ne portent pas de données persistées propres), même si les deux invoquent indirectement la couche SQLite ; la nuance est déjà documentée pour l'API.

## Points vérifiés et corrects
- `Commandes` est réel : `src/Orders.php` lit `orders`, et `public/index.php` route `/orders` et `/orders/{id}` ; le schéma et les tests sont dans `migrations/001_init.sql` et `tests/OrdersTest.php`.
- `API HTTP & point d'entrée` est réel : `public/index.php` contient le `switch`, la réponse JSON, `/health`, `/version` et la gestion des erreurs 404.
- `Persistance SQLite` est prouvée par `src/Db.php` (`PDO sqlite`, `SVC_DB_PATH`, clé étrangères et lecture de `schema_migrations`) et par `data/app.db` référencé comme base versionnée.
- `Migrations de schéma` est correctement isolé : `bin/migrate.php` distingue `--dry-run`, copie la base avant mutation, refuse en cas d'échec de copie et transactionne chaque migration ; `composer.json` expose les deux scripts.
- `Déploiement & version servie` est prouvé par `.github/workflows/deploy.yml`, `README.md`, `public/index.php` et les deux fichiers `origin/deployed:staging/version.json` et `origin/deployed:production/version.json`. Le workflow cible bien `staging`/`main` et publie sur `deployed`.
- `Intégration continue & tests` est prouvée par `.github/workflows/ci.yml`, `composer.json`, `phpunit.xml` et `tests/OrdersTest.php`.
- La granularité est juste : six domaines, dans la fourchette attendue 4–12 ; les préoccupations techniques transverses ne sont pas fondues dans le métier, et aucun domaine d'authentification, notification, intégration externe ou builder n'est inventé. `README.md` confirme l'absence de service externe et de données personnelles.
- La carte signale honnêtement le chaînon non localisé entre les artefacts de la branche `deployed` et `deployed-version.json` : `public/index.php` lit ce dernier, tandis que `.github/workflows/deploy.yml` écrit `deployed/<env>/version.json` sans le produire directement.

## Recommandations de correction
Aucune correction requise. Conserver la question ouverte sur le mécanisme hors dépôt qui alimente `deployed-version.json` pour la relecture des workflows et l'audit d'architecture.
