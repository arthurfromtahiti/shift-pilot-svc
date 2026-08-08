# Cahier de recette — shift-pilot-svc

## Objectif

Valider que shift-pilot-svc fonctionne comme spécifié : quatre endpoints HTTP accessibles, version servie observable, distinction staging/production préservée, migrations appliquées en sécurité.

Ce document décrit les **parcours critique** à tester manuellement et les **vérifications automatisées** via CI/CD.

## Prérequis

- PHP 8.1+ avec extensions `pdo` et `pdo_sqlite`
- `composer` installé
- Accès au dépôt Git et aux branches
- Accès à GitHub Actions (pour observer les pipelines)
- Client HTTP (curl, postman, navigateur)

Durée estimée : **45 minutes** pour une recette complète.

---

# Parcours 1 : Service opérationnel en local

## 1.1 — Installation et base

### Étape A : Checkout et installation

```bash
git clone https://github.com/... shift-pilot-svc
cd shift-pilot-svc
composer install
```

**Vérifications** :
- [ ] Aucune erreur PHP durant l'installation
- [ ] `vendor/` créé avec `autoload.php`
- [ ] `composer.lock` existe

### Étape B : État initial de la base

```bash
ls -lh data/app.db
file data/app.db
sqlite3 data/app.db ".tables"
sqlite3 data/app.db "SELECT COUNT(*) FROM orders;"
sqlite3 data/app.db "SELECT version FROM schema_migrations;"
```

**Vérifications** :
- [ ] Fichier `data/app.db` existe (~12 KB)
- [ ] `file` confirme : SQLite 3.x database
- [ ] Tables présentes : `orders`, `schema_migrations`
- [ ] 5 lignes dans `orders` (Heiata, Teiki, Manoa, Vaite, Moana)
- [ ] Schéma en version 1

### Étape C : Essai à blanc des migrations

```bash
php bin/migrate.php --dry-run
```

**Vérification** :
- [ ] Affiche : `version de schéma en base : 1`
- [ ] Affiche : `aucune migration à appliquer`
- [ ] Aucune erreur

## 1.2 — Tests unitaires

### Étape A : Exécution

```bash
composer test
```

**Vérification** :
- [ ] PHPUnit s'exécute sans erreur
- [ ] Tous les tests passent (OK / PASS)
- [ ] Aucun warning PHP (`Warning`, `Deprecated`)

### Étape B : Sortie attendue

```
Test Suite PASS
  [✓] testListeToutesLesCommandes — 5 commandes retournées
  [✓] testTrouveUneCommandeParIdentifiant — détail d'une commande
  [✓] testIdentifiantInconnuRenvoieNull — absence gérée

3 tests, 3 ok
```

---

# Parcours 2 : Endpoints HTTP

## 2.1 — Lancer le serveur local

### Étape A : Démarrage

```bash
php -S 127.0.0.1:8080 -t public
```

Laissez le serveur tournant dans un terminal séparé.

**Vérification** : message `Listening on http://127.0.0.1:8080`

## 2.2 — Endpoint `GET /health`

### Requête

```bash
curl -s http://127.0.0.1:8080/health | jq .
```

### Vérifications

- [ ] Code HTTP 200
- [ ] Réponse JSON : `{"status":"ok"}`
- [ ] Content-Type : `application/json`

### Répétition

Faire 3 requêtes d'affilée → toutes doivent succéder.

## 2.3 — Endpoint `GET /version`

### Requête (sans fichier déploiement)

```bash
curl -s http://127.0.0.1:8080/version | jq .
```

### Vérifications

- [ ] Code HTTP 200
- [ ] Réponse JSON avec 5 champs :
  - `sha` : `null` (aucun fichier `deployed-version.json` en local)
  - `ref` : `null`
  - `environnement` : `null`
  - `deployedAt` : `null`
  - `schemaVersion` : `1` (lu depuis la base)

### Vérification avec fichier de déploiement (simulation)

```bash
cat > deployed-version.json << 'EOF'
{
  "sha": "abc123def456",
  "ref": "staging",
  "environnement": "staging",
  "deployedAt": "2026-08-08T06:37:00Z"
}
EOF
curl -s http://127.0.0.1:8080/version | jq .
```

**Vérifications** :
- [ ] Champs `sha`, `ref`, `environnement`, `deployedAt` maintenant présents
- [ ] `schemaVersion` reste `1` (priorité du fichier : c'est le fichier qui le fournit)
- [ ] Simuler une mise à jour du fichier → `/version` retourne la nouvelle valeur immédiatement

## 2.4 — Endpoint `GET /orders`

### Requête

```bash
curl -s http://127.0.0.1:8080/orders | jq .
```

### Vérifications

- [ ] Code HTTP 200
- [ ] Réponse : tableau JSON avec 5 objets
- [ ] Ordre : croissant par `id` (1, 2, 3, 4, 5)
- [ ] Champs présents pour chaque commande : `id`, `client`, `montant_cents`, `devise`, `statut`

### Exemple de réponse attendue

```json
[
  {
    "id": 1,
    "client": "Heiata",
    "montant_cents": 420000,
    "devise": "XPF",
    "statut": "payee"
  },
  ...
]
```

### Parcours : Vérifier la cohérence des données

```bash
sqlite3 data/app.db "SELECT id, client, montant_cents, devise, statut FROM orders ORDER BY id;"
```

Comparer les résultats bruts SQLite avec la réponse JSON → doit être identique.

## 2.5 — Endpoint `GET /orders/{id}`

### Requête : Cas de succès

```bash
curl -s http://127.0.0.1:8080/orders/1 | jq .
```

**Vérifications** :
- [ ] Code HTTP 200
- [ ] Réponse JSON avec un objet (pas un tableau)
- [ ] Valeurs correspondent à la ligne `id=1`

### Requête : Cas d'erreur — identifiant non entier

```bash
curl -s -o /dev/null -w "%{http_code}\n" http://127.0.0.1:8080/orders/abc
```

**Vérifications** :
- [ ] Code HTTP 404
- [ ] Réponse JSON : `{"error":"Route inconnue"}`

### Requête : Cas d'erreur — identifiant absent

```bash
curl -s http://127.0.0.1:8080/orders/999 | jq .
```

**Vérifications** :
- [ ] Code HTTP 404
- [ ] Réponse JSON : `{"error":"Commande introuvable"}`

### Parcours : Tester les 5 identifiants valides

```bash
for id in 1 2 3 4 5; do
  echo "=== ID $id ==="
  curl -s http://127.0.0.1:8080/orders/$id | jq .id,.client
done
```

**Vérification** : les 5 doivent succéder.

## 2.6 — Route inconnue

### Requête

```bash
curl -s http://127.0.0.1:8080/unknown | jq .
```

**Vérifications** :
- [ ] Code HTTP 404
- [ ] Réponse JSON : `{"error":"Route inconnue"}`

---

# Parcours 3 : Cycle de migrations

## 3.1 — État initial

### Étape A : Lire la version en base

```bash
sqlite3 data/app.db "SELECT MAX(version) FROM schema_migrations;"
```

**Vérification** : `1`

### Étape B : Essai à blanc

```bash
php bin/migrate.php --dry-run
```

**Vérification** :
- [ ] Sortie : `aucune migration à appliquer`
- [ ] Aucune erreur

## 3.2 — Créer une migration de test

### Étape A : Créer le fichier

```bash
cat > migrations/002_test.sql << 'EOF'
-- Migration de test : ajouter une colonne notes
ALTER TABLE orders ADD COLUMN notes TEXT;
EOF
```

### Étape B : Essai à blanc

```bash
php bin/migrate.php --dry-run
```

**Vérifications** :
- [ ] Sortie affiche `002_test.sql`
- [ ] Affiche le SQL : `ALTER TABLE orders ADD COLUMN notes TEXT;`
- [ ] **Aucune modification** en base (essai à blanc)

### Étape C : Vérifier que la base est inchangée

```bash
sqlite3 data/app.db ".schema orders"
```

**Vérification** : colonne `notes` **n'existe pas** (essai à blanc n'a pas écrit)

## 3.3 — Appliquer la migration

### Étape A : Appliquer

```bash
php bin/migrate.php
```

**Vérifications** :
- [ ] Sortie confirme application : `appliquée : 002_test.sql`
- [ ] Sortie affiche : `version de schéma finale : 2`
- [ ] Message de sauvegarde : `sauvegarde écrite : app-20260808-HHMMSS-avant-v2.db`

### Étape B : Vérifier la sauvegarde

```bash
ls -lh data/backups/ | grep "before-v2"
```

**Vérification** :
- [ ] Un fichier `app-*-avant-v2.db` existe
- [ ] Taille ~12 KB (copie de la base avant migration)

### Étape C : Vérifier la mutation en base

```bash
sqlite3 data/app.db ".schema orders"
sqlite3 data/app.db "SELECT version FROM schema_migrations;"
```

**Vérifications** :
- [ ] Colonne `notes` existe dans le schéma
- [ ] `schema_migrations` porte maintenant deux lignes : `1` et `2`

## 3.4 — Rollback via sauvegarde

### Étape A : Identifier la sauvegarde

```bash
ls -t data/backups/app-*-avant-v2.db | head -1
```

Notez le chemin exact.

### Étape B : Restaurer (simulation)

```bash
cp "$(ls -t data/backups/app-*-avant-v2.db | head -1)" data/app.db
```

### Étape C : Vérifier le rollback

```bash
sqlite3 data/app.db "SELECT version FROM schema_migrations;"
sqlite3 data/app.db ".schema orders"
```

**Vérifications** :
- [ ] `schema_migrations` ne porte plus que `1`
- [ ] Colonne `notes` a disparu

### Étape D : Nettoyer

Supprimer la migration de test et la sauvegarde :

```bash
rm migrations/002_test.sql
rm "data/backups/app-*-avant-v2.db"
composer test  # Rebâtir la base de test
```

**Vérification** : tests passent à nouveau

---

# Parcours 4 : Distinction staging/production

## 4.1 — Contexte

Ce parcours valide que la **promotion staging → production** est un geste humain, pas automatisé.

### Hypothèse de configuration

- Branche `main` → déploiement sur `production`
- Branche `staging` → déploiement sur `staging`
- Autres branches PR → pas de déploiement

## 4.2 — Simulation : Changement en staging

### Étape A : Vérifier la branche actuelle

```bash
git branch -a
git status
```

**Vérification** : vous êtes sur `main` ou `staging`.

### Étape B : Créer une branche de test

```bash
git checkout -b test/staging-deploy
```

### Étape C : Faire un changement trivial

```bash
echo "# Test" >> README.md
git add README.md
git commit -m "test: simulation"
```

### Étape D : Pousser vers staging (simulation)

```bash
git push origin test/staging-deploy:staging -f
```

**Attention** : `-f` force ; ne pas utiliser sur une branche réelle sans `--force-with-lease`.

### Étape E : Observer le workflow CI en GitHub Actions

Accédez à `https://github.com/.../actions` → cherchez le run pour ce push.

**Vérifications** (une fois le workflow terminé) :
- [ ] Tests réussis (composer test ✓)
- [ ] Essai à blanc des migrations réussi
- [ ] Migrations appliquées réussies
- [ ] Push vers `deployed` réussi
- [ ] Branche `deployed` porte maintenant `staging/version.json` avec ce SHA

### Étape F : Vérifier que production n'est pas touchée

```bash
git show origin/deployed:production/version.json | jq .sha
```

Comparez avec le SHA avant le changement → doit être **identique** (production non touchée).

## 4.3 — Promotion staging → production (geste humain)

### Étape A : Vérifier la branche staging

```bash
git fetch
git log origin/staging -1 --oneline
git log origin/main -1 --oneline
```

**Vérification** : staging est en avance sur main (ou égal).

### Étape B : Créer une PR (simulation)

```bash
git checkout main
git pull origin main
git merge origin/staging --no-edit
git log -1 --oneline
```

Ceci simule une PR approuvée manuellement.

### Étape C : Pousser vers main

```bash
git push origin main
```

### Étape D : Observer le workflow

Accédez à GitHub Actions → cherchez le run pour ce push vers `main`.

**Vérifications** (une fois terminé) :
- [ ] Tests réussis
- [ ] Migrations appliquées
- [ ] `production/version.json` mis à jour avec le nouveau SHA
- [ ] `staging/version.json` reste inchangé (environnements distincts)

### Étape E : Nettoyer la branche de test

```bash
git checkout main
git branch -D test/staging-deploy
git push origin --delete test/staging-deploy
```

**Note** : ne **pas** utiliser `git reset --hard` ou `git push -f` — ces commandes 
écrasent l'historique. Si `main` doit revenir à un état antérieur, créer un nouveau commit 
de correction plutôt que de réécrire l'historique.

---

# Parcours 5 : Robustesse — erreurs et cas limites

## 5.1 — Base indisponible

### Étape A : Renommer temporairement la base

```bash
mv data/app.db data/app.db.bak
```

### Étape B : Appel HTTP

```bash
curl -s http://127.0.0.1:8080/orders 2>&1
```

**Vérifications** :
- [ ] Une erreur est levée (PDOException non attrapée de `public/index.php:11`)
- [ ] La réponse n'est pas du JSON valide (réponse HTML ou stack trace, selon config PHP)
- [ ] **Code HTTP ne sera probablement pas 5xx**, contrairement au comportement attendu

**Raison** : `public/index.php` n'a pas de `try/catch` global. Toute exception échappe sans 
interception ; la réponse dépend de `display_errors` de l'hôte, qui n'est pas dans le dépôt.

**Note de conception** : C'est une **Question ouverte (numéro 4)** du `QUESTIONS_OUVERTES.md` — 
la robustesse du routeur devrait être améliorée avant l'extension.

### Étape C : Restituer la base

```bash
mv data/app.db.bak data/app.db
```

## 5.2 — Fichier `deployed-version.json` corrompu

**Objectif** : Valider que le routeur gère un fichier JSON invalide sans exposer d'erreur.

### Étape A : Créer un JSON invalide

```bash
echo "{invalid json" > deployed-version.json
```

### Étape B : Requête

```bash
curl -s http://127.0.0.1:8080/version | jq .
```

**Vérifications** :
- [ ] Pas d'erreur PHP levée (suppresseur `@` en place)
- [ ] Réponse JSON valide et cohérente (pas le JSON corrompu)
- [ ] Champs `sha`, `ref`, `deployedAt` valent `null` (fallback au tableau vide)
- [ ] `schemaVersion` vaut `1` (lu depuis la base, qui est valide)
- [ ] Réponse complète :
  ```json
  {
    "sha": null,
    "ref": null,
    "deployedAt": null,
    "schemaVersion": 1
  }
  ```

**Raison** : `public/index.php:16-19` utilise `@json_decode()` et l'opérateur `+` pour merger 
un fallback. C'est une **Question ouverte (numéro 5)** du `QUESTIONS_OUVERTES.md` — la sécurité 
peut être améliorée (vérifier que `json_decode` retourne bien un array, pas null).

### Étape C : Nettoyer

```bash
rm deployed-version.json
```

## 5.3 — Absence de migration disponible

### Vérification

```bash
php bin/migrate.php
```

**Vérification** :
- [ ] Affiche : `aucune migration à appliquer`
- [ ] Quitte avec code 0 (succès)

---

# Checklist de non-régression avant tout merge

Avant de fusionner une PR, valider :

- [ ] **Tests unitaires** : `composer test` passe (zéro erreur)
- [ ] **Essai à blanc** : `php bin/migrate.php --dry-run` valide le SQL
- [ ] **Endpoints HTTP** : tous les 4 endpoints répondent en JSON
- [ ] **Distinction staging/production** : deux branches `version.json` sur `deployed` coexistent
- [ ] **Sauvegarde** : `/data/backups/` porte une copie horodatée après chaque migration appliquée
- [ ] **Intégrité des migrations** :
  - Noms : `NNN_*.sql` (trois chiffres, ordre croissant)
  - Aucun doublon sur le préfixe
  - SQL valide (essai à blanc le confirme)
- [ ] **Configuration de déploiement** : `deploy.yml` teste avant de migrer ; publie après succès

---

# Résultats attendus — Recette réussie

À la fin de tous les parcours, vous devez avoir :

| Vérification | Résultat |
|--------------|----------|
| Serveur local accessible | `127.0.0.1:8080` répond |
| `/health` | 200 OK, JSON `{status:ok}` |
| `/version` | 200 OK, JSON avec `sha/ref/schemaVersion/etc.` |
| `/orders` | 200 OK, tableau de 5 commandes |
| `/orders/{id}` (succès) | 200 OK, détail de la commande |
| `/orders/{id}` (erreur) | 404, JSON `{error}` |
| Tests unitaires | Tous passent (3/3) |
| Migrations | Essai à blanc valide, application crée sauvegarde |
| Distinction staging/production | Deux fichiers `version.json` coexistent sur `deployed` |
| Robustesse | Cas d'erreur gérés (base indisponible, JSON corrompu, etc.) |

---

## Références

- **CDC_FONCTIONNEL.md** — contrats des endpoints, règles métier
- **CARTOGRAPHIE_CODE.md** — structure du code, points critiques
- **PROJECT_CONTEXT.md** — contexte, points d'attention, hypothèses
- **GUIDE_MIGRATIONS.md** — procédure complète pour ajouter une migration
- **GUIDE_DEPLOIEMENT.md** — procédure de déploiement
