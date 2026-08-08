# Fonctionnel — Audit

> Confiance : medium — les chemins de code (routage, appels de méthode, types) sont vérifiables par lecture statique ; les effets HTTP runtime (codes de réponse effectifs, comportement sous panne SQLite) ne sont pas observables sans exécution ; le mécanisme de dépôt de `deployed-version.json` sur l'hôte servi est `INCONNU`.

## Compréhension globale

shift-pilot-svc expose quatre endpoints HTTP en lecture seule, appuyés sur une base SQLite avec 5 commandes fictives. La fonctionnalité métier réelle est intentionnellement minimale — ce n'est pas un produit. La valeur fonctionnelle du projet est opérationnelle : tester la distinction entre deux canaux de déploiement (`staging` vs `main`) et l'observabilité de la version réellement servie. L'audit fonctionnel porte donc autant sur la cohérence des endpoints que sur le bon fonctionnement du mécanisme de version servie, qui est la fonctionnalité centrale.

## Résumé exécutif

Les quatre endpoints sont implémentés, cohérents avec la carte des domaines et les workflows, et correctement répondants aux cas d'erreur standard (404 sur commande absente, 404 sur route inconnue). L'unique point fonctionnel conditionnel est le mécanisme de dépôt de `deployed-version.json` : `deploy.yml` (VÉRIFIÉ_CODE) écrit `deployed/<env>/version.json` mais n'écrit pas ce fichier à la racine du projet servi ; `public/index.php:16-19` (VÉRIFIÉ_CODE) lit `deployed-version.json` à cette racine. Si aucun mécanisme externe (hébergement, webhook) ne comble cet écart de chemin, `/version` retournerait `sha: null, ref: null, deployedAt: null` — HYPOTHÈSE conditionnelle, non vérifiable dans ce dépôt (hôte : INCONNU). Un second point à surveiller est la subtilité de l'opérateur `+` sur `/version` : si `deployed-version.json` contient un `schemaVersion` obsolète, la valeur retournée par l'API peut ne pas refléter l'état réel de la base. Pour le reste, la cohérence est bonne.

## Constats détaillés

**VÉRIFIÉ_CODE (chemin) + HYPOTHÈSE (effet) + INCONNU (hôte) — `GET /health` : fonctionnel mais dépendant de SQLite.** `public/index.php:11` ouvre la connexion PDO avant tout routage (VÉRIFIÉ_CODE). `public/index.php:22-24` retourne `{"status":"ok"}` si l'exécution atteint ce point (VÉRIFIÉ_CODE). Aucun `try/catch` n'encadre la connexion (VÉRIFIÉ_CODE). HYPOTHÈSE : si SQLite est inaccessible, une `PDOException` non attrapée s'échapperait, et la réponse serait probablement HTTP 500 — mais le code de réponse effectif dépend de la configuration PHP sur l'hôte (`display_errors`, gestionnaire d'erreurs), qui est INCONNU dans ce dépôt. Fonctionnellement, la sonde teste implicitement la disponibilité de la base. Ce comportement n'est pas documenté comme tel dans le README.

**VÉRIFIÉ_CODE — `GET /version` : fonctionnel avec un comportement de priorité non trivial.** `public/index.php:27` retourne `$version + ['schemaVersion' => Db::schemaVersion($pdo)]`. L'opérateur `+` PHP conserve la valeur du tableau gauche si la clé existe déjà. `deploy.yml:46` écrit `schemaVersion` dans `deployed-version.json` lors de chaque déploiement — donc en production, c'est la valeur du fichier qui est retournée, pas la base live. Ce comportement est intentionnel (la version servie fait foi) mais peut induire une divergence silencieuse si la base est modifiée hors déploiement. Si `deployed-version.json` est absent, les champs `sha`, `ref`, `deployedAt` valent `null` et `schemaVersion` est lue depuis la base — comportement gracieux, mais non alertant.

**VÉRIFIÉ_CODE — `GET /orders` : fonctionnel.** `src/Orders.php:14-16` retourne `SELECT id, client, montant_cents, devise, statut FROM orders ORDER BY id` — liste complète triée. Les 5 lignes fictives sont correctement retournées. Pas de pagination, pas de filtre — attendu pour un banc d'essai. Test : `testListeToutesLesCommandes` (`tests/OrdersTest.php:25-27`).

**VÉRIFIÉ_CODE — `GET /orders/{id}` : fonctionnel avec 404 correct.** `public/index.php:35-43` + `src/Orders.php:18-24`. La regex `#^/orders/(\d+)$#` filtre les identifiants non entiers (→ 404 Route inconnue, pas 404 Commande introuvable). `Orders::find()` retourne `null` sur `fetch() === false` et le routeur émet `http_response_code(404)` + `{"error":"Commande introuvable"}`. Test : `testTrouveUneCommandeParIdentifiant` et `testIdentifiantInconnuRenvoieNull` (`tests/OrdersTest.php:29-37`).

**VÉRIFIÉ_CODE (écart de chemin) + INCONNU (hôte) — Mécanisme de dépôt de `deployed-version.json`.** `public/index.php:16-19` (VÉRIFIÉ_CODE) lit `deployed-version.json` à la racine du projet servi. `deploy.yml` (VÉRIFIÉ_CODE) écrit dans `deployed/<env>/version.json` sur la branche `deployed` — pas à la racine du projet servi. L'écart entre ces deux chemins est un fait de code. En revanche, l'existence ou l'absence d'un mécanisme externe (hébergement, script de déploiement, webhook) qui copierait ce fichier à la racine est INCONNU : aucun artefact dans ce dépôt ne le décrit, mais cela ne prouve pas son absence. HYPOTHÈSE conditionnelle : si ce mécanisme est absent, `/version` retournerait `sha: null, ref: null, deployedAt: null` sans erreur HTTP. La sévérité effective dépend de l'environnement d'hébergement (INCONNU).

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

- **`/version` si le mécanisme de dépôt de `deployed-version.json` est absent de l'hôte** (INCONNU) : HYPOTHÈSE — la fonctionnalité principale du banc d'essai retournerait des champs `null` sans erreur HTTP ni alerte. Sévérité conditionelle à la configuration d'hébergement.
- **`/health` sous panne SQLite** (HYPOTHÈSE + hôte INCONNU) : si la connexion PDO échoue avant le routage, la sonde pourrait être indisponible précisément quand elle est la plus utile — mais l'effet exact dépend de la configuration PHP sur l'hôte.

## Risques

- **Version servie potentiellement invisible** (HYPOTHÈSE — hôte INCONNU) : si `deployed-version.json` n'est pas déposé à la racine du projet servi par un mécanisme externe, `/version` retournerait des champs nuls. Un agent ou un humain s'appuyant sur `/version` pour confirmer un déploiement penserait que rien n'a été déployé alors que la branche `deployed` est à jour. La réalité dépend de l'environnement d'hébergement. (`public/index.php:16-19`)
- **`schemaVersion` désynchronisée** (VÉRIFIÉ_CODE chemin, HYPOTHÈSE effet) : la valeur du fichier prime sur la base live (`opérateur +`, `public/index.php:27`) — si la base est modifiée hors déploiement, `/version` indiquerait une version de schéma obsolète sans alerte. Possible mais conditionnel à une modification hors cycle.
- **Disponibilité de `/health` sous panne SQLite** (HYPOTHÈSE + hôte INCONNU) : un monitoring qui compte sur `/health` pour distinguer « service down » de « base down » ne pourrait probablement pas faire cette distinction — mais l'effet exact (HTTP 500, réponse vide, etc.) dépend de la configuration PHP sur l'hôte. (`public/index.php:11`)

## Recommandations priorisées

1. **Clarifier le mécanisme de dépôt de `deployed-version.json`** — identifier si un mécanisme externe (hébergement, webhook, script) copie ce fichier à la racine du projet servi, et si oui le versionner ou le documenter ; si non, le créer. Sans ce maillon (dont l'existence est INCONNU hors de ce dépôt), la fonctionnalité centrale du banc d'essai serait silencieusement incomplète.
2. **Documenter le comportement de `/health`** — clarifier dans le README si la sonde de santé est conçue pour dépendre de SQLite, et si oui, le documenter comme « deep health check ».
3. **Ajouter un commentaire sur l'opérateur `+` dans `public/index.php:27`** — expliquer pourquoi le fichier a la priorité sur la base pour `schemaVersion`.

## Questions ouvertes

- Le mécanisme qui dépose `deployed-version.json` sur l'hôte servi est-il documenté quelque part (hébergement, script, webhook) ? (question partagée avec ARCHITECTURE_AUDIT et WORKFLOW_DEPLOIEMENT)
- Le comportement de `/health` (dépendant de SQLite) est-il intentionnel (deep health check) ou un oubli de conception ?
- Des évolutions fonctionnelles sont-elles prévues pour ce service (filtres sur `/orders`, écriture via API, authentification) ? Elles conditionneraient fortement les priorités de remédiation.
