# Architecture — Audit

> Confiance : high

## Compréhension globale

shift-pilot-svc est un micro-service PHP 8.1 de moins de 200 lignes de code source hors tests. L'architecture est intentionnellement plate : un routeur d'entrée, une couche de connexion SQLite, un domaine métier unique, un exécuteur de migrations CLI. Il n'y a pas de framework, pas de conteneur d'injection de dépendances, pas de couche intermédiaire. La singularité du projet n'est pas architecturale mais opérationnelle : c'est le seul dépôt pilote avec une base versionnée, un exécuteur de migrations et un canal de déploiement distinguant deux environnements.

## Résumé exécutif

L'architecture est cohérente avec son objectif de banc d'essai. Elle est lisible d'un coup d'œil — trois classes, un routeur, un migrateur CLI — et ne porte aucune dette structurelle grave. Les risques identifiés sont locaux et ciblés : la connexion base ouverte avant tout routage rend la sonde `/health` dépendante de SQLite, et l'absence de gestion d'exception dans le routeur peut produire des réponses HTTP 500 non-JSON en cas d'erreur inattendue. Le chaînon architectural le plus fragile est le lien entre `deploy.yml` (qui publie sur la branche `deployed`) et `public/index.php` (qui lit `deployed-version.json` à la racine) : ce maillon de dépôt n'est pas dans le dépôt et constitue un trou documenté mais non résolu. Enfin, la logique de priorité de `schemaVersion` dans `/version` (valeur du fichier prime sur la base live via l'opérateur `+`) peut induire une désynchronisation silencieuse. Ces quatre points méritent attention avant toute extension du service.

## Constats détaillés

**VÉRIFIÉ_CODE — Architecture plate et lisible.** Le service est organisé en quatre fichiers source : `public/index.php` (47 lignes, routeur), `src/Db.php` (34 lignes, couche PDO/SQLite), `src/Orders.php` (30 lignes, domaine métier), `bin/migrate.php` (77 lignes, migrateur CLI). Aucun framework, aucune couche d'abstraction supplémentaire. Les dépendances Composer sont limitées à `phpunit/phpunit` (dev) et aux extensions PHP natives `pdo`/`pdo_sqlite` (`composer.json:4-5`). Cette platitude est un atout de lisibilité et une réduction de la surface d'attaque liée aux dépendances tierces.

**VÉRIFIÉ_CODE — Connexion base ouverte avant le routage.** `public/index.php:11` appelle `Db::connect()` avant le `switch ($path)`. Toute requête — y compris `GET /health` — échouera avec une `PDOException` non attrapée si SQLite est inaccessible. Or `/health` est précisément conçu pour signaler l'état du service dans les scénarios d'incident. Ce couplage fait que la sonde de santé répond identiquement à un service sain et à un service dont la base est en panne (`public/index.php:11`, `src/Db.php:17-21`).

**VÉRIFIÉ_CODE — Absence de gestion d'exception dans le routeur.** `public/index.php` ne contient aucun `try/catch`. Une `PDOException` levée par `Db::connect()`, `Orders::all()`, ou `Orders::find()` s'échappe sans interception. En production avec `display_errors = Off`, la réponse sera HTTP 500 sans corps — contrairement aux 404 qui sont tous en JSON. Avec `display_errors = On`, la stack trace sera incluse dans le corps avec `Content-Type: application/json` déjà posé.

**VÉRIFIÉ_CODE + HYPOTHÈSE — Chaînon `deployed-version.json` hors dépôt.** `public/index.php:16-19` lit `deployed-version.json` à la racine du projet pour exposer la version servie. `deploy.yml` ne dépose jamais ce fichier dans le workspace servi : il publie sur la branche `deployed` (`deployed/<env>/version.json`) sans étape de copie vers la racine. Le mécanisme qui dépose ce fichier sur l'hôte n'est pas dans le dépôt (`deploy.yml` — absence vérifiée par lecture complète). Si ce mécanisme est absent ou défaillant, `/version` retourne `sha: null, ref: null, deployedAt: null` silencieusement. Hypothèse : un script ou un webhook d'hébergement assure ce dépôt — non prouvé par ce dépôt.

**VÉRIFIÉ_CODE — Priorité de `schemaVersion` : fichier > base live.** `public/index.php:27` fusionne `$version + ['schemaVersion' => Db::schemaVersion($pdo)]`. L'opérateur `+` PHP conserve la clé du tableau gauche si elle existe déjà. `deploy.yml:46` écrit `schemaVersion` dans `deployed-version.json`. En contexte de déploiement réel, c'est la valeur du fichier qui est retournée, pas la base live. Si la base est modifiée après l'écriture du fichier (migration manuelle, restauration), l'API peut retourner une `schemaVersion` obsolète sans erreur.

**VÉRIFIÉ_CODE — Configuration par variable d'environnement.** `SVC_DB_PATH` permet d'overrider le chemin de la base (`src/Db.php:11`). Utilisé par les tests pour pointer sur un fichier temporaire — usage correct et isolé. Aucune autre variable d'environnement n'est lue, ce qui simplifie le déploiement.

## Forces

- Architecture lisible d'un coup d'œil : 3 classes, 1 routeur, 1 migrateur, < 300 lignes total (`src/`, `public/`, `bin/`).
- Séparation des responsabilités claire : routage (`public/index.php`), connexion (`src/Db.php`), domaine (`src/Orders.php`), migrations (`bin/migrate.php`).
- Pas de dépendances tierces en production (uniquement extensions PHP natives), surface d'attaque minimale (`composer.json:4-5`).
- `SVC_DB_PATH` comme unique point de configuration externe : testable sans mock, portable (`src/Db.php:11`).
- Distinction nette API/mutations : zéro écriture via HTTP, toutes les mutations passent par `bin/migrate.php`.

## Dettes techniques

- **Connexion avant routage** : `Db::connect()` est appelé avant le `switch` dans `public/index.php:11`, rendant `/health` dépendant de la disponibilité SQLite — contraire à l'intention d'une sonde de santé.
- **Absence de gestion d'exception dans le routeur** : aucun `try/catch` dans `public/index.php` — toute erreur inattendue produit une réponse HTTP 500 non-JSON.
- **Chaînon `deployed-version.json` hors dépôt** : le mécanisme de dépôt du fichier de version sur l'hôte servi est absent du dépôt, laissant un trou architectural non résolu.

## Zones critiques

- **`public/index.php:11` (connexion avant routage)** : un senior regarderait ici en premier en cas d'incident où `/health` ne répond pas correctement alors que le problème est la base.
- **`public/index.php:16-27` (lecture et fusion de `deployed-version.json`)** : deux comportements fragiles couplés — `json_decode` retournant `null` sur JSON invalide, puis l'opérateur `+` sur un potentiel `null`.
- **`deploy.yml` (absence de copie vers `deployed-version.json`)** : le chaînon manquant le plus impactant sur le fonctionnement de `/version` en production.

## Risques

- **`/health` indisponible en cas de panne base** : si SQLite est inaccessible, la sonde de santé répondra HTTP 500 au lieu de `{"status":"ok"}` — impact : monitoring aveugle pendant un incident base. (`public/index.php:11`)
- **Stack trace exposée en production** : en l'absence de `try/catch`, une exception PHP avec `display_errors = On` sort dans la réponse avec `Content-Type: application/json`. Pour un banc d'essai sans données sensibles, risque nul — à corriger avant exposition publique. (`public/index.php`)
- **`/version` silencieusement vide** : si `deployed-version.json` n'est pas déposé par le mécanisme d'hébergement, `/version` retourne `sha: null, ref: null, deployedAt: null` sans erreur. Pas d'alerte observable. (`public/index.php:16-19`)
- **`schemaVersion` désynchronisée** : un décalage entre le fichier de version et la base réelle ne génère aucune alerte. (`public/index.php:27`)

## Recommandations priorisées

1. **Déplacer `Db::connect()` après le case `/health`** — évite de rendre la sonde dépendante de SQLite — `public/index.php`.
2. **Envelopper le corps du routeur dans un `try/catch`** — garantit une réponse JSON cohérente pour toutes les erreurs — `public/index.php`.
3. **Documenter et protéger la lecture de `deployed-version.json`** — ajouter un `json_decode` avec vérification du résultat et garantir un tableau même si `json_decode` retourne `null` — `public/index.php:17-19`.
4. **Documenter le mécanisme de dépôt de `deployed-version.json`** — identifier et versionner (ou documenter) le script ou webhook qui dépose ce fichier à la racine du projet servi — hors dépôt, question ouverte.

## Questions ouvertes

- Quel mécanisme dépose `deployed-version.json` à la racine du projet servi ? (hébergement, webhook, script post-deploy hors dépôt)
- La priorité du fichier sur la base live pour `schemaVersion` est-elle intentionnelle ? Dans quel scénario la valeur base live serait-elle plus juste ?
- Est-il prévu que `/health` dépende de la disponibilité SQLite, ou est-ce un oubli de conception ?
