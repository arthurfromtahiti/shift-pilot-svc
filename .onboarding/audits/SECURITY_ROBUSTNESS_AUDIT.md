# Sécurité & Robustesse — Audit

> Confiance : high

## Compréhension globale

Le service présente une surface d'attaque extrêmement réduite : lecture seule, données fictives, pas d'authentification requise, pas de secrets, pas de dépendances tierces en production. Les requêtes SQL paramétrées éliminent tout risque d'injection. Les risques résiduels identifiés relèvent de la robustesse (comportement sur entrée inattendue ou état dégradé) plutôt que de la sécurité stricte. Un seul vecteur d'erreur non géré peut provoquer un crash avec fuite d'informations système si `display_errors` est actif.

## Résumé exécutif

La posture de sécurité est saine pour un banc d'essai. Les requêtes SQL sont toutes préparées ou sans paramètre utilisateur, le routeur filtre les identifiants par regex avant tout cast, et aucun secret n'est présent dans le dépôt. Les risques à traiter avant toute extension publique sont : (1) l'absence de `try/catch` dans le routeur, qui peut exposer une stack trace si `display_errors` est activé, (2) la lecture non protégée de `deployed-version.json` — si le fichier contient un JSON invalide, `json_decode` retourne `null` et l'opérateur `+` PHP lève une `TypeError` fatale, (3) la connexion base ouverte avant `/health`, qui empêche la sonde de répondre si SQLite est indisponible, et (4) l'absence de vérification d'intégrité de la sauvegarde avant migration. Ces quatre points sont peu risqués dans le contexte actuel (banc d'essai, données fictives, pas d'accès public) mais constituent des dettes à liquider avant toute exposition en conditions réelles.

## Constats détaillés

**VÉRIFIÉ_CODE — Requêtes SQL paramétrées : pas d'injection possible.** `Orders::find(int $id)` utilise `$pdo->prepare('SELECT … WHERE id = :id')` avec `execute([':id' => $id])` (`src/Orders.php:20-23`). `Orders::all()` utilise `query()` sans paramètre utilisateur (`src/Orders.php:15`). Les requêtes dans `Db::schemaVersion()` interrogent `sqlite_master` et `schema_migrations` sans paramètre externe (`src/Db.php:27-31`). Aucune interpolation de chaîne dans un contexte SQL.

**VÉRIFIÉ_CODE — Filtrage des identifiants par regex.** Le routeur `public/index.php:35` utilise `preg_match('#^/orders/(\d+)$#', $path, $m)` pour ne laisser passer que des entiers dans `find()`. Le cast `(int) $m[1]` est redondant (la regex garantit déjà `\d+`) mais sans risque. Tout autre format de chemin tombe en 404 Route inconnue.

**VÉRIFIÉ_CODE — Pas de secret dans le dépôt.** Confirmé par lecture complète de l'arborescence : aucun token, clé API, mot de passe ou credential n'est présent dans les fichiers versionnés. Les seules données en base sont fictives et sans caractère personnel (`migrations/001_init.sql:15-20`, README).

**VÉRIFIÉ_CODE — Risque de crash sur JSON invalide dans `deployed-version.json`.** `public/index.php:17-18` lit le fichier via `json_decode((string) file_get_contents($versionFile), true)`. Si le fichier existe mais contient un JSON malformé, `json_decode` retourne `null` (pas d'exception). La ligne suivante `public/index.php:27` exécute `$version + ['schemaVersion' => Db::schemaVersion($pdo)]` — en PHP 8, additionner `null` et un tableau lève une `TypeError` fatale. Ce chemin n'est protégé par aucun `try/catch` ni par une validation du retour de `json_decode`. Pour les requêtes `/version`, le crash se produirait avant toute réponse.

**VÉRIFIÉ_CODE — Stack trace potentiellement exposée.** Aucun `try/catch` dans `public/index.php`. Si `Db::connect()` lève une `PDOException` (fichier absent, verrouillé, corrompu), ou si la `TypeError` ci-dessus se déclenche, PHP affichera la stack trace dans la sortie standard. Avec `Content-Type: application/json` déjà posé (`public/index.php:8`) et `display_errors = On`, la stack trace apparaîtra dans une réponse au content-type JSON. Pour un banc d'essai sans données sensibles, l'impact est nul. À corriger avant exposition publique.

**VÉRIFIÉ_CODE — Absence de vérification de méthode HTTP.** `$_SERVER['REQUEST_METHOD']` n'est jamais consulté dans `public/index.php` (lecture complète, ligne 1-47). `POST /orders`, `DELETE /health`, etc., reçoivent la même réponse que le `GET` correspondant. Pour un service en lecture seule avec données fictives, le risque est nul. Un audit de sécurité sur un service étendu devrait poser un code 405 pour les méthodes non supportées.

**VÉRIFIÉ_CODE — `Db::connect()` avant `/health` : sonde dépendante de la base.** `public/index.php:11` ouvre la connexion PDO avant le `switch`. Une `PDOException` non attrapée empêche toute réponse, y compris sur `/health`. Ce point a un impact de robustesse opérationnelle : un système de monitoring qui sonde `/health` recevrait HTTP 500 au lieu d'un indicateur d'état de la base.

**VÉRIFIÉ_CODE — Sauvegarde sans vérification d'intégrité.** `bin/migrate.php:56` réalise `copy(Db::path(), $sauvegarde)`. La fonction `copy()` peut réussir même sur un fichier SQLite corrompu (elle copie les octets tels quels). Aucun `PRAGMA integrity_check` n'est exécuté ni avant la copie ni après. Si la base est corrompue au moment de la sauvegarde, la copie sera corrompue également, sans aucune alerte. Pour un fichier versionné dans le dépôt récupérable via git, le risque est faible mais réel si la corruption précède la sauvegarde.

**VÉRIFIÉ_CODE — Permissions des fichiers : cohérentes.** `data/backups/` est créé avec `0o775` (`bin/migrate.php:53`). `data/app.db` a les permissions `rw-r--r--` (644) observées via `ls -la`. Pas de permission trop ouverte.

**VÉRIFIÉ_CODE — `foreign_keys = ON` activé.** `src/Db.php:20` exécute `PRAGMA foreign_keys = ON` à chaque connexion. Efficace pour toute contrainte d'intégrité référentielle qui serait ajoutée dans de futures migrations.

## Forces

- Requêtes SQL préparées pour toute opération paramétrée — pas d'injection SQL possible (`src/Orders.php:20-23`).
- Filtrage des entrées utilisateur par regex avant tout traitement (`public/index.php:35`).
- Zéro secret dans le dépôt, données fictives uniquement (README, `migrations/001_init.sql`).
- `PDO::ERRMODE_EXCEPTION` + `FETCH_ASSOC` : comportement prévisible, pas de fetch par indice silencieux (`src/Db.php:18-19`).
- `foreign_keys = ON` : intégrité référentielle active à chaque connexion (`src/Db.php:20`).
- Aucune dépendance tierce en production : surface d'attaque liée à la supply chain = zéro (`composer.json:4`).

## Dettes techniques

- **Crash sur JSON invalide** : lecture de `deployed-version.json` sans validation du retour de `json_decode` + opérateur `+` sur potentiel `null` → `TypeError` fatale en PHP 8 (`public/index.php:17-19,27`).
- **Absence de `try/catch` dans le routeur** : toute exception non attrapée peut exposer une stack trace avec `display_errors = On` (`public/index.php`).
- **Sonde `/health` dépendante de SQLite** : `/health` échoue si la base est inaccessible (`public/index.php:11`).
- **Absence de vérification d'intégrité de la sauvegarde** : `copy()` réussit sur un fichier corrompu sans alerte (`bin/migrate.php:56`).

## Zones critiques

- **`public/index.php:17-19,27` (lecture `deployed-version.json`)** : un senior regarderait ici en priorité — deux comportements fragiles couplés, aucun filet de sécurité, chemin atteignable en production si le fichier est mal formé.
- **`public/index.php:11` (connexion avant routage)** : point de défaillance unique affectant tous les endpoints, y compris la sonde de santé.

## Risques

- **`TypeError` fatale sur `/version`** : si `deployed-version.json` contient un JSON invalide (écriture partielle, corruption disque), le service crash sur toute requête `/version` — impact opérationnel immédiat. (`public/index.php:17-19,27`)
- **Fuite de stack trace** : en environnement avec `display_errors = On`, toute exception non attrapée produit une réponse HTTP 500 avec stack trace dans le corps. Pour ce banc d'essai, sans données sensibles, l'impact est nul. (`public/index.php`)
- **Sauvegarde silencieusement corrompue** : `copy()` sur une base corrompue produit une sauvegarde corrompue sans alerte — récupération impossible depuis cette sauvegarde. (`bin/migrate.php:56`)
- **Sonde de santé inutilisable en cas de panne base** : `/health` retourne HTTP 500 quand SQLite est indisponible, précisément le moment où la sonde est la plus utile. (`public/index.php:11`)

## Recommandations priorisées

1. **Protéger la lecture de `deployed-version.json`** — vérifier que `json_decode` retourne bien un tableau avant de l'utiliser : `$version = (is_array($v = json_decode(..., true))) ? $v : ['sha' => null, 'ref' => null, 'deployedAt' => null]` — `public/index.php:17-19`.
2. **Déplacer `Db::connect()` après le case `/health`** — rendre la sonde indépendante de la disponibilité SQLite — `public/index.php`.
3. **Envelopper le routeur dans un `try/catch`** — garantir une réponse JSON cohérente sur toute erreur, empêcher les fuites de stack trace — `public/index.php`.
4. **Ajouter `PRAGMA integrity_check` avant copie dans `bin/migrate.php`** — détecter une base corrompue avant de la sauvegarder et de l'utiliser comme point de restauration — `bin/migrate.php:51-59`.

## Questions ouvertes

- `display_errors` est-il configuré à `Off` sur l'hôte servi ? (non visible dans le dépôt)
- La dépendance de `/health` à la base est-elle intentionnelle (health-check profond) ou un oubli ?
- Un `PRAGMA integrity_check` avant sauvegarde est-il trop coûteux pour une base de cette taille ? (12 KB en l'état, probablement négligeable)
