# GUIDE_DEPLOIEMENT — Pipeline CI/CD et publication des versions

> **Dernière mise à jour** : 2026-08-08  
> **Cible** : Équipes Maintenance, Production, et agents CI/CD  
> **Source de vérité** : `.onboarding/workflows/WORKFLOW_DEPLOIEMENT.md` (détails techniques)

---

## Distinction critique : artefact Git vs fichier servi

**⚠️ Point clé** : Le déploiement crée **deux fichiers de version** dans **deux emplacements différents**, avec des responsables et des cycles de vie distincts. Confondre ces deux fichiers est la source la plus probable de défauts en production.

| Aspect | Artefact Git | Fichier serveur |
|--------|--------------|-----------------|
| **Localisation** | `deployed/<env>/version.json` sur branche Git `deployed` | `deployed-version.json` à la racine du projet web |
| **Créé par** | Workflow `deploy.yml` (GitHub Actions) | Script d'hébergement (webhook, cron, ou Ansible — hors dépôt) |
| **Tracé par** | Historique Git — visible via `git log`, immuable après commit | Métadonnées serveur (timestamps fichier, logs applicatifs) |
| **Mis à jour** | À chaque merge réussi sur `staging`/`main` | À chaque copie du fichier Git vers le disque serveur |
| **État en cas de panne** | Inchangé (commit Git précédent reste) | **INCONNU** — dépend du script d'hébergement |
| **Responsable** | Équipe Développement (via CI/CD automatisé) | Équipe Ops / Infrastructure |
| **Quand le vérifier** | `git show origin/deployed:staging/version.json` | `curl https://staging.example.com/version` |

---

## Les deux canaux : staging et production

### Canal 1 — Staging (`staging` branche)

**Qui y accède ?** Développeurs, tests, préproduction.

**Cycle de vie** :
1. Développeur crée une branche de feature → PR sur `staging`
2. CI exécute : essai à blanc, tests
3. Approbation → merge sur `staging`
4. **Automatiquement** :
   - Tests tournent
   - Migrations appliquées (si présentes)
   - Version publiée sur `deployed/staging/version.json` (branche Git `deployed`)
   - Copie vers serveur staging (hors dépôt)
5. Accessible : `https://staging.example.com/version`

**Garantie** : Chaque push sur `staging` produit **une nouvelle version publiquement traçable** sur la branche `deployed`, **si les tests passent**. En cas d'erreur, la version reste celle de la push précédente.

### Canal 2 — Production (`main` branche)

**Qui y accède ?** Utilisateurs finaux ; promotion explicite depuis staging.

**Cycle de vie** :
1. Développeur crée une branche de feature → PR vers `main` (rare — la plupart passent par staging d'abord)
2. **Ou** : Fusion manuelle de `staging` → `main` (geste humain intentionnel, pas automatisé)
3. CI exécute : essai à blanc, tests
4. **Automatiquement** :
   - Tests tournent
   - Migrations appliquées
   - Version publiée sur `deployed/production/version.json` (branche Git `deployed`)
   - Copie vers serveur production (hors dépôt)
5. Accessible : `https://prod.example.com/version`

**Isolation stricte** : Un push sur `staging` **ne doit jamais** modifier `deployed/production/version.json`. Le workflow détecte la branche source du merge (`github.ref_name`) et choisit la cible :
- `github.ref_name == 'main'` → écrire `deployed/production/version.json`
- Sinon → écrire `deployed/staging/version.json`

---

## Contenu du fichier version

### Structure

Fichier Git : `deployed/<env>/version.json`

```json
{
  "sha": "a1b2c3d4e5f6...",
  "ref": "staging",
  "environnement": "staging",
  "schemaVersion": 1,
  "deployedAt": "2026-08-08T14:32:15Z"
}
```

**Champs** :
- `sha` : Identifiant du commit Git déployé (SHA complet du commit GitHub Actions `${{ github.sha }}`)
- `ref` : Nom de la branche source (détecté via `github.ref_name`)
- `environnement` : Dérivé de `ref` (`staging` ou `production`)
- `schemaVersion` : Version lue en base **au moment du déploiement** (avant que le serveur ne modify la base)
- `deployedAt` : Horodatage UTC du workflow (`date -u +%Y-%m-%dT%H:%M:%SZ`)

### Priorité du champ `schemaVersion`

**Important pour les opérateurs** : Le champ `schemaVersion` dans le fichier Git représente **la version qui a été déployée**, pas la version courante de la base live.

**Mécanisme en lecture** (`public/index.php:27`) :
```php
$version + ['schemaVersion' => Db::schemaVersion($pdo)]
```

L'opérateur `+` PHP préserve la clé du tableau de gauche (`$version` = contenu du fichier décodé). Donc :
- **Si le fichier contient `schemaVersion`** : Cette valeur est retournée (traçabilité du déploiement)
- **Si le fichier ne contient pas `schemaVersion`** : La version live de la base est retournée (fallback)

---

## Procédure de déploiement

### Pour les développeurs : pousser du code

1. **Préparation locale**
   ```bash
   git checkout -b feature/xyz
   # Modifier le code ou créer une migration
   composer test          # Vérifier les tests localement
   composer migrate:dry   # Vérifier la migration en essai à blanc
   git add .
   git commit -m "feat: description"
   ```

2. **Créer une PR**
   ```bash
   git push origin feature/xyz
   # Créer une PR sur GitHub vers `staging` (ou `main` si promotion directe)
   ```

3. **CI s'exécute automatiquement**
   - Essai à blanc des migrations
   - Tests PHPUnit
   - Tous les checks doivent être verts ✓

4. **Approbation et merge**
   ```bash
   # Sur GitHub : approuver la PR et fusionner
   # OU en CLI :
   git checkout staging
   git pull origin staging
   git merge feature/xyz
   git push origin staging
   ```

5. **Déploiement automatique**
   - `deploy.yml` se déclenche
   - Migrations appliquées
   - Version publiée sur `deployed/staging/version.json`
   - Fichier copié sur le serveur (hors dépôt)

### Pour les Ops : promouvoir staging vers production

**Important** : C'est une action **manuelle et intentionnelle**. Aucun webhook n'y force automatiquement.

```bash
# Étape 1 : Vérifier l'état de staging
curl https://staging.example.com/version | jq .
# Attendre que le serveur soit stable (no 500, health OK)

# Étape 2 : Fusionner staging dans main (plusieurs options)

# Option A — PR sur GitHub
git checkout main
git pull origin main
git merge staging
# Créer une PR, reviewers approuvent, fusionner

# Option B — Push direct (si permissions permettent)
git checkout main
git pull origin main
git merge staging
git push origin main

# Étape 3 : Vérifier la publication
# Attendre que deploy.yml se termine (1–2 min)
git fetch origin deployed
git show origin/deployed:production/version.json | jq .

# Étape 4 : Vérifier l'endpoint production
# Après que le fichier ait été copié sur le serveur (hors dépôt)
curl https://prod.example.com/version | jq .

# Étape 5 : Monitorer les alertes
# Surveiller /health, latence, logs applicatifs
```

---

## Situations d'erreur

### Déploiement échoue (tests rouge)

**Symptôme** : PR merge bloquée par checks rouges.

**Cause** : Tests échoue ou migration a une syntaxe SQL invalide.

**Résolution** :
1. Corriger le code ou la migration dans une branche
2. Pousser la correction
3. Vérifier que les checks deviennent verts ✓
4. Fusionner à nouveau

**Résultat** : La branche Git `deployed` reste inchangée jusqu'au prochain merge réussi.

### Déploiement échoue (migration échoue)

**Symptôme** : Tests passent mais `composer migrate` échoue en CI.

**Cause** : Erreur SQL ou état de la base inattendu.

**Résolution** :
1. Lire les logs du workflow GitHub (onglet Actions)
2. Tester la migration en local : `composer migrate --dry-run` et `composer migrate`
3. Corriger le fichier de migration
4. Repousser le changement (nouveau commit)

**Résultat** : La version publiée reste celle de la dernière push réussie.

### Fichier `deployed-version.json` manquant sur le serveur

**Symptôme** : Endpoint `/version` retourne `{"sha": null, "ref": null, "deployedAt": null, "schemaVersion": <N>}` — pas de horodatage ni de SHA.

**Cause** : Le script d'hébergement n'a pas copié le fichier Git vers le disque.

**Résolution** :
1. Vérifier la publication sur Git : `git show origin/deployed:staging/version.json`
2. Vérifier la présence sur le serveur : `ls -la deployed-version.json` (sur le serveur)
3. Re-déclencher la copie (manuellement ou via webhook)
4. Tester : `curl /version` (doit retourner un SHA non-null)

**Préoccupation** : Ce point est **hors dépôt** — la responsabilité incombe à l'équipe Ops. Document nécessaire : « Procédure de déploiement de `deployed-version.json` sur le serveur ».

### Fichier `deployed-version.json` corrompu

**Symptôme** : Endpoint `/version` retourne HTTP 500 sans JSON.

**Cause** : Le fichier existe mais n'est pas du JSON valide.

**Résolution** :
1. Arrêter le service web
2. Vérifier le fichier : `cat deployed-version.json`
3. Restaurer depuis la branche Git :
   ```bash
   git fetch origin deployed
   git show origin/deployed:staging/version.json > deployed-version.json
   # OU
   git show origin/deployed:production/version.json > deployed-version.json
   ```
4. Redémarrer le service

**Préoccupation** : Le code ne protège pas cette erreur — ajouter une gestion d'exception est recommandé avant montée en production réelle.

---

## Vérifications recommandées

### Quotidien (si production active)

```bash
# 1. Santé de l'endpoint
curl https://prod.example.com/health

# 2. Version servie (SHA et horodatage)
curl https://prod.example.com/version | jq .

# 3. Données métier
curl https://prod.example.com/orders | jq '.| length'

# 4. Vérifier la publication Git
git fetch origin deployed
git show origin/deployed:production/version.json | jq .
```

### Avant une promotion staging → production

```bash
# 1. État de staging stable
curl https://staging.example.com/health

# 2. Pas de déploiement en cours sur main
git fetch origin deployed
git log --oneline -5 origin/deployed

# 3. Vérifier les migrations appliquées
git show origin/deployed:staging/version.json | jq '.schemaVersion'
git show origin/deployed:production/version.json | jq '.schemaVersion'
# (Staging doit être = Production ou supérieur)

# 4. Comparaison avant/après promotion
PROD_SHA_BEFORE=$(git show origin/deployed:production/version.json | jq -r '.sha')
# ... effectuer la promotion ...
git fetch origin deployed
PROD_SHA_AFTER=$(git show origin/deployed:production/version.json | jq -r '.sha')
echo "Avant: $PROD_SHA_BEFORE"
echo "Après: $PROD_SHA_AFTER"
# (Doit être différent si promotion effectuée)
```

---

## Références et documentation

- **Workflow technique** : `.onboarding/workflows/WORKFLOW_DEPLOIEMENT.md`
- **Cahier de recette** : `.onboarding/CAHIER_RECETTE.md` — Phase 5 pour reproduire un déploiement complet
- **Guide des migrations** : `.onboarding/GUIDE_MIGRATIONS.md`
- **CDC fonctionnel** : `.onboarding/CDC_FONCTIONNEL.md` — règles métier et garanties détaillées
