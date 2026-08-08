# GUIDE_MIGRATIONS — Appliquer les migrations de schéma

> **Dernière mise à jour** : 2026-08-08  
> **Cible** : Développeurs locaux et agents CI/CD  
> **Source de vérité** : `.onboarding/workflows/WORKFLOW_MIGRATION.md` (détails techniques)

## Vue d'ensemble

Le service utilise des migrations SQL versionnées pour modifier le schéma SQLite. Le mécanisme garantit :
- **Sauvegarde obligatoire** avant toute modification
- **Atomicité par migration** — une erreur annule la migration courante uniquement
- **Essai à blanc** — afficher le SQL avant l'exécution

Les migrations s'exécutent en trois contextes :
1. **Développement local** — avant les tests et avant de pousser
2. **CI sur PR** — essai à blanc pour alerte précoce
3. **Déploiement (CI après merge)** — application réelle après les tests verts

---

## Contexte 1 — Développement local

### Essai à blanc (recommandé avant chaque commit)

```bash
cd ~/shift-pilot-svc
composer migrate:dry
```

Affiche le contenu SQL de tous les fichiers de migration à appliquer, sans modifier la base.

**Cas 1** : Aucune migration en attente (base déjà à jour)
```
Version de schéma courante : 1
Aucune migration à appliquer
```

**Cas 2** : Une ou plusieurs migrations en attente
```
Version de schéma courante : 1
À appliquer : migrations/002_add_field.sql
[Contenu SQL du fichier]
```

### Application réelle

```bash
composer migrate
```

**Étapes garanties** :
1. Création de `data/backups/` s'il n'existe pas
2. Sauvegarde horodatée : `data/backups/app-<YYYYMMDD-HHmmss>-avant-v<N>.db`
   - Si la sauvegarde échoue → script quitte avec `exit(1)`, **aucune migration n'est appliquée**
3. Application de chaque migration dans une transaction
   - Chaque fichier de migration s'exécute dans son propre `BEGIN TRANSACTION ... COMMIT`
   - En cas d'erreur SQL, cette migration est rollbackée ; les migrations précédentes restent appliquées
4. Enregistrement de la version dans `schema_migrations`
5. Affichage de la version finale

**Exemple de sortie nominale** :
```
Version de schéma courante : 0
À appliquer : migrations/001_init.sql
Sauvegarde obligatoire créée : data/backups/app-20260808-120530-avant-v1.db
Version de schéma finale : 1
```

---

## Contexte 2 — CI sur PR (essai à blanc uniquement)

Chaque PR (pull request) déclenche le workflow `ci.yml`, qui exécute :

```bash
composer migrate:dry   # essai à blanc — aucune modification
composer test          # suite de tests PHPUnit
```

**Important** : L'essai à blanc s'exécute **à chaque PR et à chaque push**, pas seulement après merge. Cet automatis me permet de détecter **immédiatement** les erreurs SQL avant qu'elles ne se propagent en déploiement.

**Résultat attendu** :
- Affichage du SQL à appliquer (ou « aucune migration »)
- Tests passent ✓
- Tous les checks GitHub passent ✓

Si l'essai à blanc échoue (erreur SQL détectée) ou si un test échoue, le workflow s'arrête et **aucun merge n'est possible** jusqu'à correction.

---

## Contexte 3 — Déploiement (application réelle après merge)

Après un merge réussi sur `staging` ou `main`, le workflow `deploy.yml` s'exécute :

```bash
composer test              # (re)tests avant déploiement
php bin/migrate.php        # application réelle
# publication de version.json sur la branche deployed
```

**Garanties** :
- Les tests passent ✓ (même vérification qu'en CI)
- Les migrations sont appliquées **dans la même étape** que le déploiement
- La version publiée sur `deployed/<env>/version.json` porte le SHA et la `schemaVersion` post-migration
- En cas d'erreur — tests échoue ou migration échoue → **aucune nouvelle version n'est publiée** sur la branche Git

**Résultat** :
- Artefact Git mis à jour : `deployed/<env>/version.json` (tracé dans l'historique)
- Copie sur l'hôte : `deployed-version.json` (mécanisme hors dépôt)

---

## Fichiers de migration

### Emplacement et nommage

Fichiers : `migrations/*.sql`, triés lexicographiquement (ordre : `001_init.sql`, `002_*.sql`, etc.)

**Convention** : `NNN_description.sql`
- `NNN` = préfixe numérique strictement croissant (001, 002, …, 010, …)
- Préfixe doit être numérique pour permettre le filtrage `(int) $m[1] > $courante`

### Contenu d'une migration

Chaque fichier contient du SQL pur, exécuté dans une transaction :

```sql
-- migrations/002_add_status_column.sql
ALTER TABLE orders ADD COLUMN status TEXT DEFAULT 'draft';
INSERT INTO schema_migrations (version, applied_at) VALUES (2, datetime('now', 'utc'));
```

**Attention** : Le registre `schema_migrations` est géré **automatiquement par le migrateur**. Ne pas l'insérer manuellement — le script le fait pour vous.

---

## Procédure en cas d'erreur

### Migration échoue en développement

Si `composer migrate` échoue :

1. **Message d'erreur** : Affiche le contenu de la requête qui a échoué et la sortie SQL
2. **État de la base** :
   - La migration courante est **rollbackée** (annulée)
   - Les migrations **précédentes appliquées restent persistées**
   - Une sauvegarde a été créée : `data/backups/app-*-avant-v<N>.db` (restauration manuelle possible si besoin)
3. **Prochaine action** :
   - Corriger le fichier de migration
   - Committer la correction
   - Rejouer `composer migrate`

### Migration échoue en déploiement (CI)

Si le déploiement échoue (tests ou migrations) :

1. **Workflow s'arrête** — aucun merge n'aboutit
2. **Aucune nouvelle version n'est publiée** sur la branche Git
3. **Intervention** :
   - Corriger le problème dans la branche
   - Créer une nouvelle PR
   - Rejouer les checks

---

## Rollback et restauration

### Rollback transactionnel (automatique)

Si une migration échoue, la migration courante est annulée automatiquement :

```bash
composer migrate
# Migration 002 échoue → elle est rollbackée
# Migration 001 (appliquée précédemment) reste dans la base
```

**Couverture** : Rollback de la migration courante uniquement, pas des migrations précédentes.

### Restauration d'une sauvegarde antérieure (manuel)

Pour restaurer une base entière depuis une sauvegarde :

```bash
# 1. Arrêter le service web
sudo systemctl stop shift-pilot-svc

# 2. Localiser la sauvegarde
ls data/backups/app-*-avant-v*.db

# 3. Restaurer manuellement (remplacer <fichier> par le nom du backup)
cp data/backups/app-<YYYYMMDD-HHmmss>-avant-v<N>.db data/app.db

# 3. Redémarrer le service
sudo systemctl start shift-pilot-svc

# 4. Vérifier l'état de la base
sqlite3 data/app.db "SELECT MAX(version) FROM schema_migrations;"
```

**Important** : Cette procédure est **entièrement manuelle**. Aucun script ne la pilote. Il revient à l'équipe Ops de :
- Monitorer les alertes (endpoints `/health`, `/version`)
- Exécuter les commandes de restauration
- Tester la restauration en environnement de préproduction

---

## Vérifications recommandées

### Avant de pousser un changement

```bash
# 1. Essai à blanc local
composer migrate:dry

# 2. Lancer les tests
composer test

# 3. Lancer le serveur et tester manuellement
php -S 127.0.0.1:8080 -t public
curl http://127.0.0.1:8080/orders
```

### Après un déploiement

```bash
# 1. Vérifier la version publiée
git fetch origin deployed
git show origin/deployed:staging/version.json | jq .

# 2. Vérifier l'endpoint /version sur le serveur (si accès)
curl https://prod.example.com/version | jq .

# 3. Vérifier que /health répond
curl https://prod.example.com/health
```

---

## Questions courantes

**Q. Peux-je créer deux migrations en même temps (numéros 002 et 003) ?**  
R. Oui, mais créez-les en ordre croissant et testez-les ensemble. Le migrateur les applique en ordre lexicographique.

**Q. Que se passe-t-il si une migration a une syntaxe SQL invalide ?**  
R. L'essai à blanc (`--dry-run`) **n'affiche que le contenu** — aucune validation SQL n'est faite. L'erreur sera détectée lors de l'application réelle. C'est pourquoi le CI teste toujours les migrations sur une branche `deployed`.

**Q. Peux-je modifier `data/app.db` directement en SQL ?**  
R. Déconseillé. Tous les changements doivent passer par des fichiers de migration versionnés. Modification directe = perte du traçage dans `schema_migrations`.

**Q. Ma base est corrompue — comment la réinitialiser ?**  
R. Supprimer `data/app.db` et rejouer les migrations depuis zéro :
```bash
rm data/app.db
composer migrate
```

---

## Références

- Workflow technique complet : `.onboarding/workflows/WORKFLOW_MIGRATION.md`
- Cahier de recette, Phase 2 : `.onboarding/CAHIER_RECETTE.md` (étapes détaillées pour tester)
- Tests : `tests/OrdersTest.php` (vérification du schéma et des données)
