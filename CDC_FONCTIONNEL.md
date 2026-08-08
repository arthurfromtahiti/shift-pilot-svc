# Cahier des charges fonctionnel — shift-pilot-svc

## Résumé exécutif

shift-pilot-svc expose **quatre endpoints HTTP en lecture seule** qui retournent du JSON. Le service interroge une base SQLite versionnée pour consulter des commandes de test. Aucune écriture via l'API ; toute mutation de schéma passe par les migrations. L'objectif n'est pas fonctionnel (données fictives) mais opérationnel : démontrer une **version servie observable** et la **distinction entre deux canaux de déploiement**.

## Endpoints et contrats

### `GET /health` — Sonde de santé

**Description** : Vérifier que le service est vivant.

**Réponse** (200 OK) :
```json
{
  "status": "ok"
}
```

**Comportement** :
- Retourne `{"status":"ok"}` si le service atteint ce point
- **Important** : cette sonde dépend de la disponibilité de la base SQLite
  - Si la connexion PDO échoue, une exception s'échappe sans être attrapée
  - Behavior HTTP exact (500 vs 503 vs 200) dépend de la configuration PHP sur l'hôte
- Contenu-type : `application/json`

**Cas d'erreur** :
- Panne SQLite → `PDOException` non attrapée → réponse HTTP dépend de la config PHP (hôte)

### `GET /version` — Afficher la version déployée

**Description** : Consulter la version servie en ce moment — SHA déployé, environnement, schéma appliqué, horodatage.

**Réponse** (200 OK, avec fichier `deployed-version.json`) :
```json
{
  "sha": "17ae996ea128bea1876cacb5f08d4c76f1fc6e46",
  "ref": "staging",
  "environnement": "staging",
  "deployedAt": "2026-08-08T06:37:00Z",
  "schemaVersion": 1
}
```

**Réponse** (200 OK, sans fichier `deployed-version.json`) :
```json
{
  "sha": null,
  "ref": null,
  "deployedAt": null,
  "schemaVersion": 1
}
```
**Note** : En l'absence du fichier, la clé `environnement` **n'existe pas** (pas créée par le fallback de `index.php:19`).

**Comportement** :
- Champs `sha`, `ref`, `deployedAt` sont lus depuis le fichier `deployed-version.json` à la racine (code `index.php:17-19`)
  - Jamais déduits du code Git courant
  - Si ce fichier n'existe pas → ces trois champs valent `null`, et `environnement` est **absent**
  - Si ce fichier existe → tous les champs du fichier sont retournés as-is
  
- `schemaVersion` est :
  - D'abord cherché dans `deployed-version.json` (si le fichier fournit ce champ)
  - Si absent du fichier → lu depuis la base (requête `MAX(version)` en table `schema_migrations`)
  - **Priorité** : le fichier prime sur la base live (permet une version servie stable)
  
- Contenu-type : `application/json`

**Cas d'erreur** :
- `deployed-version.json` absent → `sha/ref/deployedAt` valent `null`, `environnement` absent, `schemaVersion` lu depuis la base
- Panne SQLite lors de la lecture du schéma → `PDOException` non attrapée (pas de try/catch) → réponse HTTP dépend de la config PHP (ligne 11 de `index.php`)

**Chaînon critique** (hors dépôt) :
- Le pipeline écrit `deployed/<env>/version.json` sur la branche `deployed`
- Un mécanisme externe (hébergement, webhook, script de déploiement) doit copier ce fichier en `deployed-version.json` à la racine du projet servi
- **Si ce mécanisme est absent** → `/version` retournerait `sha/ref/deployedAt` nuls, `environnement` absent, et `schemaVersion` uniquement depuis la base

### `GET /orders` — Lister toutes les commandes

**Description** : Retourner l'intégralité des commandes de la base.

**Réponse** (200 OK) :
```json
[
  {
    "id": 1,
    "client": "Heiata",
    "montant_cents": 420000,
    "devise": "XPF",
    "statut": "payee"
  },
  {
    "id": 2,
    "client": "Teiki",
    "montant_cents": 180000,
    "devise": "XPF",
    "statut": "annulee"
  },
  {
    "id": 3,
    "client": "Manoa",
    "montant_cents": 960000,
    "devise": "XPF",
    "statut": "payee"
  },
  {
    "id": 4,
    "client": "Vaite",
    "montant_cents": 305000,
    "devise": "XPF",
    "statut": "payee"
  },
  {
    "id": 5,
    "client": "Moana",
    "montant_cents": 75000,
    "devise": "XPF",
    "statut": "annulee"
  }
]
```

**Caractéristiques** :
- **Ordre** : trié par identifiant ascendant (`ORDER BY id`)
- **Contenu de test** : 5 lignes fictives peuplées par `migrations/001_init.sql`
- **Aucun filtre, aucune pagination** : endpoint retourne toutes les commandes
- **Tous les champs** : `id`, `client`, `montant_cents`, `devise`, `statut` (correspondance exacte avec la table `orders`)
- **Montants en centimes** : `montant_cents` est un entier pour éviter les arrondis float
- **Devise** : défaut `XPF` en base ; pas de validation de format
- **Statut** : pas de liste énumérée ; accepte toute valeur textuelle
- Contenu-type : `application/json`

**Cas d'erreur** :
- Pas de cas d'erreur documenté ; succès (200) ou exception PDO non attrapée

**Hypothèse future** :
- Si pagination/filtre est ajoutée → accepter `?limit=10&offset=0` ou `?statut=payee`
- Si colonnes sont ajoutées → les refléter dans la réponse

### `GET /orders/{id}` — Détail d'une commande

**Description** : Retourner les données d'une commande par identifiant.

**Réponse** (200 OK) :
```json
{
  "id": 1,
  "client": "Heiata",
  "montant_cents": 420000,
  "devise": "XPF",
  "statut": "payee"
}
```

**Paramètre** :
- `{id}` : identifiant entier positif requis (1 à 5 pour les données initiales)
- Validation : regex `#^/orders/(\d+)$#` (ligne 35 de `public/index.php`)
  - Seulement des chiffres acceptés
  - Identifiants non entiers → 404 (route inconnue)

**Comportement** :
- Si `id` trouvé → 200 + JSON avec les 5 champs
- Si `id` introuvable → 404 + JSON d'erreur

**Réponse 404 Not Found** :
```json
{
  "error": "Commande introuvable"
}
```

**Cas d'erreur** :
- `/orders/abc` → 404 Route inconnue (regex ne match pas)
- `/orders/999` → 404 Commande introuvable (pas de ligne avec id=999)
- Panne SQLite → `PDOException` non attrapée → réponse HTTP dépend de config PHP

**Hypothèse future** :
- Si écriture est ajoutée → `PUT /orders/{id}` et `DELETE /orders/{id}` requiert authentification

## Modèle de données métier

**Entité** : `orders` — commande client fictive

| Colonne | Type | Nullable | Default | Notes |
|---------|------|----------|---------|-------|
| `id` | INTEGER | NO | (primary key) | Identifiant unique |
| `client` | TEXT | NO | — | Nom du client (fictif) |
| `montant_cents` | INTEGER | NO | — | Montant en centimes entiers (évite arrondis float) |
| `devise` | TEXT | NO | `'XPF'` | Devise de règlement (pas de validation) |
| `statut` | TEXT | NO | — | État de la commande : `'payee'` ou `'annulee'` (pas de CHECK) |

**Données actuelles** (depuis `migrations/001_init.sql:15-20`) :
```sql
INSERT INTO orders (id, client, montant_cents, devise, statut) VALUES
  (1, 'Heiata', 420000, 'XPF', 'payee'),
  (2, 'Teiki',  180000, 'XPF', 'annulee'),
  (3, 'Manoa',  960000, 'XPF', 'payee'),
  (4, 'Vaite',  305000, 'XPF', 'payee'),
  (5, 'Moana',   75000, 'XPF', 'annulee');
```

**Registre de schéma** (`schema_migrations`) :
| Colonne | Type | Notes |
|---------|------|-------|
| `version` | INTEGER | Numéro de la migration appliquée |
| `applied_at` | TEXT | Horodatage ISO 8601 UTC de l'application |

Actuellement : une seule ligne (`version: 1, applied_at: '2026-08-08T03:59:...'`).

## Règles métier

### 1. Distinction entre deux canaux de déploiement

- **Staging** : environnement de test automatisé. Les agents déploient eux-mêmes après approbation.
- **Production** : environnement cible. La promotion depuis staging est un geste humain hors chaîne d'agents.

**Règle** : Ne jamais automatiser la fusion sur `main` directement. C'est l'échec que ce banc d'essai sert à détecter.

### 2. Version servie observable

L'endpoint `/version` doit toujours refléter ce qui **est vraiment deployé**, jamais ce que le code souhaite.

**Règle** : 
- Avant d'appliquer une migration → ne rien écrire de nouveau version.json
- Après tests verts + migrations appliquées → publier une nouvelle version.json
- En cas d'échec (test rouge, migration échouée) → **ne pas toucher** version.json

### 3. Sauvegarde obligatoire avant mutation de base

Toute application de migrations doit :
1. Créer un répertoire `data/backups/` s'il n'existe pas
2. Copier `data/app.db` en `app-<YYYYMMDD-HHmmss>-avant-v<N>.db`
3. Refuser d'appliquer si la copie échoue

**Règle** : Pas d'exception — si la sauvegarde échoue, arrêt total, sorte avec code 1.

### 4. Transactions atomiques par migration

Chaque fichier de migration s'exécute dans sa propre transaction.

**Règle** :
- Succès → `COMMIT` ; la migration reste persistée
- Erreur → `ROLLBACK` de cette migration seulement ; les migrations antérieures restent appliquées
- Message d'erreur identifie le fichier et cite le chemin de la sauvegarde

### 5. Données fictives et immuables

Les 5 commandes de test sont des données d'aide au développement.

**Règle** : Ces données ne changent qu'explicitement via migration (ajout de lignes, suppression, changement de valeur). Aucun endpoint n'offre l'écriture ; les données restent stables et reproductibles.

## Garanties de service

| Garantie | Conséquence | Priorité |
|----------|-------------|----------|
| Tests passent avant déploiement | Aucun code rouge ne peut être publié en production | **Critique** |
| Migrations appliquées en CI avant publication | Version servie est cohérente avec le code | **Critique** |
| Version servie publiée sur déploiement réussi | Observabilité de ce qui est réellement en ligne | **Critique** |
| Distinction staging/production maintenue | Gouvernance de déploiement vérifiée par ce banc d'essai | **Critique** |
| Sauvegarde avant mutation de base | Possibilité de rebobinage manuel en cas d'urgence | **Élevée** |

## Points de vigilance futurs

Si le service évolue :

1. **Ajout d'endpoints** → réfléchir à la couche métier (use cases, logique applicative)
2. **Filtres/pagination sur `/orders`** → ajouter query-string ; documenter les limites
3. **Écriture via l'API** → authentification, autorisation, transactions distribuées
4. **Contraintes CHECK sur statuts/devises** → migration de validation + vérification des données existantes
5. **Index sur colonnes filtrées** → ajouter via migration si `/orders?statut=...` implémenté

## Modèle de contrat minimal

Tout endpoint **doit** :
- Retourner du JSON valide (`Content-Type: application/json`)
- Retourner un code HTTP approprié (200, 404, 500)
- En cas **d'erreur gérée** (ex. `/orders/{id}` absent) → retourner un JSON avec champ `error`
  - **Important** : Les exceptions PDO (pannes SQLite, requête invalide) **ne sont pas attrapées** → pas de JSON structuré, sorte dépend de la config PHP (lignes 11, 36 de `index.php`)
- Jamais inventer de version depuis le code (laisser `/version` en charge)
- Respecter l'ordre de lecture : fichier > base > null (pour `/version`)

## Références

- `CARTOGRAPHIE_CODE.md` — où trouver chaque fonctionnalité dans le code
- `PLAN_DE_RECETTE.md` — comment tester ces endpoints
- `src/Orders.php` — implémentation des requêtes sur `orders`
- `public/index.php` — routeur et endpoints
- `migrations/001_init.sql` — données initiales et schéma
