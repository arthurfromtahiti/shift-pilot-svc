# Architecture — Audit

> Confiance : medium — les constats statiques sur le code source sont vérifiés ; les effets runtime (comportement HTTP, stack trace) et le mécanisme d'hébergement (`deployed-version.json`) restent des hypothèses ou des inconnues faute d'environnement servi accessible.

## Compréhension globale

shift-pilot-svc est un micro-service PHP 8.1 de moins de 200 lignes de code source hors tests. L'architecture est intentionnellement plate : un routeur d'entrée, une couche de connexion SQLite, un domaine métier unique, un exécuteur de migrations CLI. Il n'y a pas de framework, pas de conteneur d'injection de dépendances, pas de couche intermédiaire. La singularité du projet n'est pas architecturale mais opérationnelle : c'est le seul dépôt pilote avec une base versionnée, un exécuteur de migrations et un canal de déploiement distinguant deux environnements.

## Résumé exécutif

L'architecture est cohérente avec son objectif de banc d'essai. Elle est lisible d'un coup d'œil — trois classes, un routeur, un migrateur CLI — et ne porte aucune dette structurelle grave. Les risques identifiés sont locaux et ciblés : la connexion base ouverte avant tout routage rend la sonde `/health` dépendante de SQLite (déduit du code), et l'absence de gestion d'exception dans le routeur peut produire des comportements d'erreur non-JSON (comportement exact conditionnel à la configuration PHP, non vérifiable dans ce dépôt). Le chaînon architectural le plus fragile est le lien entre `deploy.yml` (qui publie sur la branche `deployed`) et `public/index.php` (qui lit `deployed-version.json` à la racine) : si aucun mécanisme hors dépôt ne dépose ce fichier, `/version` retourne des champs nuls — la vérification ne peut avoir lieu que sur l'hôte servi. Enfin, la logique de priorité de `schemaVersion` dans `/version` (valeur du fichier prime sur la base live via l'opérateur `+`) peut induire une désynchronisation silencieuse. Ces points méritent attention avant toute extension du service.

## Constats détaillés

**VÉRIFIÉ_CODE — Architecture plate et lisible.** Le service est organisé en quatre fichiers source : `public/index.php` (47 lignes, routeur), `src/Db.php` (34 lignes, couche PDO/SQLite), `src/Orders.php` (30 lignes, domaine métier), `bin/migrate.php` (77 lignes, migrateur CLI). Aucun framework, aucune couche d'abstraction supplémentaire. Les dépendances Composer sont limitées à `phpunit/phpunit` (dev) et aux extensions PHP natives `pdo`/`pdo_sqlite` (`composer.json:4-5`). Cette platitude est un atout de lisibilité et réduit la surface d'attaque liée aux dépendances déclarées — sans exclure d'éventuelles vulnérabilités transitives non vérifiées ici.

**VÉRIFIÉ_CODE — Connexion base ouverte avant le routage.** `public/index.php:11` appelle `Db::connect()` avant le `switch ($path)`. Toute requête — y compris `GET /health` — lève une `PDOException` non attrapée si SQLite est inaccessible. Or `/health` est précisément conçu pour signaler l'état du service dans les scénarios d'incident. Ce couplage signifie que la sonde de santé dépend de la disponibilité SQLite (`public/index.php:11`, `src/Db.php:17-21`).

**VÉRIFIÉ_CODE + HYPOTHÈSE — Absence de gestion d'exception dans le routeur.** `public/index.php` ne contient aucun `try/catch` (lecture complète, lignes 1-47). Une `PDOException` levée par `Db::connect()`, `Orders::all()`, ou `Orders::find()` s'échappe sans interception. Hypothèse : en production avec `display_errors = Off`, la réponse serait HTTP 500 sans corps — la valeur de cette directive sur l'hôte n'est pas dans le dépôt (`INCONNU`). Hypothèse : avec `display_errors = On`, la stack trace apparaîtrait dans la réponse avec `Content-Type: application/json` déjà posé (`public/index.php:8`). Ces effets ne peuvent être confirmés sans exécution PHP réelle.

**VÉRIFIÉ_CODE + INCONNU — Chaînon `deployed-version.json` hors dépôt.** `public/index.php:16-19` lit `deployed-version.json` à la racine du projet : si le fichier est absent, il retourne `['sha' => null, 'ref' => null, 'deployedAt' => null]`. `deploy.yml` publie `deployed/<env>/version.json` sur la branche `deployed` (lecture complète de `deploy.yml` — aucune étape n'écrit à la racine du workspace servi). Quel mécanisme dépose ce fichier à la racine sur l'hôte est `INCONNU` : un script ou webhook externe peut exister sans être dans ce dépôt. Il n'est pas possible de conclure à une fonctionnalité incomplète sans accès à l'hôte.

**VÉRIFIÉ_CODE — Priorité de `schemaVersion` : fichier > base live.** `public/index.php:27` fusionne `$version + ['schemaVersion' => Db::schemaVersion($pdo)]`. L'opérateur `+` PHP conserve la clé du tableau gauche si elle existe déjà. `deploy.yml:61` écrit `schemaVersion` dans `deployed/<env>/version.json`. Si ce fichier est déposé à la racine en production, c'est la valeur du fichier qui serait retournée, pas la base live — un décalage après migration manuelle ou restauration serait silencieux.

**VÉRIFIÉ_CODE — Configuration par variable d'environnement.** `SVC_DB_PATH` permet d'overrider le chemin de la base (`src/Db.php:11`). Utilisé par les tests pour pointer sur un fichier temporaire — usage correct et isolé. Aucune autre variable d'environnement n'est lue, ce qui simplifie le déploiement.

## Forces

- Architecture lisible d'un coup d'œil : 3 classes, 1 routeur, 1 migrateur, < 300 lignes total (`src/`, `public/`, `bin/`).
- Séparation des responsabilités claire : routage (`public/index.php`), connexion (`src/Db.php`), domaine (`src/Orders.php`), migrations (`bin/migrate.php`).
- Pas de dépendances tierces en production (uniquement extensions PHP natives), surface d'attaque liée aux dépendances déclarées minimale (`composer.json:4-5`).
- `SVC_DB_PATH` comme unique point de configuration externe : testable sans mock, portable (`src/Db.php:11`).
- Distinction nette API/mutations : zéro écriture via HTTP, toutes les mutations passent par `bin/migrate.php`.

## Dettes techniques

- **Connexion avant routage** : `Db::connect()` est appelé avant le `switch` dans `public/index.php:11`, rendant `/health` dépendant de la disponibilité SQLite — contraire à l'intention d'une sonde de santé.
- **Absence de gestion d'exception dans le routeur** : aucun `try/catch` dans `public/index.php` — toute exception non attrapée produit une réponse dont le format dépend de la configuration PHP (non vérifiable dans le dépôt).
- **Chaînon `deployed-version.json` hors dépôt** : le mécanisme de dépôt du fichier de version sur l'hôte servi n'est pas dans le dépôt — vérification requise sur l'hôte.

## Zones critiques

- **`public/index.php:11` (connexion avant routage)** : un senior regarderait ici en premier en cas d'incident où `/health` ne répond pas correctement alors que le problème est la base.
- **`public/index.php:16-27` (lecture et fusion de `deployed-version.json`)** : deux comportements fragiles couplés — `json_decode` peut retourner `null` sur JSON invalide, puis l'opérateur `+` sur un potentiel `null` lève une `TypeError` en PHP 8.
- **`deploy.yml` (chaînon vers l'hôte servi)** : le lien entre la branche `deployed` et la racine du projet servi n'est pas dans ce dépôt.

## Risques

- **`/health` dépendante de SQLite** : si SQLite est inaccessible, la connexion PDO échouera et la sonde ne retournera pas `{"status":"ok"}` — comportement déduit du code, non exécuté (`public/index.php:11`).
- **Réponse d'erreur non-JSON (hypothèse)** : en l'absence de `try/catch`, une exception PHP non attrapée produirait une réponse dont le contenu dépend de `display_errors` — directive non vérifiable dans le dépôt. (`public/index.php`)
- **`/version` retournant des champs nuls** : si `deployed-version.json` n'est pas déposé à la racine, `/version` retourne `sha: null, ref: null, deployedAt: null` sans erreur — comportement vérifié par lecture du code. Que ce mécanisme existe ou non sur l'hôte est `INCONNU`. (`public/index.php:16-19`)
- **`schemaVersion` désynchronisée** : un décalage entre le fichier de version et la base réelle ne génère aucune alerte. (`public/index.php:27`)

## Recommandations priorisées

1. **Déplacer `Db::connect()` après le case `/health`** — évite de rendre la sonde dépendante de SQLite — `public/index.php`.
2. **Envelopper le corps du routeur dans un `try/catch`** — garantit une réponse JSON cohérente pour toutes les erreurs — `public/index.php`.
3. **Protéger la lecture de `deployed-version.json`** — vérifier que `json_decode` retourne bien un tableau : `$version = is_array($v) ? $v : ['sha' => null, 'ref' => null, 'deployedAt' => null]` — `public/index.php:17-19`.
4. **Vérifier le mécanisme de dépôt de `deployed-version.json`** — identifier et documenter (ou versionner) le script ou webhook qui dépose ce fichier à la racine du projet servi — question ouverte sur l'hôte.

## Questions ouvertes

- Quel mécanisme dépose `deployed-version.json` à la racine du projet servi ? (hébergement, webhook, script post-deploy hors dépôt)
- La priorité du fichier sur la base live pour `schemaVersion` est-elle intentionnelle ? Dans quel scénario la valeur base live serait-elle plus juste ?
- Est-il prévu que `/health` dépende de la disponibilité SQLite, ou est-ce un oubli de conception ?
- `display_errors` est-il configuré à `Off` sur l'hôte servi ? (non visible dans le dépôt)
