# Architecture — shift-pilot-svc

## Vue d'ensemble

shift-pilot-svc est un micro-service PHP minimaliste de moins de 300 lignes de code source. 
L'architecture est intentionnellement plate — pas de framework, pas de conteneur d'injection de 
dépendances, pas de couches intermédiaires. Cette platitude rend le code lisible d'un coup d'œil 
et minimise la surface d'attaque liée aux dépendances.

### Structure du projet

```
shift-pilot-svc/
├── bin/
│   └── migrate.php          (77 lignes) — Migrateur SQL CLI
├── src/
│   ├── Db.php               (34 lignes) — Couche PDO/SQLite
│   └── Orders.php           (30 lignes) — Domaine métier (requêtes sur orders)
├── public/
│   └── index.php            (47 lignes) — Routeur HTTP
├── migrations/
│   └── 001_init.sql         — Schéma initial + données de test
├── tests/
│   └── OrdersTest.php       — PHPUnit (couverture partielle)
├── data/
│   ├── app.db               (12 KB) — Base SQLite versionnée
│   └── backups/             — Sauvegardes horodatées (.gitignore)
├── composer.json            — Dépendances (phpunit dev uniquement)
└── .github/workflows/
    ├── ci.yml               — Tests et essai à blanc sur PR/push
    └── deploy.yml           — Déploiement automatisé sur deployed
```

## Domaines et responsabilités

### 1. Routage HTTP (`public/index.php`)

**Responsabilité** : dispatcher les requêtes aux endpoints, formater les réponses JSON.

**Caractéristiques** :
- Aucun framework — parsage manuel du `REQUEST_URI`
- Quatre endpoints : `GET /health`, `GET /version`, `GET /orders`, `GET /orders/{id}`
- Toutes les réponses sont JSON (`Content-Type: application/json`)
- Les erreurs HTTP (404) retournent un JSON avec `error` et `code`

**Hotspot** : `public/index.php:11` — **la connexion SQLite est ouverte avant le routage**.
Cela signifie que la sonde `/health` dépend de la disponibilité de la base. Par conception,
c'est peut-être intentionnel (deep health check), mais ça n'est pas documenté en code.

**Autre hotspot** : `public/index.php:16-19` — **lecture de `deployed-version.json`**.
Ce fichier n'est pas produit par le dépôt ; le pipeline écrit sur la branche `deployed`,
pas à la racine du projet servi. Si ce maillon est absent, `/version` retourne des champs `null`.

**Risque** : absence de `try/catch` dans le routeur — toute exception non attrapée s'échappe
et sa réponse dépend de la configuration PHP (`display_errors`), qui est hors du dépôt.
Recommendation : envelopper le corps du routeur dans un `try/catch` qui retourne une réponse
JSON cohérente pour les erreurs.

### 2. Couche SQLite (`src/Db.php`)

**Responsabilité** : connexion PDO, lecture du schéma version, exécution de requêtes.

**Caractéristiques** :
- Wrapper minimaliste autour de PDO/SQLite
- Chemin de base configurable via `SVC_DB_PATH` (défaut : `data/app.db`)
- `foreign_keys = ON` activé à chaque connexion (prêt pour des contraintes étrangères futures)
- `schemaVersion()` lit `MAX(version)` depuis `schema_migrations` (retourne 0 si table absente)

**Configuration** :
```php
// Chemin de base — override par SVC_DB_PATH pour les tests
$dbPath = getenv('SVC_DB_PATH') ?: dirname(__DIR__) . '/data/app.db';
```

**Utilisation dans les tests** :
```bash
# En local
php bin/migrate.php
php -S 127.0.0.1:8080 -t public

# Avec une base temporaire (PHPUnit)
SVC_DB_PATH=/tmp/test.db vendor/bin/phpunit
```

### 3. Domaine métier (`src/Orders.php`)

**Responsabilité** : requêtes sur la table `orders` — `all()`, `find()`.

**Caractéristiques** :
- Deux méthodes : `Orders::all()` retourne toutes les commandes (triées par id)
- `Orders::find($id)` retourne une commande par identifiant ou `null`
- Pas de filtre, pas de pagination (scope: données fictives de test)

**Schéma de données** :
```
orders(
  id INTEGER PRIMARY KEY,
  client TEXT NOT NULL,
  montant_cents INTEGER NOT NULL,
  devise TEXT NOT NULL DEFAULT 'XPF',
  statut TEXT NOT NULL
)
```

**Points d'attention** :
- `montant_cents` est un entier (pas de float → pas de risque d'arrondi)
- `statut` n'a pas de contrainte CHECK — accepte toute valeur textuelle
- `devise` n'a pas de contrainte CHECK — accepte toute valeur textuelle

### 4. Migrateur SQL (`bin/migrate.php`)

**Responsabilité** : appliquer des migrations SQL en toute sécurité avec sauvegarde et
transactions.

**Modes** :
- `--dry-run` : affiche le SQL sans l'exécuter
- Sans flag : sauvegarde + exécution transactionnelle

**Hotspot** : `bin/migrate.php:24-30` — **construction de `$aFaire` à partir des noms de fichier**.
Trois problèmes distincts :
1. **Tri lexicographique, pas numérique** : un fichier `10_*.sql` s'applique avant `002_*.sql`
2. **Pas de validation de préfixe** : un fichier mal nommé (`foo_*.sql`) est silencieusement ignoré
3. **Collision silencieuse** : deux fichiers avec le même préfixe (ex. `002_a.sql` et `002_b.sql`)
   font que le premier disparaît sans avertissement

**Convention non vérifiée** : nommer les fichiers `NNN_*.sql` avec trois chiffres (`001`, `002`, …).
Le script n'applique pas cette convention — seulement la suppose pour le tri.

**Autre hotspot** : `bin/migrate.php:67` — **message opérationnel trompeur** « la base est inchangée »
après un échec. Techniquement trompeur : les migrations antérieures de la même exécution restent
appliquées (seule la migration courante est annulée par rollback). Le message ne tient pas compte
de cet état partiel.

### 5. Déploiement automatisé (`.github/workflows/deploy.yml`)

**Responsabilité** : tests + migrations + publication sur la branche `deployed`.

**Pipeline** :
1. Checkout, setup PHP
2. Tests (`composer test`)
3. Dry-run des migrations (pour visibilité uniquement)
4. Application réelle des migrations
5. Détermination de l'environnement (`main` → `production`, `staging` → `staging`)
6. Écriture de `<env>/version.json` sur la branche `deployed`
7. Push de la branche `deployed` à `origin`

**Hotspot** : `deploy.yml:35-36,69` — **publication sans vérification post-push**.
Le workflow n'effectue pas de `GET /version` pour confirmer que la version servie est
accessible et correcte après publication. Un problème de dépôt de `deployed-version.json` sur
l'hôte serait invisible dans les logs CI.

**Autre hotspot** : **mécanisme de dépôt de `deployed-version.json` absent du dépôt**.
Le pipeline écrit sur la branche `deployed` ; un mécanisme externe (hébergement, webhook,
script post-deploy) doit copier ce fichier à la racine du projet servi. Sans ce maillon,
`/version` retourne `sha: null, ref: null, deployedAt: null`.

## Dépendances

**Production** : aucune (extensions PHP natives `pdo` et `pdo_sqlite` uniquement).

**Développement** :
- `phpunit/phpunit` — tests unitaires

```json
// composer.json — les dépendances sont explicitement minimales
{
  "require": {},
  "require-dev": {
    "phpunit/phpunit": "^11.0"
  }
}
```

## Flux de données

```
GET /orders
    ↓
public/index.php:1 (Db::connect() — ouvre SQLite)
    ↓
public/index.php:38 (Orders::all())
    ↓
src/Orders.php:14 (SELECT ... FROM orders)
    ↓
public/index.php:39 (echo json_encode(...))
```

## Zones critiques

### A. Connexion avant routage (`public/index.php:11`)

Une `PDOException` levée par `Db::connect()` échappe sans interception. Toute requête,
y compris `/health`, lève une exception si SQLite est inaccessible.

**Recommandation** : soit déplacer `Db::connect()` après le case `/health`, soit envelopper
le routeur dans un `try/catch`.

### B. Lecture de `deployed-version.json` (`public/index.php:16-19`)

```php
$version = @json_decode(file_get_contents('deployed-version.json'), true) ?? [];
```

Trois problèmes :
1. **Suppression d'erreur avec `@`** — masque les avertissements mais reste lisible
2. **`json_decode` peut retourner `null` si le JSON est invalide** — la fusion avec `+` alors
   opère sur un tableau et un `null`, production d'une `TypeError` en PHP 8+
3. **Fallback à un tableau vide** — si le fichier n'existe pas, les champs retournés valent `null`
   (comportement déduit via `+` operator)

**Recommandation** :
```php
$v = @json_decode(file_get_contents('deployed-version.json'), true);
$version = is_array($v) ? $v : ['sha' => null, 'ref' => null, 'deployedAt' => null];
```

### C. Priorité de `schemaVersion` dans `/version` (`public/index.php:27`)

```php
return $version + ['schemaVersion' => Db::schemaVersion($pdo)];
```

L'opérateur `+` PHP conserve la clé du tableau gauche si elle existe. Si `deployed-version.json`
contient un `schemaVersion`, c'est cette valeur qui est retournée, jamais celle de la base live.

**Intention** : la version déployée (fichier) fait foi, pas la base live. Cela peut induire une
désynchronisation silencieuse si la base est modifiée hors déploiement.

**Recommandation** : documenter explicitement ce comportement en commentaire.

### D. Base versionnée et déploiement CI (`deploy.yml:35-36` + `data/app.db`)

Le pipeline applique les migrations en CI mais ne commite pas `data/app.db` modifié. À la
deuxième migration, la base versionnée sera en retard d'une version par rapport au schéma
appliqué lors du déploiement.

**Stratégie actuelle** : la base versionnée = état zéro des migrations. Toutes les migrations
s'appliquent à chaque déploiement.

**Risque** : après un déploiement qui inclut la migration 2, un checkout donnera une base à v1.
Décalage possible entre `data/app.db` et la présence de `002_*.sql`.

## Points d'extension

Si le service est amené à évoluer, les points suivants seraient les plus impactés :

1. **Ajout d'endpoints avec logique complexe** → introduire une couche métier/use cases
2. **Filtres ou pagination sur `/orders`** → ajouter des paramètres de query
3. **Écriture via l'API** → nécessiter l'authentification + autorisation ; respecter les
   contraintes de transactions atomiques
4. **Contraintes CHECK sur `statut`/`devise`** → ajouter une migration, vérifier que les données
   existantes sont compatibles
5. **Index sur colonnes fréquemment filtrées** → ajouter via une migration si `/orders?statut=x`
   est implémenté

## Recette de sécurité — points à ne pas casser

- [ ] **Distinction staging/production** : deux fichiers de version coexistent sur `deployed`
      sans s'écraser
- [ ] **Sauvegarde obligatoire** : `bin/migrate.php` refuse d'appliquer des migrations sans
      copie réussie
- [ ] **Transactions atomiques** : chaque migration s'exécute dans `beginTransaction`/`commit`/`rollBack`
- [ ] **Tests avant déploiement** : le pipeline `deploy.yml` exécute `composer test` avant les
      migrations et la publication
- [ ] **`--force-with-lease` sur `deployed`** : protège contre les pushes concurrents

## Pour aller plus loin

- `README.md` — vue d'ensemble pour développeurs
- `GUIDE_MIGRATIONS.md` — procédure complète pour ajouter une migration
- `GUIDE_DEPLOIEMENT.md` — procédure de déploiement et récupération des versions
- `.github/workflows/deploy.yml` — code du pipeline
- `bin/migrate.php` — détails techniques du migrateur SQL
