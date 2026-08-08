# Guide de déploiement — shift-pilot-svc

## Vue d'ensemble

Le déploiement est automatisé par GitHub Actions. Chaque push sur `staging` ou `main`
déclenche un pipeline qui :

1. Vérifie les tests (PHPUnit) et les migrations en CI
2. Applique les migrations en cas de succès
3. Écrit une version sur la branche `deployed`
4. Pousse cette version sur `origin/deployed`

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

## Vérifications avant de pousser

Le développeur **doit** vérifier localement avant tout push. Ne pas compter sur le CI pour 
découvrir une erreur — les vérifications locales économisent un cycle CI :

```bash
php bin/migrate.php --dry-run    # Vérifier le SQL
composer test                     # Vérifier les tests unitaires
```

Voir `GUIDE_MIGRATIONS.md` section « Avant de déployer une migration » pour les détails complets.

## Pipeline de déploiement (`.github/workflows/deploy.yml`)

### Déclencheurs

```yaml
on:
  push:
    branches:
      - staging
      - main
```

Tout push sur `staging` ou `main` déclenche le pipeline — pas de bouton manuel, pas de PR.

### Étapes

#### 1. Checkout et setup

```bash
actions/checkout@v4
shivammathur/setup-php@v2 (PHP 8.1, extensions pdo, pdo_sqlite)
composer install --no-interaction --prefer-dist
```

Récupère le code, configure PHP, installe les dépendances.

#### 2. Tests (`composer test`)

```bash
php -m | grep -E '(pdo|pdo_sqlite)' && phpunit
```

Exécute PHPUnit contre une base temporaire. **Si les tests échouent, le pipeline s'arrête** —
aucune migration n'est appliquée, aucune version n'est publiée. La version précédente reste
sur la branche `deployed`.

#### 3. Application réelle des migrations

Le pipeline `.github/workflows/deploy.yml` (exécuté après la CI qui valide les tests) applique :
```bash
php bin/migrate.php
```

Les migrations appliquées en CI contre `data/app.db` versionné (état initial). La base modifiée 
n'est pas commitée — à la prochaine migration, elle s'applique aussi contre l'état initial.

Copie la base dans `data/backups/app-<horodatage>-avant-v<N>.db`, puis applique chaque
migration en transaction. **Si une migration échoue, le pipeline s'arrête** — la version
précédente reste sur `deployed`.

#### 4. Détermination de l'environnement

```bash
CIBLE=$( [[ "${{ github.ref_name }}" == "main" ]] && echo "production" || echo "staging" )
```

`main` → `production`, toute autre branche → `staging`.

#### 5. Récupération de la branche `deployed`

```bash
git fetch origin deployed --depth 1
```

Récupère la branche `deployed` pour ne pas écraser les fichiers de l'autre environnement.

#### 6. Écriture du `version.json`

```json
{
  "sha": "<commit SHA>",
  "ref": "<branche: staging ou main>",
  "environnement": "<staging ou production>",
  "schemaVersion": <version lue depuis la base post-migration>,
  "deployedAt": "<horodatage UTC>"
}
```

Exemple : `deployed/staging/version.json` ou `deployed/production/version.json`.

#### 7. Commit et push

```bash
git add <environnement>/version.json
git commit -m "deploy(<environnement>): <SHA>"
git push origin deployed --force-with-lease
```

Le `--force-with-lease` évite d'écraser un push concurrent sur `deployed` (protection légère).

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

1. **Cause probable** : le fichier `deployed-version.json` n'est pas sur l'hôte servi
2. **Explication** : 
   - Le workflow `deploy.yml` écrit `<env>/version.json` (soit `staging/version.json`, soit 
     `production/version.json`) sur la branche `deployed` dans le dépôt Git.
   - L'endpoint `/version` de `public/index.php` lit le fichier `deployed-version.json` à la 
     racine du projet servi (ligne 16-19).
   - **Le maillon manquant** : aucun mécanisme du dépôt ne copie ce fichier de la branche 
     `deployed` à la racine de l'hôte. Cela exige une étape externe : webhook post-deploy, 
     script de dépôt, ou synchronisation filesystem.
3. **Diagnostic** :
   ```bash
   # Sur l'hôte servi
   ls -la deployed-version.json
   # Sur le dépôt local
   git show origin/deployed:staging/version.json
   ```
4. **Action de court terme** :
   - Extraire manuellement et copier sur l'hôte :
     ```bash
     git show origin/deployed:<env>/version.json > deployed-version.json
     ```
     où `<env>` est `staging` ou `production`.
   - Voir `QUESTIONS_OUVERTES.md` pour la décision sur le mécanisme de dépôt.

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
