# Plan de recette — shift-pilot-svc

Ce document décrit les tests manuels et automatisés pour valider le bon fonctionnement du
service et des deux canaux (`staging`/`main`).

## Avant de commencer

**Prérequis** :
- PHP 8.1 avec extensions `pdo` et `pdo_sqlite`
- `composer` installé
- Accès au dépôt Git
- Accès à GitHub Actions pour observer les pipelines

**Durée estimée** : 45 minutes pour une recette complète

---

## Phase 1 : Setup local (10 min)

### 1.1 Cloner et installer

```bash
git clone <url> shift-pilot-svc
cd shift-pilot-svc
composer install
```

**Vérification** : aucune erreur, `vendor/` créé.

### 1.2 Vérifier la base de données

```bash
ls -la data/app.db
file data/app.db
sqlite3 data/app.db ".tables"
```

**Vérification** :
- Fichier existe et est un SQLite valide
- Tables présentes : `orders`, `schema_migrations`

### 1.3 Vérifier les sauvegardes

```bash
ls -la data/backups/ 2>&1 | head -5
```

**Vérification** : dossier vide ou contient uniquement des `.db` horodatés.

---

## Phase 2 : Tests unitaires (5 min)

### 2.1 Lancer les tests

```bash
composer test
```

**Vérification** :
- Tous les tests passent
- Pas de warning PHP

### 2.2 Analyser la couverture (optionnel)

```bash
vendor/bin/phpunit --coverage-text
```

**Vérification** : tous les tests passent sans erreur.

---

## Phase 3 : Migrations (15 min)

### 3.1 Essai à blanc

```bash
php bin/migrate.php --dry-run
```

ou

```bash
composer migrate:dry
```

**Vérification** :
- Affiche le SQL de chaque migration non appliquée
- Pas d'erreur

**Sortie attendue** :
```
Version courante : 1
Aucune migration à appliquer.
```
(puisque `001_init.sql` est déjà appliquée)

### 3.2 Vérifier la version courante en base

```bash
sqlite3 data/app.db "SELECT MAX(version) FROM schema_migrations;"
```

**Vérification** : retourne `1`.

### 3.3 Tester une migration fictive (**optionnel, recommandé**)

**Objectif** : valider que le migrateur fonctionne (dry-run, sauvegarde, application).

Créer un fichier `migrations/002_test_dummy.sql` :
```sql
-- Test migration (à supprimer après le test)
CREATE TABLE IF NOT EXISTS test_dummy (id INTEGER PRIMARY KEY);
```

Essai à blanc :
```bash
php bin/migrate.php --dry-run
```

**Vérification** : affiche le `CREATE TABLE` sans l'exécuter.

Application réelle :
```bash
php bin/migrate.php
```

**Vérification** :
- Affiche « Version de schéma finale : 2 »
- `data/backups/app-YYYYMMDD-HHmmss-avant-v2.db` créé (avant la migration)
- Base modifiée : `sqlite3 data/app.db "SELECT COUNT(*) FROM schema_migrations;"` retourne `2`
- Table créée : `sqlite3 data/app.db "SELECT name FROM sqlite_master WHERE type='table' AND name='test_dummy';"` retourne `test_dummy`

**Nettoyage** :
```bash
rm migrations/002_test_dummy.sql
```

**Note** : la table test_dummy reste dans la base de test (pas de rollback automatique de la 
structure). C'est normal — en production, une migration appliquée ne s'annule pas seule. 
Pour tester un vrai rollback sur `data/app.db`, restaurer depuis la sauvegarde horodatée.

---

## Phase 4 : Serveur local et endpoints (10 min)

### 4.1 Démarrer le serveur

```bash
php -S 127.0.0.1:8080 -t public
```

**Vérification** : aucune erreur.

### 4.2 Tester `/health`

```bash
curl -s http://127.0.0.1:8080/health | jq .
```

**Sortie attendue** :
```json
{"status":"ok"}
```

### 4.3 Tester `/version`

```bash
curl -s http://127.0.0.1:8080/version | jq .
```

**Sortie attendue** (local, `deployed-version.json` absent) :
```json
{
  "sha": null,
  "ref": null,
  "deployedAt": null,
  "schemaVersion": 1
}
```

**Explication** :
- `schemaVersion` vient toujours de la base (`SELECT MAX(version) FROM schema_migrations`)
- `sha`, `ref`, `deployedAt` viennent du fichier `deployed-version.json` — absent en local après 
  un `git clone`. Après un déploiement CI, ce fichier doit être présent à la racine du projet 
  servi (voir `GUIDE_DEPLOIEMENT.md` pour le mécanisme de dépôt).

**En production** (après déploiement CI réussi) :
```json
{
  "sha": "abc123...",
  "ref": "staging",
  "environnement": "staging",
  "schemaVersion": 1,
  "deployedAt": "2026-08-08T14:30:56Z"
}
```

### 4.4 Tester `/orders`

```bash
curl -s http://127.0.0.1:8080/orders | jq .
```

**Sortie attendue** :
```json
[
  {"id": 1, "client": "Heiata", "montant_cents": 420000, "devise": "XPF", "statut": "payee"},
  {"id": 2, "client": "Teiki", "montant_cents": 180000, "devise": "XPF", "statut": "annulee"},
  {"id": 3, "client": "Manoa", "montant_cents": 960000, "devise": "XPF", "statut": "payee"},
  {"id": 4, "client": "Vaite", "montant_cents": 305000, "devise": "XPF", "statut": "payee"},
  {"id": 5, "client": "Moana", "montant_cents": 75000, "devise": "XPF", "statut": "annulee"}
]
```

**Vérification** : 5 commandes retournées (Heiata, Teiki, Manoa, Vaite, Moana), triées par `id`, avec montants en centimes.

### 4.5 Tester `/orders/{id}`

```bash
curl -s http://127.0.0.1:8080/orders/1 | jq .
```

**Sortie attendue** :
```json
{"id": 1, "client": "Heiata", "montant_cents": 420000, "devise": "XPF", "statut": "payee"}
```

Identifier manquant :
```bash
curl -s http://127.0.0.1:8080/orders/999 | jq .
```

**Sortie attendue** :
```json
{"error": "Commande introuvable", "code": 404}
```

### 4.6 Tester une route inexistante

```bash
curl -s http://127.0.0.1:8080/foobar | jq .
```

**Sortie attendue** :
```json
{"error": "Route inconnue", "code": 404}
```

### 4.7 Arrêter le serveur

```bash
Ctrl+C
```

---

## Phase 5 : Pipeline CI/CD (15 min)

### 5.1 Créer une branche de test sur `staging`

```bash
git checkout -b test-recette-staging
# Faire une modification mineure, ex. ajouter un commentaire dans README.md
git add README.md
git commit -m "test(recette): validation staging"
git push origin test-recette-staging
```

### 5.2 Créer une PR vers `staging`

Sur GitHub (ou via `gh pr create`) :
- Source : `test-recette-staging`
- Cible : `staging`

**Vérifications dans les logs CI** (`.github/workflows/ci.yml`) :
- [ ] Tests passent (composer test)
- [ ] Aucune erreur PHP ou d'extension manquante
- Attendre que la CI soit verte

### 5.3 Fusionner sur `staging`

```bash
git checkout staging
git pull origin staging
git merge --ff-only origin/test-recette-staging
git push origin staging
```

**Pipeline déclenché** (`.github/workflows/deploy.yml`, job `publier`) :
1. Checkout du code
2. Setup PHP + Composer install
3. Exécution de `composer test` (PHPUnit) — **pipeline s'arrête si tests rouges**
4. Application des migrations : `php bin/migrate.php` — **pipeline s'arrête si migration échoue**
5. Détermination de la cible : `staging` → `staging`, `main` → `production`
6. Récupération de la branche `deployed` pour isolation par environnement
7. Écriture de `staging/version.json` avec SHA, ref, schemaVersion, horodatage
8. Commit sur la branche `deployed` : `git commit -m "deploy(staging): <SHA>"`
9. Push avec lease : `git push origin deployed --force-with-lease`

**Important** : le développeur doit valider localement avant de pousser pour économiser un cycle CI 
(voir `GUIDE_MIGRATIONS.md` section « Avant de déployer une migration »).

**En cas de succès** : `deployed/staging/version.json` est à jour, accessible via `git show origin/deployed:staging/version.json`

**En cas d'échec** (tests ou migration) : 
- Étape 9-10 ne s'exécutent pas
- Aucune nouvelle version publiée
- `deployed/staging/version.json` reste celle du déploiement précédent

### 5.4 Vérifier la version publiée

**Important** : le workflow `deploy.yml` écrit sur la branche `deployed` du dépôt Git (fichier `staging/version.json`). 
L'endpoint `/version` de l'application lit le fichier `deployed-version.json` à la racine du projet servi — ce fichier 
doit être synchronisé par un processus **externe au repo** (webhook, script, ou mécanisme d'hébergement). 
Voir `GUIDE_DEPLOIEMENT.md` pour les détails.

```bash
git fetch origin deployed
git show origin/deployed:staging/version.json
```

**Sortie attendue** :
```json
{
  "sha": "<commit SHA>",
  "ref": "staging",
  "environnement": "staging",
  "schemaVersion": 1,
  "deployedAt": "2026-08-08T14:30:56Z"
}
```

**Vérification clé** : le `sha` doit correspondre au commit du merge (voir `git log --oneline staging | head -1`).

### 5.5 Vérifier que `production` n'est pas affectée

```bash
git show origin/deployed:production/version.json
```

**Vérification** : le SHA doit être différent de celui de staging (ancien déploiement).

### 5.6 Nettoyer

```bash
git branch -D test-recette-staging
git push origin -d test-recette-staging
```

---

## Phase 6 : Promotion staging → production (5 min)

### 6.1 Vérifier que staging est sain

Voir Phase 5.4 — le `deployed/staging/version.json` doit être frais.

### 6.2 Créer une branche de promotion

```bash
git checkout -b promote-staging-to-main
git merge --ff-only origin/staging
git push origin promote-staging-to-main
```

### 6.3 Créer une PR vers `main`

Sur GitHub :
- Source : `promote-staging-to-main`
- Cible : `main`

**Vérifications** :
- [ ] Tests passent en CI
- Approuver la PR (geste humain, normalement fait par Maintenance/Production)

### 6.4 Fusionner sur `main`

```bash
git checkout main
git pull origin main
git merge --ff-only origin/promote-staging-to-main
git push origin main
```

**Attendre le déploiement** (`deploy.yml` sur `main`) :
- [ ] Pipeline identique à `staging`, mais écrit sur `deployed/production/version.json`

### 6.5 Vérifier la version production

```bash
git fetch origin deployed
git show origin/deployed:production/version.json
```

**Vérification** : le SHA doit correspondre à celui du commit de promotion.

### 6.6 Nettoyer

```bash
git branch -D promote-staging-to-main
git push origin -d promote-staging-to-main
git checkout main  # Revenir à main (branche de travail)
```

---

## Phase 7 : Vérifications de sécurité (optionnel, 5 min)

### 7.1 Vérifier qu'un agent ne peut pas fusionner directement sur `main`

Tenter de créer une PR depuis une branche directement vers `main` (sans passer par `staging`) :
- Ce n'est pas techniquement bloqué par le dépôt (pas de branch protection)
- Mais elle **ne doit jamais être fusionnée** par un agent — c'est un geste humain
- La présence de cette PR est un signal pour la Maintenance/Production

**Vérification** : aucune PR d'agent directe vers `main` en production.

### 7.2 Vérifier la sauvegardes de backups

```bash
ls -la data/backups/ | head -10
```

**Vérification** : aucun commit de sauvegarde dans le dépôt (`.gitignore` correct).

### 7.3 Vérifier les dépendances

```bash
composer outdated
composer audit
```

**Vérification** : pas de vulnérabilités critique non patchées.

---

## Phase 8 : Recette approfondie (optionnel, 10 min)

### 8.1 Tester une restauration de sauvegarde

Si vous avez créé la migration fictive en Phase 3.3 :

```bash
# Base pré-test
ls -la data/app.db
stat data/app.db

# Supprimer la table test (ou la migration est appliquée en base)
sqlite3 data/app.db "DROP TABLE IF EXISTS test_dummy;"

# Restaurer depuis sauvegarde
cp data/backups/app-YYYYMMDD-HHmmss-avant-v2.db data/app.db

# Vérifier
sqlite3 data/app.db ".tables"  # test_dummy ne doit pas exister
sqlite3 data/app.db "SELECT MAX(version) FROM schema_migrations;"  # doit afficher 1
```

### 8.2 Tester une réexécution de migration

```bash
php bin/migrate.php
```

**Vérification** : affiche « Aucune migration à appliquer » (puisque v1 est déjà appliquée).

### 8.3 Vérifier la cohérence des données

```bash
sqlite3 data/app.db "SELECT COUNT(*) FROM orders; SELECT COUNT(*) FROM schema_migrations;"
```

**Vérification** : `5` commandes, `1` migration.

---

## Checklist finale

Avant de valider la recette complète, cocher tous les points :

- [ ] **Phase 1** : Base versionnée, sauvegardes gitignoré
- [ ] **Phase 2** : Tous les tests passent
- [ ] **Phase 3** : Migrations appliquées, essai à blanc fonctionne
- [ ] **Phase 4** : Tous les endpoints retournent du JSON cohérent
- [ ] **Phase 5** : Pipeline `staging` fonctionne, version publiée
- [ ] **Phase 5.5** : `production` n'est pas affectée par `staging`
- [ ] **Phase 6** : Promotion `staging` → `main` réussit, version production à jour
- [ ] **Phase 7** : Sauvegardes gitignoré, dépendances saines
- [ ] **Phase 8** (optionnel) : Restauration et réexécution correctes

---

## Dépannage rapide

| Symptôme | Cause probable | Action |
|----------|---|---|
| `SQLSTATE[HY000]: General error: 1 database disk image is malformed` | `data/app.db` corrompu | Restaurer depuis `data/backups/` |
| Tests échouent localement | Base en mauvais état ou `SVC_DB_PATH` mal configuré | `composer test` crée sa propre base temporaire — vérifier Php |
| Pipeline échoue sur une nouvelle migration | Nommage mal formé ou SQL invalide | Vérifier le nom : `NNN_*.sql` avec N ∈ [0-9] ; essai à blanc local |
| `/version` retourne des champs `null` | `deployed-version.json` absent sur l'hôte | Vérifier que le mécanisme externe de synchronisation fonctionne (webhook post-deploy ou script) |
| `production` est affectée après un push sur `staging` | Branche `deployed` non isolée par environnement | Vérifier `deploy.yml:40` — détermination de `CIBLE` |

---

## Après la recette

**Si tous les points sont cochés** : le service est validé et prêt pour l'utilisation en production.

**Points à surveiller en production** :
- Monitoring de `/health` et `/version` pour détecter les incidents
- Sauvegardes externes de `data/app.db` avant chaque déploiement
- Validation que le mécanisme de dépôt de `deployed-version.json` fonctionne

**Pour les futurs développeurs** :
- Relire [README.md](README.md) et [GUIDE_MIGRATIONS.md](GUIDE_MIGRATIONS.md) avant
  d'ajouter une migration
- Consulter [QUESTIONS_OUVERTES.md](QUESTIONS_OUVERTES.md) avant l'extension

---

## Synthèse de couverture

| Phase | Type | Domaine |
|-------|------|---------|
| 1–2 | Automatisé | Installation, tests unitaires |
| 3 | Manuel | Migrations : dry-run, application, sauvegardes |
| 4 | Manuel | Endpoints : réponses JSON, états |
| 5–6 | Automatisé | Pipeline CI/CD : tests, migrations, version publiée |
| 7–8 | Manuel | Sécurité, isolation, restauration |

---

Dernière mise à jour : 2026-08-08
