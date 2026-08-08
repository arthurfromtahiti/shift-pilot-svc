# Fonctionnel — Audit

> Confiance : high

## Compréhension globale

shift-pilot-svc expose quatre endpoints HTTP en lecture seule, appuyés sur une base SQLite avec 5 commandes fictives. La fonctionnalité métier réelle est intentionnellement minimale — ce n'est pas un produit. La valeur fonctionnelle du projet est opérationnelle : tester la distinction entre deux canaux de déploiement (`staging` vs `main`) et l'observabilité de la version réellement servie. L'audit fonctionnel porte donc autant sur la cohérence des endpoints que sur le bon fonctionnement du mécanisme de version servie, qui est la fonctionnalité centrale.

## Résumé exécutif

Les quatre endpoints sont implémentés, cohérents avec la carte des domaines et les workflows, et correctement répondants aux cas d'erreur standard (404 sur commande absente, 404 sur route inconnue). La seule incomplétude fonctionnelle notable est le chaînon `deployed-version.json` : `deploy.yml` publie sur la branche `deployed` mais ne dépose pas ce fichier à la racine du projet servi, rendant `/version` silencieusement incomplet (`sha: null, ref: null, deployedAt: null`) si le mécanisme d'hébergement ne l'y dépose pas. Ce trou est documenté mais non résolu. Un second point fonctionnel à surveiller est la subtilité de l'opérateur `+` sur `/version` : si `deployed-version.json` contient un `schemaVersion` obsolète, la valeur retournée par l'API peut ne pas refléter l'état réel de la base. Pour le reste, la cohérence est bonne.

## Constats détaillés

**VÉRIFIÉ_CODE — `GET /health` : fonctionnel mais dépendant de SQLite.** `public/index.php:22-24` retourne `{"status":"ok"}` après avoir ouvert la connexion PDO à `public/index.php:11`. La connexion précède le routage — si SQLite est inaccessible, `/health` répondra HTTP 500 au lieu de `{"status":"ok"}`. Fonctionnellement, cela signifie que la sonde de santé teste implicitement la disponibilité de la base. Ce comportement n'est pas documenté comme tel dans le README. Pour un monitoring qui s'attend à une réponse rapide même quand la base est lente, c'est une source de faux positifs d'indisponibilité.

**VÉRIFIÉ_CODE — `GET /version` : fonctionnel avec un comportement de priorité non trivial.** `public/index.php:27` retourne `$version + ['schemaVersion' => Db::schemaVersion($pdo)]`. L'opérateur `+` PHP conserve la valeur du tableau gauche si la clé existe déjà. `deploy.yml:46` écrit `schemaVersion` dans `deployed-version.json` lors de chaque déploiement — donc en production, c'est la valeur du fichier qui est retournée, pas la base live. Ce comportement est intentionnel (la version servie fait foi) mais peut induire une divergence silencieuse si la base est modifiée hors déploiement. Si `deployed-version.json` est absent, les champs `sha`, `ref`, `deployedAt` valent `null` et `schemaVersion` est lue depuis la base — comportement gracieux, mais non alertant.

**VÉRIFIÉ_CODE — `GET /orders` : fonctionnel.** `src/Orders.php:14-16` retourne `SELECT id, client, montant_cents, devise, statut FROM orders ORDER BY id` — liste complète triée. Les 5 lignes fictives sont correctement retournées. Pas de pagination, pas de filtre — attendu pour un banc d'essai. Test : `testListeToutesLesCommandes` (`tests/OrdersTest.php:25-27`).

**VÉRIFIÉ_CODE — `GET /orders/{id}` : fonctionnel avec 404 correct.** `public/index.php:35-43` + `src/Orders.php:18-24`. La regex `#^/orders/(\d+)$#` filtre les identifiants non entiers (→ 404 Route inconnue, pas 404 Commande introuvable). `Orders::find()` retourne `null` sur `fetch() === false` et le routeur émet `http_response_code(404)` + `{"error":"Commande introuvable"}`. Test : `testTrouveUneCommandeParIdentifiant` et `testIdentifiantInconnuRenvoieNull` (`tests/OrdersTest.php:29-37`).

**VÉRIFIÉ_CODE — Chaînon `deployed-version.json` absent du dépôt.** C'est le trou fonctionnel le plus significatif du service. `public/index.php:16-19` lit `deployed-version.json` à la racine du projet servi. Ce fichier n'est pas versionné dans `main`/`staging`, et `deploy.yml` ne le dépose jamais dans le workspace servi — il publie sur la branche `deployed`. Le mécanisme qui relie `deployed/<env>/version.json` à `deployed-version.json` à la racine n'est pas dans le dépôt. Conséquence fonctionnelle observable : `/version` retourne `sha: null, ref: null, deployedAt: null` si ce mécanisme est absent ou défaillant, sans erreur HTTP explicite. Le service répond correctement mais la fonctionnalité centrale (observer la version réellement servie) est silencieusement vide.

**VÉRIFIÉ_CODE — Méthodes HTTP non filtrées : comportement défini mais non documenté.** `$_SERVER['REQUEST_METHOD']` n'est jamais consulté (`public/index.php:1-47`). `POST /orders` et `DELETE /health` reçoivent les mêmes réponses que leurs équivalents GET. Pour un service en lecture seule avec données fictives, aucune conséquence pratique. Le workflow SERVICE documente ce point comme risque mineur (`WORKFLOW_SERVICE.md:94`).

**VÉRIFIÉ_CODE — Cohérence données/API.** Les champs retournés par l'API (`id`, `client`, `montant_cents`, `devise`, `statut`) correspondent exactement aux colonnes de la table `orders` sélectionnées dans `SELECT` (`src/Orders.php:15,21`). La valeur de `devise` (`XPF`) et les statuts (`payee`, `annulee`) des 5 lignes fictives sont cohérents avec `migrations/001_init.sql:15-20`. Le test `testTrouveUneCommandeParIdentifiant` valide `client` et `montant_cents` (`tests/OrdersTest.php:31-33`).

**VÉRIFIÉ_CODE — Pas de fonctionnalité inachevée.** Le périmètre déclaré (4 endpoints, lecture seule, données fictives) est entièrement implémenté. Aucune route commentée, aucun `TODO` dans le code source, aucun endpoint vide ou non fonctionnel. Le projet est cohérent avec son objectif de banc d'essai.

**VÉRIFIÉ_CODE — Distinction staging/production respectée.** `deploy.yml:40` détermine l'environnement : `main` → `production`, toute autre branche déclencheuse → `staging`. Les deux fichiers `version.json` coexistent sur `deployed` sans s'écraser (correction `da34e1e`). La promotion `staging → production` est explicitement un geste humain hors chaîne d'agents (README, carte des domaines).

## Forces

- Fonctionnalité métier complète et cohérente : tous les endpoints déclarés dans la carte des domaines sont implémentés (`public/index.php`, `src/Orders.php`).
- Réponses d'erreur cohérentes et JSON pour tous les cas standard (404 route, 404 commande, `Content-Type: application/json` sur toute réponse).
- Distinction staging/production correctement implémentée et testée par les workflows CI/CD (`deploy.yml:40`).
- `/version` ne déduit jamais la version servie depuis le code — lecture du fichier externe ou fallback `null` (`public/index.php:16-19`).

## Dettes techniques

- **Chaînon `deployed-version.json` hors dépôt** : fonctionnalité centrale silencieusement incomplète si le mécanisme de dépôt est absent (`public/index.php:16-19`, `deploy.yml`).
- **`/health` non documenté comme dépendant de SQLite** : comportement implicite non explicité dans le README ni dans un commentaire de code.
- **Priorité du fichier sur la base dans `/version`** : comportement non trivial non documenté explicitement pour un lecteur du routeur (`public/index.php:27`).

## Zones critiques

- **`/version` avec `deployed-version.json` absent** : la fonctionnalité principale du banc d'essai (observer la version servie) est silencieusement vide — pas d'erreur HTTP, pas d'alerte, simplement des champs `null`.
- **`/health` sous panne SQLite** : sonde indisponible exactement quand elle est la plus utile.

## Risques

- **Version servie invisible** : si `deployed-version.json` n'est pas déposé à la racine du projet servi, `/version` retourne des champs nuls. Un agent ou un humain qui s'appuie sur `/version` pour confirmer un déploiement penserait que rien n'a été déployé, alors que la branche `deployed` est à jour. (`public/index.php:16-19`)
- **`schemaVersion` désynchronisée** : la valeur du fichier prime sur la base live — si la base est modifiée hors déploiement (migration manuelle, restauration), `/version` indique une version de schéma obsolète sans alerte. (`public/index.php:27`)
- **Fausse disponibilité de `/health`** : un monitoring qui compte sur `/health` pour distinguer « service down » de « base down » ne peut pas faire cette distinction avec l'implémentation actuelle. (`public/index.php:11`)

## Recommandations priorisées

1. **Documenter et résoudre le mécanisme de dépôt de `deployed-version.json`** — identifier, versionner ou documenter le script/webhook qui dépose ce fichier à la racine du projet servi. Sans ce maillon, la fonctionnalité centrale du banc d'essai est incomplète.
2. **Documenter le comportement de `/health`** — clarifier dans le README si la sonde de santé est conçue pour dépendre de SQLite, et si oui, le documenter comme « deep health check ».
3. **Ajouter un commentaire sur l'opérateur `+` dans `public/index.php:27`** — expliquer pourquoi le fichier a la priorité sur la base pour `schemaVersion`.

## Questions ouvertes

- Le mécanisme qui dépose `deployed-version.json` sur l'hôte servi est-il documenté quelque part (hébergement, script, webhook) ? (question partagée avec ARCHITECTURE_AUDIT et WORKFLOW_DEPLOIEMENT)
- Le comportement de `/health` (dépendant de SQLite) est-il intentionnel (deep health check) ou un oubli de conception ?
- Des évolutions fonctionnelles sont-elles prévues pour ce service (filtres sur `/orders`, écriture via API, authentification) ? Elles conditionneraient fortement les priorités de remédiation.
