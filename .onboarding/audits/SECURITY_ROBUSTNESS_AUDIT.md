# Sécurité & Robustesse — Audit

> Confiance : medium — les chemins de code sont vérifiés par lecture statique ; les effets runtime (TypeError, stack trace, permissions effectives) dépendent de la configuration PHP et du filesystem servi, non vérifiables dans ce dépôt.

## Compréhension globale

Le service présente une surface d'attaque extrêmement réduite : lecture seule, données fictives, pas d'authentification requise, pas de secrets, pas de dépendances tierces en production. Les requêtes SQL paramétrées éliminent tout risque d'injection pour les routes actuellement présentes. Les risques résiduels identifiés relèvent de la robustesse (comportement sur entrée inattendue ou état dégradé) plutôt que de la sécurité stricte. Un seul vecteur d'erreur non géré peut provoquer une réponse dégradée dont la forme dépend de la configuration PHP — inconnue dans ce dépôt.

## Résumé exécutif

La posture de sécurité est saine pour un banc d'essai. Les requêtes SQL sont toutes préparées ou sans paramètre utilisateur, le routeur filtre les identifiants par regex avant tout cast, et aucun secret n'est présent dans le dépôt. Les risques à traiter avant toute extension publique sont : (1) l'absence de `try/catch` dans le routeur, dont la conséquence dépend de `display_errors` (valeur non connue sur l'hôte), (2) la lecture non protégée de `deployed-version.json` — si le fichier existe mais contient un JSON invalide, `json_decode` retourne `null` et l'opérateur `+` PHP peut lever une `TypeError` en PHP 8 (HYPOTHÈSE : requiert fichier présent et JSON invalide), (3) la connexion base ouverte avant `/health`, et (4) l'absence de vérification d'intégrité de la sauvegarde avant migration. Ces points sont peu risqués dans le contexte actuel (banc d'essai, données fictives, pas d'accès public) mais constituent des dettes à liquider avant toute exposition en conditions réelles.

## Constats détaillés

**VÉRIFIÉ_CODE — Requêtes SQL paramétrées pour les routes actuelles.** `Orders::find(int $id)` utilise `$pdo->prepare('SELECT … WHERE id = :id')` avec `execute([':id' => $id])` (`src/Orders.php:20-23`). `Orders::all()` utilise `query()` sans paramètre utilisateur (`src/Orders.php:15`). Les requêtes dans `Db::schemaVersion()` interrogent `sqlite_master` et `schema_migrations` sans paramètre externe (`src/Db.php:27-31`). Aucune interpolation de chaîne dans un contexte SQL sur les routes actuelles. Ce constat est borné au code lu — une extension future devra être réévaluée.

**VÉRIFIÉ_CODE — Filtrage des identifiants par regex.** Le routeur `public/index.php:35` utilise `preg_match('#^/orders/(\d+)$#', $path, $m)` pour ne laisser passer que des entiers dans `find()`. Le cast `(int) $m[1]` est redondant (la regex garantit déjà `\d+`) mais sans risque. Tout autre format de chemin tombe en 404 Route inconnue.

**VÉRIFIÉ_CODE — Pas de secret dans le dépôt.** Confirmé par lecture complète de l'arborescence : aucun token, clé API, mot de passe ou credential n'est présent dans les fichiers versionnés. Les seules données en base sont fictives et sans caractère personnel (`migrations/001_init.sql:15-20`, README).

**VÉRIFIÉ_CODE (chemin de code) + HYPOTHÈSE (effet runtime) — Risque de TypeError sur JSON invalide.** `public/index.php:17-18` lit `deployed-version.json` via `json_decode((string) file_get_contents($versionFile), true)` seulement si `is_file($versionFile)` est vrai. Si le fichier existe mais contient un JSON malformé, `json_decode` retourne `null`. La ligne `public/index.php:27` exécuterait alors `null + ['schemaVersion' => ...]` — en PHP 8, cette opération lève une `TypeError` fatale. Ce chemin de code est identifiable par lecture statique. La TypeError elle-même est une hypothèse : elle requiert (a) que le fichier soit présent sur l'hôte et (b) qu'il contienne un JSON invalide — conditions non vérifiables dans ce dépôt.

**VÉRIFIÉ_CODE (absence de try/catch) + HYPOTHÈSE (effet) + INCONNU (configuration)— Exception non attrapée dans le routeur.** `public/index.php` ne contient aucun `try/catch` (lecture complète). Toute exception non attrapée (PDOException, TypeError) s'échappe. La forme de la réponse dépend de `display_errors` sur l'hôte — valeur `INCONNU` : si `Off`, la sortie standard serait vide avec HTTP 500 ; si `On`, la stack trace apparaîtrait dans le corps. `Content-Type: application/json` est déjà posé (`public/index.php:8`), ce qui ment dans les deux cas. Ces effets sont des hypothèses conditionnelles à la configuration runtime.

**VÉRIFIÉ_CODE — Absence de vérification de méthode HTTP.** `$_SERVER['REQUEST_METHOD']` n'est jamais consulté dans `public/index.php` (lecture complète, lignes 1-47). `POST /orders`, `DELETE /health`, etc., reçoivent la même réponse que le `GET` correspondant. Pour un service en lecture seule avec données fictives, le risque est nul. Un audit de sécurité sur un service étendu devrait poser un code 405 pour les méthodes non supportées.

**VÉRIFIÉ_CODE — `Db::connect()` avant `/health` : sonde dépendante de la base.** `public/index.php:11` ouvre la connexion PDO avant le `switch`. Une `PDOException` non attrapée empêche toute réponse, y compris sur `/health`. Ce point a un impact de robustesse opérationnelle : un système de monitoring qui sonde `/health` recevrait un résultat d'erreur au lieu d'un indicateur d'état de la base.

**VÉRIFIÉ_CODE — Sauvegarde sans vérification d'intégrité.** `bin/migrate.php:56` réalise `copy(Db::path(), $sauvegarde)`. La fonction `copy()` peut réussir même sur un fichier SQLite corrompu (elle copie les octets tels quels). Aucun `PRAGMA integrity_check` n'est exécuté ni avant la copie ni après. Si la base est corrompue au moment de la sauvegarde, la copie sera corrompue également, sans aucune alerte. Pour un fichier versionné dans le dépôt récupérable via git, le risque est faible mais réel si la corruption précède la sauvegarde. Note : `PRAGMA integrity_check` seul ne garantit pas non plus une copie cohérente pendant une écriture concurrente — une sauvegarde SQLite robuste nécessiterait `VACUUM INTO` ou un verrou exclusif.

**VÉRIFIÉ_CODE + INCONNU — Permissions des fichiers.** `data/backups/` est créé avec `0o775` (`bin/migrate.php:53`) — vérifié par lecture du code. Les permissions effectives de `data/app.db` sur le filesystem sont `INCONNU` : aucune commande reproductible n'a été exécutée dans cet audit et aucune sortie datée n'est disponible. Aucune permission trop ouverte n'est dérivable du code seul.

**VÉRIFIÉ_CODE — `foreign_keys = ON` activé.** `src/Db.php:20` exécute `PRAGMA foreign_keys = ON` à chaque connexion. Efficace pour toute contrainte d'intégrité référentielle qui serait ajoutée dans de futures migrations.

## Forces

- Requêtes SQL préparées pour toute opération paramétrée actuellement présente — pas d'injection SQL sur les routes lues (`src/Orders.php:20-23`).
- Filtrage des entrées utilisateur par regex avant tout traitement (`public/index.php:35`).
- Zéro secret dans le dépôt, données fictives uniquement (README, `migrations/001_init.sql`).
- `PDO::ERRMODE_EXCEPTION` + `FETCH_ASSOC` : comportement prévisible, pas de fetch par indice silencieux (`src/Db.php:18-19`).
- `foreign_keys = ON` : intégrité référentielle active à chaque connexion (`src/Db.php:20`).
- Aucune dépendance tierce de production déclarée dans `composer.json:4` — la surface d'attaque liée aux dépendances déclarées est nulle dans le périmètre de ce dépôt ; la configuration PHP, les extensions activées et l'hôte servi (INCONNU) ne sont pas couverts par ce constat.

## Dettes techniques

- **Chemin de code menant à TypeError** : lecture de `deployed-version.json` sans validation du retour de `json_decode` + opérateur `+` sur potentiel `null` (`public/index.php:17-19,27`).
- **Absence de `try/catch` dans le routeur** : toute exception non attrapée produit une réponse dont le format dépend de la configuration PHP (non vérifiable dans le dépôt) (`public/index.php`).
- **Sonde `/health` dépendante de SQLite** : `/health` ne peut pas répondre si la base est inaccessible (`public/index.php:11`).
- **Absence de vérification d'intégrité de la sauvegarde** : `copy()` réussit sur un fichier corrompu sans alerte (`bin/migrate.php:56`).

## Zones critiques

- **`public/index.php:17-19,27` (lecture `deployed-version.json`)** : chemin de code menant à TypeError identifiable statiquement — deux comportements fragiles couplés, aucun filet de sécurité.
- **`public/index.php:11` (connexion avant routage)** : point de défaillance unique affectant tous les endpoints, y compris la sonde de santé.

## Risques

- **Chemin de code menant à TypeError sur `/version`** : si `deployed-version.json` existe sur l'hôte mais contient un JSON invalide (écriture partielle, corruption disque), le service crasherait sur toute requête `/version`. Hypothèse conditionnelle — ne peut être reproduite sans exécution PHP. (`public/index.php:17-19,27`)
- **Réponse d'erreur de forme inconnue** : en l'absence de `try/catch`, une exception PHP non attrapée produit une réponse dont la forme dépend de `display_errors` — inconnue sur l'hôte. Pour ce banc d'essai, sans données sensibles, l'impact est conditionnel. (`public/index.php`)
- **Sauvegarde silencieusement corrompue** : `copy()` sur une base corrompue produit une sauvegarde corrompue sans alerte — récupération impossible depuis cette sauvegarde. (`bin/migrate.php:56`)
- **Sonde de santé inutilisable en cas de panne base** : `/health` ne peut pas retourner `{"status":"ok"}` quand SQLite est indisponible, précisément le moment où la sonde est la plus utile. (`public/index.php:11`)

## Recommandations priorisées

1. **Protéger la lecture de `deployed-version.json`** — vérifier que `json_decode` retourne bien un tableau avant de l'utiliser : `$version = (is_array($v = json_decode(..., true))) ? $v : ['sha' => null, 'ref' => null, 'deployedAt' => null]` — `public/index.php:17-19`.
2. **Déplacer `Db::connect()` après le case `/health`** — rendre la sonde indépendante de la disponibilité SQLite — `public/index.php`.
3. **Envelopper le routeur dans un `try/catch`** — garantir une réponse JSON cohérente sur toute erreur — `public/index.php`.
4. **Renforcer la sauvegarde dans `bin/migrate.php`** — `PRAGMA integrity_check` avant copie pour détecter une base corrompue (léger sur 12 KB) et envisager `VACUUM INTO` plutôt que `copy()` pour une sauvegarde cohérente — `bin/migrate.php:51-59`.

## Questions ouvertes

- `display_errors` est-il configuré à `Off` sur l'hôte servi ? (non visible dans le dépôt)
- La dépendance de `/health` à la base est-elle intentionnelle (health-check profond) ou un oubli ?
- Un `PRAGMA integrity_check` avant sauvegarde est-il trop coûteux pour une base de cette taille ? (12 KB en l'état, probablement négligeable)
