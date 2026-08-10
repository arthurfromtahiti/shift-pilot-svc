# Cartographie du code — shift-pilot-svc

## Vue d'ensemble

Le service compte **~210 lignes de code source PHP/SQL** répartis en deux domaines : le **routage HTTP** (`public/index.php`, 47 L) et la **couche métier** (`src/`, 2 fichiers, ~60 L). À cela s'ajoutent l'**exécuteur de migrations** (`bin/migrate.php`, 77 L) et le **schéma/données** (`migrations/001_init.sql`, 21 L).

Pas de framework, pas de conteneur d'injection de dépendances — une **architecture plate** intentionnelle pour maximiser la lisibilité et minimiser la surface d'attaque.

## Structure globale

```
shift-pilot-svc/
├── bin/
│   └── migrate.php           (77 L) — Exécuteur de migrations
├── src/
│   ├── Db.php                (34 L) — Couche PDO/SQLite
│   └── Orders.php            (30 L) — Domaine métier
├── public/
│   └── index.php             (47 L) — Routeur HTTP
├── migrations/
│   └── 001_init.sql          (21 L) — Schéma + données de test
├── tests/
│   └── OrdersTest.php        (26 L) — Suite PHPUnit
├── data/
│   ├── app.db                (~12 KB) — Base SQLite versionnée
│   └── backups/              (→ .gitignore) — Sauvegardes horodatées
├── .github/workflows/
│   ├── ci.yml                — Tests + essai à blanc
│   └── deploy.yml            — Déploiement + publication
└── composer.json             — Dépendances (phpunit dev uniquement)
```

## Fichiers critiques

### 1. `public/index.php` — Routeur HTTP (47 lignes)

**Rôle** : Dispatcher toutes les requêtes HTTP aux endpoints; formater les réponses JSON.

**Points clés** :

| Ligne(s) | Code | Rôle |
|----------|------|------|
| 8 | `header('Content-Type: application/json...')` | Déclare JSON sur toutes les réponses |
| 10-11 | Parsage du chemin + ouverture PDO | **Hotspot** : la base est ouverte avant tout routage |
| 16-19 | Lecture de `deployed-version.json` | **Hotspot** : lire la version servie depuis le fichier externe |
| 21-47 | Switch sur `$path` → 4 endpoints | Dispatch manuel (pas de framework) |
| 27 | Opérateur `+` sur version + schemaVersion | **Hotspot** : priorité du fichier sur la base |
| 35 | Regex `#^/orders/(\d+)$#` | Validation : seulement les identifiants entiers |
| 45-46 | Fallback 404 | Route inconnue (JSON `{error}`) |

**Hotspots identifiés** :
- **L.11** : `Db::connect()` lancé avant le routage → toute requête dépend de SQLite (y compris `/health`)
- **L.16-19** : Si `deployed-version.json` est absent ou invalide → champs `sha/ref/deployedAt` valent `null` sans alerte
- **L.27** : Opérateur `+` conserve la clé du tableau gauche → si le fichier contient `schemaVersion`, elle prime sur la base live

**Contrat de chaque endpoint** :
- `GET /health` → `{status: ok}` (200)
- `GET /version` → `{sha, ref, environnement, schemaVersion, deployedAt}` (200, champs `null` si fichier absent)
- `GET /orders` → `[{id, client, montant_cents, devise, statut}, ...]` (200)
- `GET /orders/{id}` → `{id, client, montant_cents, devise, statut}` (200) ou `{error}` (404)
- Autre → `{error: "Route inconnue"}` (404)

### 2. `src/Db.php` — Couche d'accès (34 lignes)

**Rôle** : Connexion PDO à SQLite, résolution du chemin de base, lecture du schéma appliqué.

**Public API** :
```php
Db::path(): string              // Retourne le chemin à data/app.db (override par SVC_DB_PATH)
Db::connect(): PDO              // Crée et retourne une connexion PDO
Db::schemaVersion(PDO): int     // Lit MAX(version) depuis schema_migrations
```

**Détails** :

| Ligne(s) | Code | Rôle |
|----------|------|------|
| 10-12 | `path()` | Lit `SVC_DB_PATH` en env ; sinon `dirname(__DIR__)/data/app.db` |
| 15-21 | `connect()` | PDO, mode exception, fetch assoc, `PRAGMA foreign_keys = ON` |
| 25-32 | `schemaVersion()` | Lit `MAX(version)` depuis `schema_migrations`, retourne 0 si table absente |

**Hypothèse de conception** :
- La base `data/app.db` est versionnée dans le dépôt → elle persiste réellement
- `foreign_keys = ON` est activé dès la connexion (prêt pour des contraintes étrangères futures)
- `schemaVersion()` retourne l'**état réel** de la base, jamais supposé

**Points de vigilance** :
- [ ] Ne jamais déduire le schéma du code ; toujours le demander à la base
- [ ] L'override `SVC_DB_PATH` est utilisé par PHPUnit pour une base temporaire
- [ ] Une exception levée ici échappe sans interception dans le routeur

### 3. `src/Orders.php` — Domaine métier (30 lignes)

**Rôle** : Requêtes en lecture seule sur la table `orders`.

**Public API** :
```php
Orders::all(): array            // Retourne [[], [], ...] triées par id
Orders::find(int): ?array       // Retourne {} ou null
Orders::count(): int            // Retourne le nombre de lignes
```

**Détails** :

| Ligne(s) | Code | Rôle |
|----------|------|------|
| 13-15 | `all()` | `SELECT id, client, montant_cents, devise, statut FROM orders ORDER BY id` |
| 18-23 | `find()` | Requête préparée (protection SQL injection), retourne array ou null |
| 26-28 | `count()` | Retourne COUNT(*) |

**Schéma de `orders`** :
```sql
CREATE TABLE orders (
  id            INTEGER PRIMARY KEY,
  client        TEXT    NOT NULL,
  montant_cents INTEGER NOT NULL,
  devise        TEXT    NOT NULL DEFAULT 'XPF',
  statut        TEXT    NOT NULL
);
```

**Données de test** (5 lignes fictives, v1 du schéma) :
```
1, Heiata,  420000, XPF, payee
2, Teiki,   180000, XPF, annulee
3, Manoa,   960000, XPF, payee
4, Vaite,   305000, XPF, payee
5, Moana,    75000, XPF, annulee
```

**Points de vigilance** :
- [ ] Aucune colonne n'a de contrainte CHECK → `statut` et `devise` acceptent n'importe quelle valeur
- [ ] Les montants sont **entiers** (centimes, pas euros) → évite les arrondis float
- [ ] Les requêtes retournent TOUS les champs de chaque ligne ; pas de select partiel

### 4. `bin/migrate.php` — Exécuteur de migrations (77 lignes)

**Rôle** : Appliquer les migrations SQL non encore appliquées, avec sauvegarde obligatoire et transactions.

**Modes d'exécution** :
```bash
php bin/migrate.php --dry-run   # Affiche le SQL, n'écrit rien
php bin/migrate.php             # Sauvegarde la base + applique réellement
```

**Flux d'exécution** :

| Ligne(s) | Étape | Détails |
|----------|-------|---------|
| 16-17 | Détection du mode + connexion | Flag `--dry-run` ; ouverture PDO |
| 20-31 | Découverte des migrations | Glob sur `migrations/*.sql`, tri lexicographique, extraction du préfixe `NNN_` |
| 33-37 | Affichage du statut | Version en base ; list des migrations à faire |
| 41-47 | Essai à blanc (si mode seco) | Affiche le SQL de chaque fichier, quitte avec code 0 |
| 50-59 | **Sauvegarde obligatoire** | Crée `data/backups/`, copie `app-<timestamp>-avant-v<N>.db`, **refuse d'appliquer si échoue** |
| 62-76 | Application réelle | Chaque migration dans sa propre transaction ; succès = INSERT dans `schema_migrations` ; erreur = rollback + exit 1 |
| 77 | Rapport final | Affiche la version finale du schéma |

**Hotspots identifiés** :

| Ligne(s) | Problème | Impact |
|----------|---------|--------|
| 20-31 | Tri lexicographique, pas numérique | Un fichier `10_*.sql` s'applique avant `002_*.sql` — **erreur silencieuse** |
| 25 | Pas de validation du préfixe | Un fichier mal nommé (`foo_*.sql`) est ignoré sans alerte |
| 29 | Collision silencieuse | Deux fichiers `002_a.sql` + `002_b.sql` → le premier disparaît |
| 55 | Message après rollback | « La base est inchangée » est techniquement vrai pour *cette* migration, mais les migrations antérieures restent appliquées |

**Convention requise (non vérifiée)** :
- Fichiers nommés `NNN_*.sql` où `NNN` = trois chiffres (`001`, `002`, `003`, …)
- Pas de doublons sur le préfixe

**Sauvegarde** :
- Chemin : `data/backups/app-<YYYYMMDD-HHmmss>-avant-v<N>.db`
- Horodatage UTC
- Répertoire créé s'il n'existe pas
- Non versionnée (`.gitignore`)

### 5. `migrations/001_init.sql` — Schéma initial (21 lignes)

**Rôle** : Première migration ; crée les tables et peuple les données de test.

**Contenu** :
```sql
CREATE TABLE schema_migrations (
  version    INTEGER PRIMARY KEY,
  applied_at TEXT NOT NULL
);

CREATE TABLE orders (
  id            INTEGER PRIMARY KEY,
  client        TEXT    NOT NULL,
  montant_cents INTEGER NOT NULL,
  devise        TEXT    NOT NULL DEFAULT 'XPF',
  statut        TEXT    NOT NULL
);

INSERT INTO orders (id, client, montant_cents, devise, statut) VALUES
  (1, 'Heiata', 420000, 'XPF', 'payee'),
  (2, 'Teiki',  180000, 'XPF', 'annulee'),
  (3, 'Manoa',  960000, 'XPF', 'payee'),
  (4, 'Vaite',  305000, 'XPF', 'payee'),
  (5, 'Moana',   75000, 'XPF', 'annulee');
```

**Points de vigilance** :
- [ ] Pas de contrainte CHECK sur `statut` ou `devise` → migration future doit valider les données existantes
- [ ] Pas d'index → si `/orders?statut=...` est ajouté, créer un index via migration
- [ ] Les montants sont en centimes (pas euros) → cohérent avec `Orders::all()` et les réponses JSON

## Workflows CI/CD

### `.github/workflows/ci.yml` — Tests et essai à blanc

**Déclenché par** : `push` sur `main`/`staging`, `pull_request`.

**Étapes** :
1. Checkout + setup PHP
2. `composer install`
3. **Essai à blanc des migrations** : `php bin/migrate.php --dry-run` (visibilité, pas d'effet)
4. **Tests** : `composer test` (PHPUnit avec base temporaire via `SVC_DB_PATH`)
5. Rapport de couverture (optionnel)

**Comportement** :
- Toute étape qui échoue → build ROUGE
- Les tests reconstruisent une base temporaire à chaque run (jamais `data/app.db`)
- L'essai à blanc est informatif — vert/rouge dépend du parsing SQL valide

### `.github/workflows/deploy.yml` — Déploiement et publication

**Déclenché par** : `push` sur `main` ou `staging`.

**Étapes** :
1. Checkout + setup PHP, `composer install`
2. **Tests** : `composer test` (vert = condition nécessaire)
3. **Essai à blanc des migrations** : `php bin/migrate.php --dry-run`
4. **Application réelle des migrations** : `php bin/migrate.php`
5. **Détermination de l'environnement** :
   - `main` → `production`
   - Autres branches → `staging`
6. **Construction du JSON de version** :
   ```json
   {
     "sha": "<git-sha>",
     "ref": "<branch-name>",
     "environnement": "<env>",
     "schemaVersion": <from-base>,
     "deployedAt": "<iso-8601>"
   }
   ```
7. **Publication sur `deployed`** :
   - Checkout branche `deployed`
   - Écrire `<env>/version.json` sans écraser l'autre environnement
   - Commit + push avec `--force-with-lease`

**Hotspot identifié** :
- Le workflow écrit `deployed/<env>/version.json` sur la branche `deployed`
- Le code lit `deployed-version.json` à la racine du projet servi
- **Un mécanisme externe** (hébergement, webhook) doit copier le fichier
- Si ce maillon est absent → `/version` retournerait des champs nuls

## Tests — `tests/OrdersTest.php`

**Type** : PHPUnit avec base temporaire.

**Stratégie** :
- Une base temporaire est créée par test (via `SVC_DB_PATH`)
- Les migrations sont appliquées à chaque test (depuis `migrations/001_init.sql`)
- Les tests ne dépendent jamais de l'état de `data/app.db` versionné

**Couverture actuelle** :
- `testListeToutesLesCommandes` → vérifie 5 lignes retournées et triées
- `testTrouveUneCommandeParIdentifiant` → vérifie le détail d'une commande
- `testIdentifiantInconnuRenvoieNull` → vérifie `find()` retourne null

**Zones non testées** :
- Routeur HTTP (dispatching, codes de réponse)
- Migrateuor (sauvegarde, transactions, rollback)
- Comportement de `/version` avec et sans fichier
- Erreurs PDO

## Points de vigilance globaux

### ✓ Respecter la simplicité plate

Pas de framework, pas d'injection de dépendances. Rester lisible en une lecture rapide.

### ✓ Sauvegarde = avant mutation

Toute application de migration commence par une copie horodatée.

### ✓ Version servie = jamais déduite du code

L'endpoint `/version` lit un fichier externe ou la base, jamais le Git.

### ✓ Distinction staging/production = deux fichiers

Deux environnements coexistent sur la branche `deployed` ; la promotion `staging → main` est humaine.

### ✓ Transactions par migration

Chaque fichier SQL s'exécute seul dans sa transaction; l'erreur d'un ne casse pas les antérieurs.

## Chemins de référence rapide

| Concept | Fichier | Ligne(s) | Note |
|---------|---------|----------|------|
| Lecture de la version servie | `public/index.php` | 16-19 | Chaînon critique : réception du fichier externe |
| Distinction staging/production | `.github/workflows/deploy.yml` | 40+ | Environnement déterminé par branche |
| Sauvegarde avant migration | `bin/migrate.php` | 50-59 | Obligatoire, non optionnelle |
| Lecture de schemaVersion | `src/Db.php` | 25-32 | Jamais supposé, toujours interrogé |
| Endpoints HTTP | `public/index.php` | 21-47 | 4 routes : health, version, orders, orders/{id} |
| Données de test | `migrations/001_init.sql` | 15-20 | 5 lignes fictives, toujours stables |

## Références complètes

- **CDC_FONCTIONNEL.md** — contrats des endpoints, règles métier, modèle de données
- **PROJECT_CONTEXT.md** — contexte projet, points d'attention, hypothèses
- **PLAN_DE_RECETTE.md** — parcours de test end-to-end
- **README.md** — présentation générale, développement local
- **ARCHITECTURE.md** — analyse détaillée de chaque domaine technique
