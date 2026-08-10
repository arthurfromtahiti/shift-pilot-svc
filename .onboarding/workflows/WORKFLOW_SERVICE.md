# WORKFLOW_SERVICE — Service HTTP : réception et traitement des requêtes

## Classification
- **Type** : `api_flow`
- **Sous-type** : API JSON lecture seule — routeur minimal PHP
- **Visibilité** : `external_user`
- **Acteur principal** : Client HTTP (navigateur, agent, test automatisé, curl)
- **Acteurs** : Client HTTP ; PHP built-in server ou hôte servi ; `App\Db` ; `App\Orders`
- **Criticité** : Moyenne — seule surface métier réellement servie, mais les données sont fictives (banc d'essai)
- **Confiance** : haute (code lu intégralement) — mécanisme de dépôt de `deployed-version.json` hors dépôt (voir Questions ouvertes)
- **Justification** : `public/index.php` lu intégralement (47 lignes). `src/Orders.php` et `src/Db.php` lus intégralement. Tous les endpoints sont visibles et leur comportement est déterministe. Point clé : `Db::connect()` est appelé avant le `switch` — la connexion base précède le routage pour toute requête, y compris `/health`. Seul point hors dépôt : le mécanisme de dépôt de `deployed-version.json` (signalé en Questions ouvertes).

## Objectif
Exposer en JSON, en lecture seule, les ressources du service pilote (santé, version servie, commandes clients). Permettre à un client HTTP d'interroger l'état du service et de consulter les commandes sans aucune écriture possible via l'API. La mutation des données passe exclusivement par les migrations ; l'API ne propose aucun endpoint d'écriture.

## Acteurs
- **Client HTTP** : déclenche chaque requête (navigateur, curl, agent, test)
- **`public/index.php`** : point d'entrée unique, routeur par `switch`/regex
- **`App\Db`** (`src/Db.php`) : ouvre la connexion PDO SQLite
- **`App\Orders`** (`src/Orders.php`) : exécute les requêtes SQL sur la table `orders`
- **`deployed-version.json`** (racine du projet, absent si déploiement non abouti) : source de vérité de la version servie

## Points d'entrée
- `/health` — sonde de santé
- `/version` — version servie + version de schéma
- `/orders` — liste de toutes les commandes
- `/orders/{id}` — détail d'une commande par identifiant entier
- Tout autre chemin → `404 {"error":"Route inconnue"}`

> **Note** : `$_SERVER['REQUEST_METHOD']` n'est jamais consulté (`public/index.php:8-47`). Ces routes sont documentées pour un usage `GET` mais acceptent toute méthode HTTP sans erreur 405 (voir Risques).

Point d'entrée technique : `public/index.php` (servi par `php -S 127.0.0.1:8080 -t public` en dev ou par un hôte en production)

## Étapes principales

1. **Chargement de l'autoloader** : `require_once dirname(__DIR__) . '/vendor/autoload.php'` (`public/index.php:3`) — charge `App\Db` et `App\Orders`.

2. **Forçage du Content-Type** : `header('Content-Type: application/json; charset=utf-8')` (`public/index.php:8`) — posé avant toute sortie, y compris pour les 404.

3. **Extraction du chemin** : `$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'` (`public/index.php:10`) — normalise le chemin, ignore query string et fragment.

4. **Connexion à la base** : `$pdo = Db::connect()` (`public/index.php:11`) — ouvre PDO SQLite sur `data/app.db` (ou `SVC_DB_PATH` si défini), active `foreign_keys`, mode exception, fetch assoc (`src/Db.php:17-22`).

5. **Lecture de la version servie** : lit `deployed-version.json` à la racine (`public/index.php:16-19`). Si le fichier existe : decode JSON. Si absent : `sha`, `ref` et `deployedAt` valent `null`. Ce fallback est prévu dans le code (`public/index.php:17-19`) — l'absence du fichier est traitée gracieusement. Le mécanisme qui dépose ce fichier sur l'hôte est hors dépôt (voir Questions ouvertes).

6. **Dispatch par `switch ($path)`** (`public/index.php:21-46`) :

   - **`/health`** → `{"status":"ok"}` (la connexion PDO est ouverte avant le `switch` à `public/index.php:11` — un échec de `Db::connect()` peut donc empêcher cet endpoint de répondre)

   - **`/version`** → `$version + ['schemaVersion' => Db::schemaVersion($pdo)]` (`public/index.php:27`) : l'opérateur `+` PHP conserve la clé `schemaVersion` de `$version` si elle existe déjà — **la valeur du fichier prime sur la valeur base live**. `deploy.yml:61` écrit `schemaVersion` dans le fichier ; en contexte de déploiement réel, c'est donc la valeur du fichier qui est retournée. Si le fichier est absent ou ne contient pas `schemaVersion`, `Db::schemaVersion($pdo)` est utilisé : interroge `sqlite_master` pour vérifier l'existence de `schema_migrations`, puis `MAX(version)` — retourne `0` si la table est absente.

   - **`/orders`** → `Orders::all()` : `SELECT id, client, montant_cents, devise, statut FROM orders ORDER BY id` (`src/Orders.php:15`) — retourne un tableau de toutes les commandes, triées par `id`.

   - **`/orders/{id}`** (branche `default` + regex `#^/orders/(\d+)$#`) → `Orders::find(int $id)` : `SELECT … WHERE id = :id` en préparé (`src/Orders.php:20-23`). Si `fetch()` retourne `false` : `http_response_code(404)` + `{"error":"Commande introuvable"}`.

   - **Tout autre chemin** → `http_response_code(404)` + `{"error":"Route inconnue"}`.

## Règles métier

- **Lecture seule côté HTTP** : aucun endpoint `POST`/`PUT`/`PATCH`/`DELETE` n'est défini. Toute mutation des données passe par `bin/migrate.php`. (`public/index.php` — absence vérifiée par lecture complète du `switch`.)

- **Montants en centimes entiers** : la colonne `montant_cents` est `INTEGER NOT NULL` (`migrations/001_init.sql:9`). L'API la renvoie telle quelle, sans conversion.

- **Devise par défaut `XPF`** : `devise TEXT NOT NULL DEFAULT 'XPF'` (`migrations/001_init.sql:10`). La valeur est lue et retournée telle quelle.

- **Commande absente → 404 `{"error":"Commande introuvable"}`** : `Orders::find` retourne `null` si `fetch()` retourne `false` (`src/Orders.php:23`) ; le routeur émet le code 404 (`public/index.php:38-39`).

- **Chemin inconnu → 404 `{"error":"Route inconnue"}`** : branche `default` du `switch` (`public/index.php:45-46`).

- **Version servie jamais déduite du code** : `$versionFile` est toujours lu depuis le fichier (`public/index.php:16-19`). Si absent → `sha`/`ref`/`deployedAt` à `null`. La `schemaVersion` de `/version` est lue en base live **uniquement si** `deployed-version.json` ne contient pas déjà cette clé — l'opérateur `+` PHP conserve la valeur du fichier si elle est présente (`public/index.php:27`). `deploy.yml` écrit `schemaVersion` dans ce fichier (`.github/workflows/deploy.yml:61`) : en contexte de déploiement réel, la valeur du fichier prime et peut diverger de la base si la base est modifiée après l'écriture du fichier.

- **Content-Type forcé en JSON** : valable pour toutes les réponses, y compris les erreurs (`public/index.php:8`).

## Données

- **`orders`** (`data/app.db`) : `id` (INTEGER PK), `client` (TEXT), `montant_cents` (INTEGER), `devise` (TEXT défaut `XPF`), `statut` (TEXT) — 5 lignes fictives en v1 (`migrations/001_init.sql`)
- **`schema_migrations`** (`data/app.db`) : `version` (INTEGER PK), `applied_at` (TEXT) — lu pour `schemaVersion` sur `/version`
- **`deployed-version.json`** (racine projet, non versionné) : `sha`, `ref`, `deployedAt`, éventuellement `environnement`, `schemaVersion` — source de vérité de la version servie. Si `schemaVersion` est présente, elle prime sur la valeur base live pour `/version` (voir Règles métier)

## Intégrations

Aucune intégration externe explicite visible. Le service est autosuffisant : SQLite local, fichier de version local. Pas d'appel réseau sortant.

## Risques

- **`deployed-version.json` absent** : si le fichier n'a pas été déposé par le mécanisme de déploiement, `/version` renvoie `sha: null, ref: null, deployedAt: null`. Le service répond correctement mais le champ de version est silencieusement vide — aucune erreur levée. (`public/index.php:17-19`)

- **`deployed-version.json` contenant un JSON invalide** : si le fichier existe mais que son contenu n'est pas un JSON valide, `json_decode` retourne `null` (`public/index.php:17-18`). L'opérateur `+` appliqué à `null` et à un tableau en `public/index.php:27` (`$version + ['schemaVersion' => ...]`) peut provoquer une erreur fatale en PHP 8 (opérandes incompatibles). Ce chemin n'est pas protégé dans le code.

- **Connexion PDO échoue** : `Db::connect()` est appelé avant le `switch`, à `public/index.php:11`, quel que soit l'endpoint demandé — y compris `/health`. Une `PDOException` (`PDO::ERRMODE_EXCEPTION` dans `src/Db.php:18`) n'est pas attrapée dans `public/index.php` : toute requête, y compris une sonde de santé, produira une réponse HTTP 500 avec stack trace si `display_errors` est actif.

- **Identifiant non entier dans `/orders/{id}`** : le regex `#^/orders/(\d+)$#` n'accepte que des chiffres ; un chemin `/orders/abc` tombe en 404 « Route inconnue ». Comportement sûr mais silencieux (pas de message d'erreur distinctif).

- **Pas de méthode HTTP vérifiée** : `GET /orders/1` et `POST /orders/1` produisent la même réponse (résultat de la requête SELECT). Ni rejet de méthode non supportée ni code 405. Risque nul en pratique (données fictives, pas d'écriture), mais à noter pour extension future.

## Questions ouvertes

- **Mécanisme de dépôt de `deployed-version.json`** : `deploy.yml` écrit sur la branche `deployed` (`deployed/<env>/version.json`) mais ne copie pas ce fichier à la racine du projet servi. Comment l'hôte le récupère-t-il ? (hors dépôt — hébergement, script serveur ?) Cette question est partagée avec le workflow Déploiement.

- **Aucune gestion de la méthode HTTP** : est-ce intentionnel (banc d'essai) ou un oubli à corriger si le service est étendu ?

## Preuves
- `public/index.php` — lu intégralement
- `src/Orders.php` — lu intégralement
- `src/Db.php` — lu intégralement
- `migrations/001_init.sql` — lu intégralement (schéma et données initiales)
- `tests/OrdersTest.php` — lu intégralement (comportements testés : liste 5 commandes, find par id, id inconnu → null, montants entiers, schemaVersion = 0 sans migrate)
