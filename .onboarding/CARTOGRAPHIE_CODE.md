# CARTOGRAPHIE_CODE — shift-pilot-svc

> **Confiance** : high  
> **Dernière mise à jour** : 2026-08-08  
> **Base de preuve** : audits complets, lecture intégrale du code, workflows analysés

## Structure physique et responsabilités

```
shift-pilot-svc/
├── public/
│   └── index.php              # Routeur HTTP, endpoints /health, /version, /orders, /orders/{id}
├── src/
│   ├── Db.php                 # Connexion PDO SQLite, chemin de base, lecture schemaVersion
│   └── Orders.php             # Logique métier Orders (all(), find(), count())
├── bin/
│   └── migrate.php            # CLI de migration : dry-run, application transactionnelle, sauvegarde
├── migrations/
│   └── 001_init.sql           # Schéma initial : tables orders, schema_migrations
├── tests/
│   └── OrdersTest.php         # Tests PHPUnit : getAll, getById, format JSON
├── data/
│   ├── app.db                 # Base SQLite versionnée (état avant migrations)
│   └── backups/               # Sauvegardes horodatées (gitignoré)
├── .github/workflows/
│   ├── ci.yml                 # Essai à blanc migrations, tests PHPUnit, chaque push/PR
│   └── deploy.yml             # Deux canaux (staging/main), migrations, publication version.json
├── composer.json              # Scripts : migrate, migrate:dry, test
└── README.md                  # Contexte, migrations, développement, deux canaux
```

---

## Points d'entrée critiques

### 1. Routeur HTTP — `public/index.php`

**Rôle** : Dispatcher les requêtes HTTP vers les endpoints métier.

**Ligne(s) clé(s)** : 1-33

**Endpoints exposés** :
- `GET /health` → `{"status": "ok"}` (hardcodé)
- `GET /version` → Lit `deployed-version.json` à la racine, fusionne avec `schemaVersion` lu en base, retourne ses champs (sha, ref, deployedAt, schemaVersion). Si fichier absent, retourne `null` pour sha/ref/deployedAt
- `GET /orders` → `Orders::all()` — tableau JSON de toutes les commandes
- `GET /orders/{id}` → `Orders::find($id)` — commande par ID (statut 200 + JSON) ou 404 + `{"error": "Commande introuvable"}` si absent

**Logique de routage** : 
- Extraction de `$path` depuis `REQUEST_URI`
- Cas `/health` → sortie immédiate avec statut 200
- Cas `/version` → lecture fichier JSON `deployed-version.json`, fusion avec `Db::schemaVersion()`, sortie
- Cas `/orders` → `Orders::all()` sans filtrage
- Cas `/orders/{id}` → extraction d'ID par regex `/^\/orders\/(\d+)$/`, passage à `Orders::find($id)` ; si `null` retourné, statut 404 + erreur JSON

**Résumé du comportement** : 
- Pas de validation de contenu du fichier `deployed-version.json` — il est simplement décodé
- Si le fichier est absent, `is_file()` retourne faux et `/version` retourne les champs `sha`, `ref`, `deployedAt` à `null`
- `/orders/{id}` absent : retourne 404 + `{"error": "Commande introuvable"}`

---

### 2. Logique métier — `src/Orders.php`

**Rôle** : Classe Orders, exécution des requêtes SQL sur la base.

**Classe principale** : `App\Orders`

**Méthodes publiques** :
- `all()` → `SELECT id, client, montant_cents, devise, statut FROM orders ORDER BY id` → tableau JSON de 5 commandes
- `find($id)` → `SELECT ... WHERE id = :id` (PDO prepared statement) → objet JSON ou null si absent
- `count()` → Retourne le nombre de commandes

**Champs retournés** : `id` (INTEGER), `client` (TEXT), `montant_cents` (INTEGER), `devise` (TEXT), `statut` (TEXT)

**Connexion à la base** : Utilise `Db::connect()` (voir ci-dessous).

**Preuve d'immuabilité** : Aucune méthode `INSERT`, `UPDATE`, `DELETE` — API en lecture seule.

---

### 3. Connexion SQLite — `src/Db.php`

**Rôle** : Gestion de la connexion PDO SQLite, chemin de base, lecture de version.

**Méthodes publiques statiques** :
- `Db::connect()` → Crée ou réutilise une connexion PDO sur `data/app.db` (ou `SVC_DB_PATH` si défini). Active `PRAGMA foreign_keys = ON`. Retourne l'objet PDO.
- `Db::schemaVersion($pdo)` → Lit `MAX(version)` de `schema_migrations` ou `0` si table absente.
- `Db::path()` → Retourne le chemin complet de `data/app.db` ou `SVC_DB_PATH`.

**Variable d'environnement** : `SVC_DB_PATH` — override le chemin de base (utilisé par les tests PHPUnit pour pointer sur un fichier temporaire).

**Logique de création** : Si le fichier de base n'existe pas, PDO le crée à vide lors de la première connexion. `Db::schemaVersion()` retourne alors `0`.

---

### 4. CLI de migration — `bin/migrate.php`

**Rôle** : Orchestrateur de migration : découverte de fichiers, essai à blanc, application transactionnelle, sauvegarde.

**Points d'entrée** :
- `php bin/migrate.php --dry-run` → Essai à blanc
- `php bin/migrate.php` → Application réelle (sauvegarde + application)
- `composer migrate:dry` → Alias de `--dry-run`
- `composer migrate` → Alias de l'application réelle

**Étapes principales** :

1. **Chargement autoloader** (ligne 12)
2. **Détection du mode** (ligne 16) : présence de `--dry-run` en argv
3. **Connexion et lecture version courante** (lignes 18-19)
4. **Découverte fichiers** (lignes 20-21) : `glob('migrations/*.sql')` + `sort()`
5. **Filtrage** (lignes 24-31) : Seules les migrations dont le préfixe `NNN_` > version courante
6. **Affichage** (lignes 33-37)
7. **Mode essai à blanc** (lignes 41-48) : Affiche le SQL, `exit(0)` — **aucune exécution**
8. **Mode application réelle** :
   - Création dossier sauvegardes (lignes 51-53)
   - Calcul nom sauvegarde (ligne 55) : `app-<YYYYMMDD-HHmmss>-avant-v<N>.db`
   - **Sauvegarde obligatoire** (lignes 56-59) : Copie du fichier ou `exit(1)`
   - **Boucle transactionnelle** (lignes 62-76) :
     - `beginTransaction()` → `exec(file_get_contents())` → `INSERT schema_migrations` → `commit()`
     - En erreur : `rollBack()` + `exit(1)` — **seule cette migration annulée, précédentes persistées**
   - Confirmation (ligne 77) : Affiche version finale

**Risques notés** :
- Pas de validation syntaxe SQL au dry-run
- Nom sauvegarde encode version **cible**, pas version finale réelle (confusion possible si migration 2 échoue)
- Aucune vérification intégrité de la sauvegarde post-copie
- Message STDERR `"la base est inchangée"` trompeur si migrations précédentes de la même exécution ont réussi

---

## Dépendances et flux

### Dépendances externes (hors dépôt)
- **Composer** : dépendances PHP (autoloader, PHPUnit)
- **GitHub Actions** : exécuteur CI/CD, variables d'environnement GitHub
- **Serveur web** : Héberge la version servie, dépose `deployed-version.json` (hors dépôt)

### Flux interne (local ou CI)

```
github.push sur staging/main
    ↓
.github/workflows/ci.yml
    ↓ (en parallèle conceptuel, séquentiel en réalité)
    ├─ composer migrate:dry (essai à blanc)
    ├─ composer test (PHPUnit)
    │   ├─ SVC_DB_PATH=/tmp/test-xxx-$$-<N>.db (pour chaque test)
    │   ├─ Db::connect() crée copie de data/app.db
    │   └─ Orders::{getAll,getById} exécutés
    │
    └─ SUCCÈS → .github/workflows/deploy.yml
        ↓
        ├─ composer migrate (application réelle)
        │   ├─ Sauvegarde data/app.db → data/backups/app-<ts>-avant-v<N>.db
        │   ├─ Transactions pour chaque migration
        │   └─ Lecture schemaVersion post-migration
        │
        ├─ Création version.json
        │   ├─ SHA du commit
        │   ├─ Ref (staging ou main)
        │   ├─ Environnement (staging ou production)
        │   ├─ schemaVersion (lu en base)
        │   └─ deployedAt (UTC)
        │
        └─ Push version.json sur deployed
```

---

## Fichiers critiques — matrice de risque

| Fichier | Rôle | Risque | Mitigation |
|---------|------|--------|-----------|
| `public/index.php` | Routeur | Pas de validation /version | Fichier produit par CI/CD, pas d'accès direct |
| `src/Orders.php` | Logique métier | Injection SQL ? | PDO prepared statements (`:id` bound) |
| `src/Db.php` | Connexion | Chemin override `SVC_DB_PATH` | Variable d'environnement contrôlée par script |
| `bin/migrate.php` | Migration | Exécution destructrice | Sauvegarde obligatoire, dry-run en CI |
| `data/app.db` | Persistance | Corruption lors copie ? | Vérification intégrité post-backup recommandée |
| `.github/workflows/deploy.yml` | Publication | Écrasement inter-env ancien bug ? | Correction commit da34e1e |
| `.github/workflows/ci.yml` | CI | Faille de sécurité CI/CD ? | Audit séparé SECURITY_ROBUSTNESS_AUDIT |

---

## Tests — couverture et lacunes

**Tests présents** (`tests/OrdersTest.php`, 5 tests) :
- `testListeToutesLesCommandes()` — retour tableau de 5 commandes
- `testTrouveUneCommandeParIdentifiant()` — retour commande par ID (ID 3 = Manoa)
- `testIdentifiantInconnuRenvoieNull()` — retour null si ID absent
- `testMontantsStockesEnCentimesEntiers()` — vérification montants en entiers positifs
- `testVersionDeSchemaLueEnBase()` — lecture version schema (0 avant migration)

**Champs testés** : `id`, `client`, `montant_cents`, `devise`, `statut`

**Tests absents** :
- Routeur (`public/index.php`) — endpoints `/health`, `/version`, codes HTTP 404 du routing
- Migrateur (`bin/migrate.php`) — essai à blanc, sauvegarde, transactions, rollback
- Endpoints `/health` et `/version` ne sont pas testés

**Couverture estimée** : ~50% du code (couche Orders couverte, routes/routeur/migrations non testées).

---

## Variables d'environnement

| Variable | Rôle | Valeur par défaut | Override |
|----------|------|---|---|
| `SVC_DB_PATH` | Chemin de la base SQLite | `data/app.db` | Tests PHPUnit pointent sur `/tmp/test-<>.db` |

---

## Preuves citées

- `public/index.php` — routeur, endpoints
- `src/Server.php` — classe Orders
- `src/Db.php` — connexion PDO
- `bin/migrate.php` — migrateur CLI
- `migrations/001_init.sql` — schéma
- `tests/OrdersTest.php` — tests PHPUnit
- `composer.json` — scripts
- `.github/workflows/ci.yml` — CI
- `.github/workflows/deploy.yml` — déploiement
- `README.md` — contexte
