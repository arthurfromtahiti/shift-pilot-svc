# Contexte projet — shift-pilot-svc

## Vue d'ensemble

**shift-pilot-svc** est un micro-service HTTP en PHP conçu comme **banc d'essai opérationnel** pour le système Shift/Paperclip. Son objectif ne s'est pas de fournir une fonctionnalité métier, mais de **tester la distinction entre deux canaux de déploiement** (`staging` vs `main`) et de **démontrer l'observabilité de la version réellement servie**.

C'est **le seul dépôt pilote SHIFT réellement servi** : il persiste des données (base SQLite versionnée), exécute des migrations de schéma avec sauvegarde obligatoire, et publie une version servie observable sur une branche dédiée.

### Raison d'être

Le confondre les deux canaux de déploiement — laisser une chaîne d'agents fusionner directement sur `main` au lieu de respecter une promotion `staging → main` avec gestes manuels — est **l'échec précis** que ce dépôt sert à détecter. C'est un **test de gouvernance**, pas un test fonctionnel.

## Domaines clés

| Domaine | Rôle | Criticité | Détails |
|---------|------|-----------|---------|
| **Commandes** | Ressource métier unique (consultation seule) | Cœur | 4 endpoints HTTP qui exposent une table `orders` de 5 lignes fictives |
| **API HTTP** | Routeur, dispatching, réponses JSON | Support | Aucun framework ; parsage manuel en ~50 lignes de PHP |
| **Persistance** | Couche SQLite versionnée | Support | Base `data/app.db` commitée ; lecture du schéma appliqué |
| **Migrations** | Exécuteur SQL avec sauvegarde obligatoire | Support | Deux modes (dry-run, application réelle) ; transactions atomiques |
| **Déploiement & version servie** | Publication sur branche `deployed` | **Cœur** | Distinction staging/production ; version observée via `/version` |
| **CI/tests** | PHPUnit + essai à blanc des migrations | Support | Base temporaire reconstruite à chaque test |

## Points d'attention — ce qui peut casser

### 1. Distinction staging/production

**Le sujet du dépôt.** Deux canaux parallèles qui doivent rester distincts :

- **`staging`** (branche source) → tests + migrations appliquées → publication sur `deployed/staging/version.json`
  - Les agents déploient **eux-mêmes** après approbation
  - Environnement d'expérimentation autonome

- **`main`** (branche source) → tests + migrations appliquées → publication sur `deployed/production/version.json`
  - La promotion depuis `staging` est un **geste humain/manuel hors chaîne d'agents**
  - Maintenance et Production valident, **n'automatisent pas** cette promotion
  - Confondre avec `staging` invalidera le banc d'essai

**À ne pas casser** :
- [ ] Deux fichiers `version.json` coexistent sur la branche `deployed` sans s'écraser
- [ ] Un déploiement qui échoue (tests rouges, migration échouée) **n'écrit pas** de nouvelle version servie
- [ ] La distinction est vérifiée à l'exécution du workflow (`deploy.yml:40` ; branche détectée via `github.ref`)

### 2. Sauvegarde obligatoire avant migration

Les migrations appliquent des modifications SQL réelles à un fichier versionné. Avant toute mutation :

- **Créer `data/backups/`** s'il n'existe pas
- **Copier `data/app.db` en `app-<YYYYMMDD-HHmmss>-avant-v<N>.db`**
  - Horodatage UTC pour traçabilité
  - Si cette copie échoue → **arrêt total** (`exit 1` du script)
- **Exécuter les migrations en transaction**
  - Un échec → rollback de la migration courante seulement
  - Les migrations déjà commitées restent persistées
  - Le script cite le chemin de la sauvegarde dans le message d'erreur

**À ne pas casser** :
- [ ] `bin/migrate.php:91` refuse d'appliquer si la copie échoue
- [ ] Chaque migration s'enveloppe dans `beginTransaction`/`commit`/`rollBack`
- [ ] Les fichiers de sauvegarde ne sont **pas** versionnés (`.gitignore`)

### 3. Version servie observable

L'endpoint `GET /version` retourne la version **réellement déployée**, jamais déduite du code :

```json
{
  "sha": "...",
  "ref": "staging",
  "environnement": "staging",
  "schemaVersion": 1,
  "deployedAt": "2026-08-08T06:37:00Z"
}
```

**Chaînon critique** : le workflow écrit sur la branche `deployed` (`deployed/<env>/version.json`), mais le code lit un fichier `deployed-version.json` à la racine. Un mécanisme externe (hébergement, webhook, script) doit copier le fichier.

- **Si ce maillon est absent** → `/version` retournerait des champs `null` sans erreur HTTP
- **Implication** : on ne pourrait pas distinguer « déploiement en attente » de « pas de déploiement »

**À ne pas casser** :
- [ ] `/version` n'invente jamais la version depuis le code
- [ ] Si `deployed-version.json` est absent → champs `sha/ref/deployedAt` valent `null` ; `schemaVersion` est lu depuis la base
- [ ] L'opérateur `+` PHP conserve la priorité du fichier sur la base pour `schemaVersion`

### 4. Atomicité et isolation — pas de perte de données

- **Migrations transactionnelles** : chaque fichier SQL s'exécute dans sa propre transaction
  - Un fichier qui échoue ne laisse que lui-même rollback ; les migrations précédentes subsistent
  - Message d'erreur identifie précisément le fichier et cite la sauvegarde
  
- **Sauvegardes horodatées** : chaque exécution de migration crée un fichier `app-<timestamp>-avant-v<N>.db`
  - Permet de rebobiner manuellement en cas de besoin
  - Chaque tentative de déploiement peut être audité

**À ne pas casser** :
- [ ] Chaque migration a sa propre transaction
- [ ] Aucune migration n'est appliquée sans sauvegarde préalable

## Hypothèses et inconnues

### Hypothèses de conceptionvalidées par audit

| Hypothèse | Statut | Détails |
|-----------|--------|---------|
| `schemaVersion` du fichier fait autorité, pas celle de la base live | HYPOTHÈSE, DOCUMENTÉE | `public/index.php:27` utilise l'opérateur `+` ; la valeur du fichier prime. Permet que la version servie déclarée soit stable même si la base est modifiée hors déploiement. |
| `/health` dépend de SQLite | HYPOTHÈSE, NON DOCUMENTÉE | `public/index.php:11` ouvre la connexion avant le routage ; pas d'endpoint `/health` sans base. Si intentionnel (deep health check), à clarifier. |
| Méthodes HTTP autres que GET reçoivent les mêmes réponses | HYPOTHÈSE, CONDITIONNELLE | Le code ne filtre pas `REQUEST_METHOD`. Pour un service en lecture seule avec données fictives, c'est attendu. Si écriture est ajoutée, revoir. |

### Zones non observées (exécution non testée)

| Zone | Impact | Note |
|------|--------|------|
| Codes de réponse HTTP effectifs | Moyen | Le code est statiquement correct (404, 200, JSON) ; l'exécution réelle dépend de la configuration PHP sur l'hôte. |
| Comportement sous panne SQLite | Moyen | Une `PDOException` non attrapée s'échappe. Réponse HTTP = fonction de la configuration PHP (`display_errors`). |
| Déploiement de `deployed-version.json` | **Critique** | Mécanisme externe absent du dépôt. Si manquant → `/version` retournerait champs nuls sans alerte. |

## Checklist de non-régression

Avant de modifier ce service, vérifier que les points suivants restent vrais :

- [ ] Branches `staging` et `main` deployent indépendamment (deux `version.json` sur `deployed`)
- [ ] Un déploiement qui échoue n'écrase pas la version servie précédente
- [ ] `bin/migrate.php` sauvegarde la base avant toute mutation
- [ ] Migrations s'exécutent en transactions individuelles
- [ ] Tests passent avant déploiement (CI bloque la publication sur echec)
- [ ] `/version` ne déduit jamais la version du code, seulement du fichier ou de la base
- [ ] `--force-with-lease` est utilisé pour les push sur `deployed` (prévient les races)
- [ ] `data/backups/` n'est pas versionné (*.gitignore*) ; sauvegardes restent locales/CI

## Pour aller plus loin

- **CARTOGRAPHIE_CODE.md** — structure technique fichier par fichier, points critiques du code
- **CDC_FONCTIONNEL.md** — contrats des endpoints, cas d'erreur, règles métier (minimes)
- **CAHIER_RECETTE.md** — parcours de test end-to-end, scénarios de déploiement
- **ARCHITECTURE.md** — analyse technique détaillée de chaque domaine
- **GUIDE_MIGRATIONS.md** — procédure complète pour ajouter une migration
- **GUIDE_DEPLOIEMENT.md** — procédure de déploiement pas à pas
