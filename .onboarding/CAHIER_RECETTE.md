# CAHIER_RECETTE — shift-pilot-svc

> **Confiance** : high  
> **Dernière mise à jour** : 2026-08-08  
> **Durée estimée** : entre 60 et 90 minutes (variable selon connexion réseau et problèmes rencontrés)  
> **Prérequis** : PHP 8.1+, Composer, Git, accès GitHub Actions

## Objectif

Valider les quatre scénarios critiques du service shift-pilot-svc :
1. Installation locale et lancement du serveur HTTP
2. Application des migrations de schéma SQLite (dry-run, sauvegarde, rollback)
3. Endpoints HTTP de lecture et format JSON
4. Pipeline CI/CD et publication multi-environnement

**Important** : Les phases 2 et 5 incluent des commandes destructrices (`rm`, `DROP TABLE`). **Ces opérations s'exécutent exclusivement sur des copies de test, jamais sur le dépôt productif.** Chaque phase teste un environnement isolé.

---

## Prérequis avant de commencer

### Environnement de machine

- [ ] PHP 8.1+ installé (`php --version`)
- [ ] Composer installé (`composer --version`)
- [ ] Git configuré (`git config user.name`, `git config user.email`)
- [ ] SQLite CLI (`sqlite3 --version`) — utilisé en Phase 2 pour vérifier l'état de la base
- [ ] Accès GitHub Actions lisible (pas d'API token spécial requis pour lire les logs)

### Dépôt local

- [ ] Clone du dépôt dans `~/shift-pilot-svc/` (ou répertoire de choix)
  ```bash
  git clone https://github.com/<org>/shift-pilot-svc.git ~/shift-pilot-svc
  cd ~/shift-pilot-svc
  ```
- [ ] Branche `main` active : `git checkout main`
- [ ] Branche `staging` synchronisée : `git fetch origin staging && git checkout staging`
- [ ] Branche `deployed` connue : `git fetch origin deployed` (branche de lecture, pas de travail direct)

### Isolation des phases destructrices

Pour les phases 2 et 5, **créer un répertoire de test isolé avec une base vierge** :

```bash
# À exécuter UNE FOIS avant la recette
mkdir -p /tmp/shift-pilot-test
cd /tmp/shift-pilot-test
git clone https://github.com/<org>/shift-pilot-svc.git
cd shift-pilot-svc
git remote add productif https://github.com/<org>/shift-pilot-svc.git

# Préparer une base de test vierge
rm -f data/app.db
mkdir -p data/backups

# À partir d'ici, vous travaillez dans /tmp/shift-pilot-test/shift-pilot-svc
# Aucune opération ci-dessous ne modifie votre dépôt productif
```

**À la fin de la recette** :
```bash
rm -rf /tmp/shift-pilot-test
```

**Important — État initial** : La base `data/app.db` est supprimée. Elle sera reconstruite de zéro par `bin/migrate.php`, permettant de vérifier le processus complet d'initialisation. Si vous rencontrez une base déjà à schéma v1 (migration déjà appliquée), exécutez `rm -f data/app.db` pour recommencer.

---

## Phase 1 — Installation locale (10–15 min)

**Objectif** : Vérifier que le service peut démarrer en local.

**Environnement** : Votre dépôt productif `~/shift-pilot-svc/`

- [ ] **1.1** : Installer les dépendances
  ```bash
  cd ~/shift-pilot-svc
  composer install
  ```
  Attendre que tous les packages se chargent. Vérifier absence d'erreur.

- [ ] **1.2** : Lancer le serveur de développement
  ```bash
  php -S 127.0.0.1:8080 -t public
  ```
  Affichage attendu :
  ```
  Development Server (http://127.0.0.1:8080) started
  ```

- [ ] **1.3** : Tester endpoint `/health` (dans un autre terminal)
  ```bash
  curl http://127.0.0.1:8080/health
  ```
  Réponse attendue : `{"status":"ok"}`

- [ ] **1.4** : Tester endpoint `/orders` (lire 5 commandes)
  ```bash
  curl http://127.0.0.1:8080/orders | jq .
  ```
  Vérifier : Tableau de 5 objets (Heiata, Teiki, Manoa, Vaite, Moana), chacun avec `id`, `client`, `montant_cents`, `devise`, `statut`.

- [ ] **1.5** : Tester endpoint `/orders/1`
  ```bash
  curl http://127.0.0.1:8080/orders/1 | jq .
  ```
  Réponse attendue : Un seul objet JSON avec `id: 1, client: "Heiata", montant_cents: 420000, devise: "XPF", statut: "payee"`.

- [ ] **1.6** : Arrêter le serveur (Ctrl+C dans le terminal)

---

## Phase 2 — Migrations locales (20–30 min)

**Objectif** : Vérifier que `bin/migrate.php` fonctionne en essai à blanc et en mode réel, avec sauvegarde et rollback.

**Environnement** : Clone isolé `/tmp/shift-pilot-test/shift-pilot-svc/`

### Étape 2.1 — Essai à blanc

**Note préalable : Garantir l'état initial (obligatoire)**

La procédure d'isolation en début du cahier supprime déjà `data/app.db` pour repartir d'une base vierge. **Si vous rencontrez une base déjà migrée**, la procédure doit d'abord vérifier et corriger l'état :

```bash
cd /tmp/shift-pilot-test/shift-pilot-svc
# Vérifier l'état courant
php bin/migrate.php --dry-run

# Si affichage « Aucune migration à appliquer », la base est déjà à v1
# Réinitialiser la base vierge (l'étape d'isolation ne l'a pas fait)
rm -f data/app.db
```

**À partir d'ici, Phase 2 est exécutée avec une base garantie vierge (v0).**

- [ ] **2.1.1** : Dans le clone isolé, vérifier l'état initial de la base
  ```bash
  cd /tmp/shift-pilot-test/shift-pilot-svc
  php bin/migrate.php --dry-run
  ```
  Comportement attendu (base vierge v0) : Affichage du contenu SQL de `001_init.sql` (création des tables, insertion des 5 commandes).

- [ ] **2.1.2** : Vérifier que `data/app.db` n'a pas changé
  ```bash
  ls -la data/app.db
  # Noter l'horodatage
  ```
  Ré-exécuter le dry-run, vérifier que l'horodatage de `data/app.db` n'a pas changé (essai à blanc ne modifie rien).

### Étape 2.2 — Application réelle avec sauvegarde

- [ ] **2.2.1** : Appliquer les migrations (mode réel)
  ```bash
  cd /tmp/shift-pilot-test/shift-pilot-svc
  php bin/migrate.php
  ```
  Sortie attendue — **selon l'état de la base** :
  
  - **Si base à v0 (vierge)** :
    ```
    Version de schéma courante : 0
    À appliquer : migrations/001_init.sql
    Sauvegarde obligatoire créée : data/backups/app-<YYYYMMDD-HHmmss>-avant-v1.db
    [... application de la migration ...]
    Version de schéma finale : 1
    ```
  
  - **Si base déjà à v1** :
    ```
    Version de schéma courante : 1
    Aucune migration à appliquer
    ```
    (Aucune sauvegarde n'est créée car aucune migration n'est appliquée.)

- [ ] **2.2.2** : Vérifier la sauvegarde a été créée
  ```bash
  ls -la data/backups/
  ```
  Vérifier : Un fichier `app-*-avant-v1.db` existe et n'est pas vide.

- [ ] **2.2.3** : Vérifier la base a été mise à jour
  ```bash
  sqlite3 data/app.db "SELECT COUNT(*) FROM orders;"
  ```
  Résultat attendu : `5` (5 commandes insérées).

- [ ] **2.2.4** : Essai à blanc après application — vérifier aucune nouvelle migration
  ```bash
  php bin/migrate.php --dry-run
  ```
  Sortie attendue : `"Aucune migration à appliquer"` ou équivalent.

### Étape 2.3 — Simulation de rollback

**[Important : Ces commandes modifient la **copie de test** dans `/tmp/`, pas le dépôt productif.]**

- [ ] **2.3.1** : Supprimer le fichier de migration (simulation d'ajout erroné)
  ```bash
  # Copie de test, pas productif
  rm /tmp/shift-pilot-test/shift-pilot-svc/migrations/001_init.sql
  ```

- [ ] **2.3.2** : Restaurer la sauvegarde manuellement (cas de rollback)
  ```bash
  cd /tmp/shift-pilot-test/shift-pilot-svc
  cp data/backups/app-*-avant-v1.db data/app.db.restored
  ```
  Vérifier : Fichier `data/app.db.restored` existe (restauration d'urgence).

- [ ] **2.3.3** : Nettoyer la copie de test (avant passage à phase suivante)
  ```bash
  rm -rf /tmp/shift-pilot-test/shift-pilot-svc
  git clone https://github.com/<org>/shift-pilot-svc.git /tmp/shift-pilot-test/shift-pilot-svc
  cd /tmp/shift-pilot-test/shift-pilot-svc
  git remote add productif https://github.com/<org>/shift-pilot-svc.git
  ```

---

## Phase 3 — Tests PHPUnit (15–20 min)

**Objectif** : Vérifier que la suite de tests local passe.

**Environnement** : Votre dépôt productif `~/shift-pilot-svc/`

- [ ] **3.1** : Exécuter la suite de tests
  ```bash
  cd ~/shift-pilot-svc
  composer test
  ```
  Sortie attendue : `Tests passed` (ou `OK` selon PHPUnit) avec 5 tests verts.

- [ ] **3.2** : Vérifier les noms des tests exécutés
  ```bash
  composer test 2>&1 | grep -E "testListeToutesLesCommandes|testTrouveUneCommandeParIdentifiant|testIdentifiantInconnuRenvoieNull|testMontantsStockesEnCentimesEntiers|testVersionDeSchemaLueEnBase"
  ```
  Tous les 5 tests doivent être présents et marqués comme PASS (OK).

---

## Phase 4 — Endpoints HTTP en détail (10–15 min)

**Objectif** : Vérifier les quatre endpoints et leurs formats JSON.

**Environnement** : Votre dépôt productif, serveur en local

- [ ] **4.1** : Relancer le serveur de développement
  ```bash
  cd ~/shift-pilot-svc
  php -S 127.0.0.1:8080 -t public
  ```

- [ ] **4.2** : `/health` — toujours disponible, pas de base requise
  ```bash
  curl http://127.0.0.1:8080/health
  ```
  Réponse attendue : `{"status":"ok"}`

- [ ] **4.3** : `/version` — lit `deployed-version.json` (absent en local)
  ```bash
  curl http://127.0.0.1:8080/version
  ```
  Réponse attendue : HTTP 200 avec `{"sha":null,"ref":null,"deployedAt":null,"schemaVersion":1}` (fichier n'existe pas en local, comportement nominal — la clé `environnement` est absente, pas `null`). 
  
  **Attention — cas d'erreur grave** : Si le fichier existait mais contenait du JSON invalide ou malformé, `json_decode()` retournerait `null`, ce qui lèverait une `TypeError` à la ligne 27 de `public/index.php` en tentant de fusionner `null` et un tableau avec l'opérateur `+`. Le statut HTTP et le corps de la réponse dépendent alors de la configuration PHP de l'hôte (directives `display_errors`, `error_reporting`, mode CLI vs serveur web) : HTTP 500 sans JSON, HTML d'erreur PHP, ou stack trace brute. **Aucun JSON d'erreur structuré ne sera produit dans ce scénario.**

- [ ] **4.4** : `/orders` — tableau JSON des 5 commandes
  ```bash
  curl http://127.0.0.1:8080/orders | jq .
  ```
  Vérifier : Tableau contenant exactement 5 objets, chacun avec les champs `id`, `client`, `montant_cents`, `devise`, `statut`. Correspondance attendue avec `migrations/001_init.sql` :
  - `id: 1, client: "Heiata", montant_cents: 420000, devise: "XPF", statut: "payee"`
  - `id: 2, client: "Teiki", montant_cents: 180000, devise: "XPF", statut: "annulee"`
  - `id: 3, client: "Manoa", montant_cents: 960000, devise: "XPF", statut: "payee"`
  - `id: 4, client: "Vaite", montant_cents: 305000, devise: "XPF", statut: "payee"`
  - `id: 5, client: "Moana", montant_cents: 75000, devise: "XPF", statut: "annulee"`

- [ ] **4.5** : `/orders/1` — commande spécifique
  ```bash
  curl http://127.0.0.1:8080/orders/1 | jq .
  ```
  Réponse attendue : `{"id": 1, "client": "Heiata", "montant_cents": 420000, "devise": "XPF", "statut": "payee"}`

- [ ] **4.6** : `/orders/999` — ID absent
  ```bash
  curl http://127.0.0.1:8080/orders/999
  ```
  Réponse attendue : Statut HTTP 404 avec JSON `{"error": "Commande introuvable"}` (comportement défini).

- [ ] **4.7** : Arrêter le serveur (Ctrl+C)

---

## Phase 5 — Pipeline CI/CD (15–25 min)

**Objectif** : Vérifier que les deux workflows (CI et déploiement) fonctionnent correctement sur `staging` et `main`.

**Environnement** : Dépôt GitHub, branche `staging`

### Étape 5.1 — Créer une branche de test et la fusionner dans `staging`

**⚠️ Important — Mécanisme des workflows** :
- `ci.yml` se déclenche sur **les PRs** (indépendamment du push réel).
- `deploy.yml` se déclenche **uniquement sur les `push` directs** vers `staging` ou `main`, **jamais sur les PRs**.

Pour valider complètement le pipeline (CI + déploiement), nous devons fusionner la branche de test dans `staging` pour déclencher `deploy.yml`.

- [ ] **5.1.1** : Synchroniser les branches locales
  ```bash
  cd ~/shift-pilot-svc
  git fetch origin
  git checkout staging
  git pull origin staging
  ```

- [ ] **5.1.2** : Créer une branche de test
  ```bash
  git checkout -b test/staging-recette
  ```

- [ ] **5.1.3** : Faire un changement trivial (ne pas affecter le code fonctionnel)
  ```bash
  echo "# Test recette $(date +%s)" > .test-recette-trigger
  git add .test-recette-trigger
  git commit -m "test(recette): validation pipeline staging"
  ```
  **Remarque** : Le fichier `.test-recette-trigger` est un fichier temporaire créé exclusivement pour ce test. Il sera supprimé à l'étape 5.1.6.

- [ ] **5.1.4** : Pousser la branche et observer les checks CI
  ```bash
  git push origin test/staging-recette
  ```
  Aller sur GitHub et créer une PR vers `staging`. 
  
  Attendre que le workflow `ci.yml` se déclenche sur la PR :
  - Dry-run des migrations (`composer migrate:dry`)
  - Tests PHPUnit (`composer test`)
  - Tous les checks doivent être verts ✓

- [ ] **5.1.5** : Fusionner la branche dans `staging` (pour déclencher `deploy.yml`)
  ```bash
  # Sur GitHub : approuver et fusionner la PR ("Squash and merge" ou "Create a merge commit")
  # OU en ligne de commande :
  git checkout staging
  git pull origin staging
  git merge test/staging-recette
  git push origin staging
  ```
  
  Le `push` vers `staging` déclenche **immédiatement** le workflow `deploy.yml`. Cela créera/mettra à jour `deployed/staging/version.json` sur la branche `deployed`.

- [ ] **5.1.6** : Nettoyer la branche de test
  ```bash
  git checkout staging
  git pull origin staging
  git branch -D test/staging-recette
  git push origin --delete test/staging-recette
  ```
  Vérifier : Le fichier `.test-recette-trigger` a disparu de `staging` (il existe uniquement sur la branche de test supprimée).

### Étape 5.2 — Vérifier la publication sur la branche `deployed` (artefact Git, pas le fichier servi)

**⚠️ Important** : Cette étape valide que `deploy.yml` a **écrit** `deployed/<env>/version.json` sur la branche Git `deployed`. **Ce n'est pas le fichier que le code lit en production** (`public/index.php` lit `deployed-version.json` sur le disque du serveur). Le lien entre ces deux fichiers est hors dépôt (copie par webhook, script, ou mécanisme hébergement).

- [ ] **5.2.1** : **Avant** le push staging, capturer la version production actuelle
  ```bash
  git fetch origin deployed
  git show origin/deployed:production/version.json > /tmp/prod-version-before.json
  echo "SHA avant: $(sha256sum /tmp/prod-version-before.json)"
  ```
  Vérifier : Fichier créé avec contenu JSON valide. Consigner le SHA256.

- [ ] **5.2.2** : **Après** votre fusion dans `staging` (étape 5.1.5), refetcher et vérifier `staging` a été mise à jour
  ```bash
  git fetch origin deployed
  git show origin/deployed:staging/version.json
  ```
  Vérifier : Les champs `sha`, `ref`, `environnement`, `schemaVersion`, `deployedAt` sont présents et non `null`. Le `ref` doit être `staging`.

- [ ] **5.2.3** : Vérifier que `production` n'a **pas** été modifiée par le workflow staging
  
  Capturer à nouveau la version production et comparer les SHA :
  ```bash
  git fetch origin deployed
  git show origin/deployed:production/version.json > /tmp/prod-version-after.json
  echo "SHA après: $(sha256sum /tmp/prod-version-after.json)"
  diff /tmp/prod-version-before.json /tmp/prod-version-after.json
  ```
  
  Résultat attendu : 
  - Les deux SHA256 sont identiques.
  - La sortie de `diff` est vide.
  
  Cela confirme l'isolation entre `staging` et `production` — un push sur `staging` ne modifie jamais `deployed/production/version.json`.

---

## Phase 6 — Validations finales (5–10 min)

**Objectif** : Synthèse et constatations.

- [ ] **6.1** : Tous les endpoints accessibles et répondent correctement
- [ ] **6.2** : Migrations locales appliquent et sauvegardent correctement
- [ ] **6.3** : Tests PHPUnit passent
- [ ] **6.4** : Pipeline CI/CD se déclenche sur les branches et publie les versions
- [ ] **6.5** : Les deux environnements (staging/production) restent isolés sur `deployed`

---

## Résultat de validation

### ✅ Quatre scénarios critiques validés

Si tous les points ci-dessus sont cochés, les quatre domaines critiques du service sont fonctionnels :

1. **Installation locale** — service démarre, endpoints répondent
2. **Migrations de schéma** — application atomique, sauvegarde, rollback possible
3. **API en lecture seule** — format JSON conforme, 5 commandes persistées
4. **Pipeline multi-environnement** — CI/CD fonctionne, deux canaux isolés

### Prérequis avant déploiement en production

**Important** : Cette recette valide les quatre scénarios critiques, **elle n'est pas suffisante pour un déploiement production sans validations supplémentaires** :

- [ ] **Audit de sécurité complété** — Voir `.onboarding/audits/SECURITY_ROBUSTNESS_AUDIT.md` pour les risques identifiés
- [ ] **Stratégie de sauvegardes externe** — `data/backups/` est gitignoré et éphémère. Un mécanisme de sauvegarde pérenne est nécessaire avant tout déploiement
- [ ] **Plan de restauration testé** — Vérifier que les sauvegardes peuvent être restaurées en cas d'incident
- [ ] **Monitoring `/health` et `/version`** — Configurer les alertes en production pour détecter les défaillances
- [ ] **Mécanisme de dépôt de `deployed-version.json`** — Valider que le fichier est correctement copié sur l'hôte servi après chaque déploiement

---

## Nettoyage post-recette

```bash
# Supprimer la copie isolée créée en phase 2
rm -rf /tmp/shift-pilot-test

# Vérifier que votre dépôt productif n'a pas été modifié
cd ~/shift-pilot-svc
git status
# Doit afficher "working tree clean"
```

---

## Durées observées et notes

**À compléter après votre première exécution** :

| Phase | Durée estimée | Durée réelle | Notes |
|-------|---|---|---|
| 1 - Installation | 10–15 min | ___ | |
| 2 - Migrations | 20–30 min | ___ | |
| 3 - Tests | 15–20 min | ___ | |
| 4 - Endpoints | 10–15 min | ___ | |
| 5 - CI/CD | 15–25 min | ___ | Dépend de GitHub Actions |
| 6 - Validations | 5–10 min | ___ | |
| **Total** | **60–90 min** | **___** | Affiner après première exécution |

---

## Preuves citées

- `.onboarding/workflows/WORKFLOW_MIGRATION.md` — mécanisme de migration
- `.onboarding/workflows/WORKFLOW_DEPLOIEMENT.md` — pipeline deux canaux
- `.onboarding/workflows/WORKFLOW_SERVICE.md` — endpoints HTTP
- `bin/migrate.php` — application des migrations
- `public/index.php` — endpoints
- `tests/OrdersTest.php` — tests PHPUnit
- `README.md` — développement local
