# Guide de déploiement — shift-pilot-svc

## Vue d'ensemble

Le déploiement est automatisé par GitHub Actions via deux workflows **indépendants** :

| Workflow | Rôle | Déclencheur | Résultat |
|----------|------|-----------|---------|
| `ci.yml` | Validation (tests + essai migrations) | Push sur `main`, `staging`, PR | Succès/Échec (aucune modification repo) |
| `deploy.yml` | Publication de version en dépôt | Push sur `main`, `staging` | Commit + push sur branche `deployed` |

**Relation** : `ci.yml` ne déclenche **pas** `deploy.yml`. Elles tournent toutes deux sur 
chaque push, mais en parallèle (groupe de concurrence distincts par branche). 

- Si les tests (`ci.yml`) échouent, le pipeline s'arrête — aucun commit ne quitte le dépôt.
- Si `ci.yml` passe, `deploy.yml` s'exécute **aussi** et écrit la version.
- Si `deploy.yml` échoue, la version précédente reste sur `deployed` (et aucune nouvelle 
  version n'est servie, car la synchronisation depend du fichier publié).

Les deux branches (`staging` et `main`) déploient indépendamment sur des répertoires distincts 
de la branche `deployed` (`staging/version.json` vs `production/version.json`). 

**Note importante** : la contention sur `deployed` reste possible si deux pipelines (ex. `staging` 
et `main`) tentent de pousser simultanément — voir section **Concurrence et ordre** pour les 
détails exacts et la mitigation appliquée.

## Les deux canaux distincts

### `staging` — Environnement de test des agents

- **Où** : branche `staging`
- **Versions publiées** : `deployed/staging/version.json`
- **Qui déploie** : les agents eux-mêmes — un agent pousse sur `staging`, les tests passent,
  le pipeline publie automatiquement
- **Récupération d'une version** :
  ```bash
  git fetch origin deployed && git show origin/deployed:staging/version.json
  ```
- **Annuler un déploiement** : pas de mécanisme automatique. En cas d'incident sur `staging`
  (données corrompues, migration erronée), il faut :
  1. Fixer le code sur une nouvelle branche
  2. Pousser sur `staging` pour déclencher une nouveau déploiement correctif

### `main` — Environnement de production

- **Où** : branche `main`
- **Versions publiées** : `deployed/production/version.json`
- **Qui déploie** : **aucun agent n'y pousse directement**. La Maintenance et la Production
  forment une équipe humaine qui :
  1. Vérifient que `staging` est sain (version et schéma corrects)
  2. Préparent la promotion (code review, plan de rollback, …)
  3. **Fusionnent `staging` vers `main` manuellement** (geste humain explicite)
  4. Le pipeline déclenche automatiquement
- **Récupération d'une version** :
  ```bash
  git fetch origin deployed && git show origin/deployed:production/version.json
  ```
- **Rollback** : en cas d'incident en production, il faut :
  1. Identifier et fixer la cause en code
  2. Fusionner un correctif sur `main`
  3. Le pipeline déclenche un nouveau déploiement

**Point critique** : confondre les deux et laisser une chaîne d'agents fusionner sur `main`
est l'échec que ce banc d'essai sert à détecter.

---

## Distinction critique : publication vs. exécution

C'est le point clé du système. **Deux espaces de vérité distincts** :

### Publication (en dépôt Git)

Le workflow `deploy.yml` écrit un fichier `<env>/version.json` sur la branche `deployed` :
```bash
deployed/
  ├── staging/version.json      # Version publiée depuis staging
  └── production/version.json   # Version publiée depuis main
```

**Contenu** : SHA du commit, branche source, schéma, horodatage du déploiement.
**Responsable** : GitHub Actions (`deploy.yml`).
**Source de vérité** : `git show origin/deployed:staging/version.json` (ou `production`).

### Exécution (sur l'hôte servi)

L'endpoint `/version` lit le fichier **`deployed-version.json` à la racine** de l'application servie :
```
~/shift-pilot-svc/
  └── deployed-version.json   # Fichier qui doit correspondre à ce qui est publié
```

**Contenu** : copie de `staging/version.json` ou `production/version.json`.
**Responsable** : un processus **external au dépôt** (webhook, script hébergement, …).
**Comportement** : si absent, `/version` retourne un JSON valide mais partiel 
(`sha/ref/deployedAt` à `null`, `schemaVersion` seul lu depuis la base).

### Le maillon manquant

Aucun mécanisme du dépôt ne copie le fichier de `deployed` à la racine de l'hôte. 
C'est un **problème de conception**, pas une erreur : il existe trois approches :

1. **Webhook post-déploiement** : GitHub appelle un script qui tire la version depuis `deployed`
2. **Script de déploiement** : l'hébergement exécute `git show ... > deployed-version.json`
3. **CI custom** : ajouter une étape après `git push origin deployed` qui déploie aussi sur l'hôte

Voir `QUESTIONS_OUVERTES.md` pour la décision définitive.

---

## Vérifications avant de pousser

Le développeur **doit** vérifier localement avant tout push. Ne pas compter sur le CI pour 
découvrir une erreur — les vérifications locales économisent un cycle CI :

```bash
php bin/migrate.php --dry-run    # Vérifier le SQL
composer test                     # Vérifier les tests unitaires
```

Voir `GUIDE_MIGRATIONS.md` section « Avant de déployer une migration » pour les détails complets.

## Workflows : Validation et Publication

Deux workflows s'exécutent **en parallèle et indépendamment** sur chaque push.

### Workflow 1 : Validation (`ci.yml`)

**Déclencheur** : Push sur `main`, `staging`, ou ouverture d'une PR.

**Étapes** :
1. Checkout et setup PHP 8.1, extensions PDO
2. Essai à blanc des migrations : `php bin/migrate.php --dry-run`
3. Tests unitaires : `composer test`

**Arrêt si** : un test échoue ou les migrations ont une syntaxe invalide.
**Sortie** : succès/échec — **aucune modification du dépôt** (lecture seule).

### Workflow 2 : Publication (`deploy.yml`)

**Déclencheur** : Push sur `main` ou `staging` **uniquement** (pas sur PR).

**Dépendance** : `ci.yml` n'est **pas** un prérequis. `deploy.yml` s'exécute aussi, mais 
inclut ses propres vérifications (tests, migrations). Si une vérification échoue, la version 
n'est pas publiée, mais **l'absence de dépendance explicite** signifie que les deux workflows 
tournent en parallèle, et `deploy.yml` n'attend **jamais** que `ci.yml` finisse.

**Étapes** :

#### 1. Validation interne

```bash
composer test
php bin/migrate.php
```

Tests et migrations. **Si un échoue, le pipeline s'arrête** — aucun commit sur `deployed`.

#### 2. Détermination de l'environnement

```bash
CIBLE=$( [[ "${{ github.ref_name }}" == "main" ]] && echo "production" || echo "staging" )
```

`main` → `production`, `staging` → `staging`.

#### 3. Récupération de la branche `deployed`

```bash
git fetch origin deployed --depth 1
```

Récupère l'état existant de `deployed` pour ne pas écraser les fichiers de l'autre environnement.

#### 4. Écriture du `version.json`

```json
{
  "sha": "<commit SHA>",
  "ref": "<branche: staging ou main>",
  "environnement": "<staging ou production>",
  "schemaVersion": <version lue depuis la base post-migration>,
  "deployedAt": "<horodatage UTC>"
}
```

Écrit dans `<environnement>/version.json` (soit `staging/version.json` ou `production/version.json`).

#### 5. Commit et push

```bash
git add <environnement>/version.json
git commit -m "deploy(<environnement>): <SHA>"
git push origin deployed --force-with-lease
```

Publie sur la branche `deployed`. Le `--force-with-lease` échoue si l'autre environnement a 
poussé entre-temps (rare, voir section **Concurrence**).

## Sauvegardes

### Avant chaque déploiement

Une copie horodatée de `data/app.db` est créée dans `data/backups/app-<horodatage>-avant-v<N>.db`.

- **En local** : persiste après le déploiement
- **En CI** : éphémère — supprimée après le run

### Restauration

En cas d'incident, il faut une sauvegarde **externe** de `data/app.db` sur l'hôte servi.
Les sauvegardes en CI ne persiste qu'une seule exécution.

## Récupérer la version déployée

### Depuis la branche `deployed`

```bash
git fetch origin deployed
git show origin/deployed:staging/version.json      # Dernière version staging
git show origin/deployed:production/version.json   # Dernière version production
```

Les fichiers JSON contiennent le SHA, la branche source, l'environnement et l'horodatage —
la source de vérité de ce qui a été publié.

### Depuis l'API (`GET /version`)

```bash
curl http://localhost:8080/version
```

**Séparation des responsabilités** :

1. **Le workflow `deploy.yml` (repo)** écrit `staging/version.json` ou `production/version.json` 
   **sur la branche `deployed`** — cela fonctionne de manière fiable.
2. **L'endpoint `/version` (application)** lit le fichier `deployed-version.json` **à la racine 
   du projet servi** — ce fichier doit être synchronisé par un processus **external au repo** 
   (webhook post-deploy, script de synchronisation, mécanisme d'hébergement).

**Sans ce mécanisme externe** :
```json
{
  "sha": null,
  "ref": null,
  "deployedAt": null,
  "schemaVersion": 1
}
```
(seul `schemaVersion` est fourni, lu depuis la base)

**Avec le mécanisme externe** :
```json
{
  "sha": "abc123...",
  "ref": "main",
  "environnement": "production",
  "schemaVersion": 1,
  "deployedAt": "2026-08-08T14:30:56Z"
}
```

**Responsabilité du déploiement** : implémenter la synchronisation :
```bash
# Sur l'hôte servi, après chaque déploiement CI
git show origin/deployed:<env>/version.json > deployed-version.json
```
(où `<env>` est `staging` ou `production`)

Voir `QUESTIONS_OUVERTES.md` pour la décision sur ce mécanisme.

## Points d'attention

### Migrations et CI

- Les migrations s'appliquent en CI contre `data/app.db` versionné (état initial).
- La base modifiée n'est **pas** commitée après les migrations.
- À la prochaine migration, elle s'applique aussi contre l'état initial du dépôt.

**Stratégie** : la base versionnée reste l'état zéro des migrations — les migrations
s'appliquent toutes à chaque déploiement. Si une deuxième migration est ajoutée, cette
hypothèse doit être clarifiée (faut-il mettre à jour `data/app.db` dans le dépôt ?).

### Concurrence et ordre

- **Sur la même branche** (`staging` ou `main`) : deux pushes rapides sont mis en file, non 
  annulés (`cancel-in-progress: false`). Le second déploiement attend que le premier finisse.
- **Sur des branches différentes** : les pipelines tournent en parallèle (groupes de concurrence
  distincts) — un push sur `staging` ne bloque pas un push sur `main`.

### Contention sur la branche `deployed`

**Situation** : les deux pipelines (`staging` et `main`) poussent sur la même branche `deployed`, 
mais dans des répertoires séparés (`staging/version.json` vs `production/version.json`).

**Protection** :
```bash
git push origin deployed --force-with-lease
```

Le `--force-with-lease` échoue si l'état distant a changé depuis le `git fetch` du pipeline — 
cela empêche un pipeline d'écraser les modifications apportées par l'autre.

**Scénario rare de conflit** :
1. Déploiement `staging` : fetch `deployed`, écrit `staging/version.json`
2. Déploiement `main` push `production/version.json` entre-temps
3. Déploiement `staging` tente le push → échoue (bail de lease)
4. Résolution : redéclencher manuellement le pipeline `staging`

**Monitoring** : logs de `.github/workflows/deploy.yml` — erreur `failed to push some refs` 
indique un conflit de lease rare.

### Base sans données initiales

Si le fichier `data/app.db` est absent du dépôt, le premier `composer migrate` le crée à vide.
Seule la migration s'applique après.

### Rollback et incidents

Pas de bouton rollback automatique. En cas d'incident sur `main`, il faut :
1. Fixer le code
2. Fusionner le correctif sur `main` (geste humain)
3. Le pipeline déclenche automatiquement

## Vérifications avant de déployer

Avant de fusionner sur `staging` ou `main`, l'agent/développeur/maintenance doit s'assurer que :

- [ ] `composer test` passe en local
- [ ] `composer migrate:dry` affiche le contenu des migrations prévues (sans les exécuter)
- [ ] Aucun conflit de merge
- [ ] Code review approuvée (si applicable)
- [ ] Pour `main` uniquement : accord explicite de la Maintenance/Production

## Dépannage

### Le pipeline échoue — tests rouges

1. **Cause** : un test PHPUnit échoue
2. **Effet** : aucune migration appliquée, version sur `deployed` inchangée
3. **Action** :
   - Lancer `composer test` en local
   - Fixer le code
   - Pousser un correctif
   - Le pipeline redéclenche automatiquement

### Le pipeline échoue — migration échoue

1. **Cause** : `php bin/migrate.php` sort avec un code non-zéro
2. **Effet** : version sur `deployed` inchangée
3. **Action** :
   - Lancer `php bin/migrate.php --dry-run` en local pour voir le SQL
   - Corriger le SQL de la migration
   - Pousser un correctif (nouvelle migration, pas modification de l'existante)
   - Le pipeline redéclenche automatiquement

### La version sur `deployed` reste inchangée

**Cause possible** : le dernier déploiement a échoué (tests ou migrations).

1. **Vérifier** :
   ```bash
   git log --oneline origin/deployed -- staging/version.json | head -3
   ```
   Doit afficher les trois derniers déploiements sur `staging`.

2. **Consulter les logs CI** :
   Aller sur `github.com/<owner>/<repo>/actions` pour voir les derniers runs.

### `/version` retourne des champs `null`

1. **Cause** : le fichier `deployed-version.json` n'existe pas ou est mal synchronisé sur l'hôte servi
   
2. **Comprendre le flux** : 
   - **Publication en dépôt** : le workflow `deploy.yml` écrit `staging/version.json` ou 
     `production/version.json` sur la branche `deployed` (source de vérité en Git).
   - **Lecture en service** : l'endpoint `/version` (ligne 16–19 de `public/index.php`) lit 
     le fichier `deployed-version.json` **à la racine du projet servi** sur l'hôte d'exécution.
   - **Le maillon critique** : aucun mécanisme du dépôt ne copie ce fichier de `deployed` 
     à la racine. Cela dépend d'un **processus external** : webhook post-déploiement, 
     script de synchronisation, ou script d'hébergement.

3. **Diagnostic** :
   ```bash
   # Sur l'hôte servi
   ls -la deployed-version.json  # Doit exister et être à jour
   cat deployed-version.json     # Doit contenir des clés valides (sha, ref, deployedAt, schemaVersion)
   
   # Vérifier ce qui a été publié en dépôt
   git show origin/deployed:staging/version.json
   ```

4. **Comportement du code** (`public/index.php`, ligne 17–27) :
   
   **Cas A : fichier absent** → Retourne JSON valide mais partiel :
   ```json
   {"sha": null, "ref": null, "deployedAt": null, "schemaVersion": 1}
   ```
   Cela indique que le déploiement n'a pas été synchronisé avec l'hôte.
   
   **Cas B : fichier existe, JSON valide** → Retourne JSON complet avec la version servie.
   
   **Cas C : fichier existe, JSON invalide** ⚠️  **Risque** → L'opérateur `+` ligne 27 reçoit 
   une valeur `false` ou `null` de `json_decode()`, ce qui provoque une `TypeError` en PHP 8.1+ 
   et retourne une erreur 500 (cf. commentaire du code ligne 18 : `json_decode(..., true)` 
   sans fallback robuste).
   
   **Mitigation** : s'assurer que le fichier `deployed-version.json` est toujours du JSON valide.
   Si corruption détectée : regénérer avec `git show origin/deployed:<env>/version.json > deployed-version.json`.

5. **Action de court terme (pour tests locaux)** :
   ```bash
   # Extraire manuellement depuis la branche deployed
   git show origin/deployed:staging/version.json > deployed-version.json
   ```
   
6. **Action définitive** : implémenter le mécanisme de synchronisation
   - Voir `QUESTIONS_OUVERTES.md` pour la décision architecturale sur ce processus externe.
   - Options courantes : webhook GitHub post-push, script CI, intégration hébergement.

### `schemaVersion` en `/version` est obsolète

1. **Cause** : mismatch entre le fichier de version et la base live (rare)
2. **Action** :
   - Vérifier la source de vérité : `deployed/<env>/version.json` doit primer
   - Si la base a été modifiée hors déploiement, restaurer une sauvegarde
   - Pousser un correctif sur la branche appropriée

## Pour aller plus loin

- Voir `README.md` pour la vue d'ensemble du service
- Voir `GUIDE_MIGRATIONS.md` pour les détails des migrations
- Voir `.github/workflows/deploy.yml` pour le code complet du pipeline
