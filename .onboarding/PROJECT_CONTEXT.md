# PROJECT_CONTEXT — shift-pilot-svc

> **Confiance** : high  
> **Dernière mise à jour** : 2026-08-08  
> **Base de preuve** : domaines validés, audits complets, trois workflows documentés

## Résumé exécutif

**shift-pilot-svc** est un micro-service PHP produisant le **banc d'essai de la chaîne d'onboarding SHIFT/Paperclip** — le seul dépôt pilote réellement servi, avec persistance, migrations de schéma SQLite et canal de déploiement.

### Nature et portée

- **Rôle** : Service HTTP en lecture seule, exposant quatre endpoints JSON sur une base SQLite versionnée
- **Cas d'usage** : Valider trois scénarios que les autres dépôts pilotes ne peuvent pas porter (persistance, migrations, déploiement multi-environnement)
- **Cycle de vie** : 
  - Développement local : tests PHPUnit + serveur de développement
  - Staging : déploiement autonome après fusion, branche `staging`
  - Production : promotion manuelle depuis staging, branche `main`
- **Infrastructure** : GitHub Actions (CI/CD), SQLite local/CI, deux canaux environnementaux distincts

## Domaines clés et responsabilités

| Domaine | Responsabilité | Criticité | Point d'entrée |
|---------|---|---|---|
| **API HTTP** | Service en lecture seule : quatre endpoints JSON (`/health`, `/version`, `/orders`, `/orders/{id}`) | Haute | `public/index.php` |
| **Persistance** | Base SQLite versionnée (`data/app.db`) ; 5 commandes fictives initiales (Heiata, Teiki, Manoa, Vaite, Moana) avec champs `id`, `client`, `montant_cents`, `devise`, `statut` | Moyenne | `src/Db.php`, `src/Orders.php` |
| **Migrations** | Application atomique de schémas via `bin/migrate.php` ; dry-run obligatoire en CI ; sauvegarde prérequis | Haute | `bin/migrate.php` |
| **Déploiement** | Publication sur `deployed` après succès CI ; deux environnements isolés (`staging`, `production`) | Haute | `.github/workflows/deploy.yml` |
| **Tests** | Suite PHPUnit : 5 tests couche Orders (`all()`, `find()` nominal/absent, montants, version) ; dry-run migrations en CI | Moyenne | `tests/OrdersTest.php`, `.github/workflows/ci.yml` |

## Points d'attention

### 1. Deux canaux de déploiement distincts — distinction est le sujet
- **`staging`** (branche `staging`) : environnement de test autonome. Agents et développeurs y fusionnent directement après validation.
- **`main`** (branche `main`) : production. Promotion manuelle depuis `staging` — geste **hors chaîne d'agents**. Maintenance et Production préparent, vérifient et **passent la main**.
- **Confondre les deux = l'échec que ce dépôt sert à détecter.**

### 2. Version servie : deux fichiers distincts, l'un publié, l'autre servi
**Distinction critique** :
- **Artefact publié** : `deploy.yml` écrit un `version.json` sur la branche Git `deployed` après chaque push réussi (source de vérité du CI/CD) :
  - `deployed/staging/version.json` (après push sur `staging`)
  - `deployed/production/version.json` (après push sur `main`)
- **Fichier servi** : `public/index.php` lit `deployed-version.json` depuis le disque du serveur hébergé (source de vérité de la version **réellement servie**).

**Ces deux fichiers ne sont pas le même.** `deployed-version.json` doit être alimenté par un mécanisme externe (webhook, script serveur, hébergeur) qui copie de `deployed/<env>/version.json` (branche) vers `deployed-version.json` (disque servi).

Champs : `sha`, `ref`, `environnement`, `schemaVersion` (lu en base post-migration), `deployedAt` (UTC).

**Si un déploiement n'aboutit pas** (tests rouges, migration échoue), le fichier `deployed/<env>/version.json` sur la branche reste inchangé — l'absence de nouvelle version se voit. Ce qui est servi sur l'hôte dépend de si/quand `deployed-version.json` a été copié (hors dépôt).

### 3. Persistance par migration, pas par mise à jour manuelle
- `data/app.db` est **versionné** — état de départ des migrations.
- `bin/migrate.php` applique les fichiers SQL de `migrations/` en ordre numérique.
- Chaque migration s'exécute dans sa propre transaction ; une sauvegarde horodatée est créée obligatoirement avant toute exécution.
- En CI (`deploy.yml`), les migrations sont appliquées mais **pas commitées dans le dépôt** — `data/app.db` reste l'état « avant migrations » à chaque checkout.

### 4. Infrastructure de déploiement : le chaînon manquant
- Tout ce dépôt peut prouver : `deploy.yml` publie `deployed/<env>/version.json` sur la branche `deployed`.
- **Hors dépôt** : le mécanisme qui alimente `deployed-version.json` sur l'hôte servi (hébergement, webhook, script serveur, mécanism de fichiers).
- Conséquence : `/version` renvoie des champs `sha`/`ref`/`deployedAt` à `null` si ce maillon n'est pas mis en place côté production.

### 5. Fragmentation de la couverture de test
- **Tests présents** : couche Orders (`tests/OrdersTest.php`) — cas nominaux des quatre endpoints.
- **Tests absents** : routeur (`public/index.php`), migrateur (`bin/migrate.php`), gestion des erreurs HTTP, valeurs null en JSON.

## Données — vue métier

### Notion : Commande (Order)
- **État** : Présente avec un identifiant, un client, un montant en centimes, une devise, un statut de paiement.
- **Cycle de vie** : Création via insertion dans `orders` par `001_init.sql` lors de la migration initiale ; persistée jusqu'à suppression (jamais pratiquée) ou rollback migration.
- **Attributs** : `id` (INTEGER PRIMARY KEY), `client` (TEXT), `montant_cents` (INTEGER, >0), `devise` (TEXT, défaut 'XPF'), `statut` (TEXT, ex: 'payee' ou 'annulee')
- **Valeur métier** : Ensemble de 5 commandes fictives et immuables : Heiata (420000 XPF payée), Teiki (180000 XPF annulée), Manoa (960000 XPF payée), Vaite (305000 XPF payée), Moana (75000 XPF annulée)

## Intégrations externes — toutes hors dépôt

- **Serveur web de production** : héberge la version servie, dépose `deployed-version.json`, draine `/health` et `/version` pour le monitoring.
- **GitHub Actions** : exécuteur CI/CD, public permissions pour pousser sur `deployed`.
- Aucun service externe consommé (API, base externe, CDN).

## Hypothèses et délimitations

### Hypothèses
- PHP 8.1+ disponible en local et CI
- Composer opérationnel
- Git accessible (clone, push, fetch sur `origin`)
- GitHub Actions exécuteur sans problème réseau

### Hors périmètre
- Authentification / autorisation (endpoints sans contrôle d'accès)
- Chiffrement des données
- Monitoring et alertes en production
- Plan de disaster recovery (sauvegardes pérennes)
- Sauvegardes externes (sauvegardes dans `data/backups/` sont éphémères et CI, jamais archivées)

### Inachevé ou à préciser
- **Mécanisme de dépôt de `deployed-version.json`** : hors dépôt, à documenter côté hébergement
- **`data/app.db` post-migration** : versionnement et engagement des migrations futures à clarifier
- **Tests de sécurité** : aucun audit de sécurité application-level réalisé (vérifier `SECURITY_ROBUSTNESS_AUDIT.md`)

## Schéma d'architecture — couches plates

```
Développeur / CI
        ↓ push
GitHub Actions (ci.yml / deploy.yml)
        ↓
    PHP 8.1
        ↓
  public/index.php (routeur)
        ↓
   ├─ /health → retourne `{"status": "ok"}`
   ├─ /version → lit deployed-version.json
   ├─ /orders → Orders::getAll()
   └─ /orders/{id} → Orders::getById($id)
        ↓
   src/Db.php (connexion SQLite)
        ↓
   data/app.db (base SQLite versionnée)
        ↓
   migrations/*.sql (applicateurs de schéma)
```

Aucune couche intermédiaire (ORM, cache, service bus) — appels directs de base.

## Points critiques à surveiller en recette

1. **Cycle migration local** : Tester `composer migrate:dry` et `composer migrate` en local, vérifier sauvegarde et rollback.
2. **Deux canaux CI isolés** : Simuler un push sur `staging` et vérifier que `deployed/staging/version.json` change **sans affecter** `deployed/production/version.json`.
3. **Endpoints réels** : Appeler les quatre endpoints localement et s'assurer que les réponses JSON sont conformes.
4. **Déploiement e2e** : Push sur staging, attendre CI, vérifier `version.json` mis à jour sur `deployed`.
5. **Absence de `deployed-version.json`** : Vérifier que `/version` retourne `null` en local (le fichier n'existe pas).

---

**Preuves citées** :
- `.onboarding/domaines/CARTE_DES_DOMAINES.md` — six domaines du micro-service
- `.onboarding/workflows/WORKFLOW_SERVICE.md` — quatre endpoints
- `.onboarding/workflows/WORKFLOW_MIGRATION.md` — mécanisme de migration, sauvegarde, transactions
- `.onboarding/workflows/WORKFLOW_DEPLOIEMENT.md` — deux canaux, publication `version.json`
- README.md — contexte, migrations, développement
