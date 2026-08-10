# Guide des migrations — shift-pilot-svc

## Vue d'ensemble

Le service utilise des migrations SQL versionnées pour modifier le schéma SQLite. Chaque
migration est :
- **Atomique** : exécutée dans sa propre transaction ; en cas d'erreur, seule cette migration
  est annulée.
- **Enregistrée** : le registre `schema_migrations` porte trace de chaque migration appliquée.
- **Sauvegardée** : avant toute mutation, une copie horodatée de la base est créée.

## Principes fondamentaux

1. **La sauvegarde est obligatoire** — un échec de copie arrête tout.
2. **Une seule migration peut échouer** — les migrations antérieures de la même exécution restent
   appliquées.
3. **La base versionnée (dans le dépôt) reste l'état de départ** — les migrations s'appliquent à
   chaque déploiement en CI contre l'état initial, non contre la base post-déploiement.

## Nommer une migration

```
migrations/NNN_description_brève.sql
```

- `NNN` : trois chiffres entiers (`001`, `002`, `003`, …)
- **Important** : ordre strictement croissant, pas de doublons
- Si vous ajoutez la migration après `003_init_clients.sql`, nommez-la `004_*.sql`, jamais `4_*.sql`

❌ **Mauvais** : `01_*.sql`, `002_*.sql`, `003_*.sql` (le tri lexicographique met `01` avant `002`)
✅ **Bon** : `001_*.sql`, `002_*.sql`, `003_*.sql`

## Structure d'une migration

```sql
-- migrations/004_ajouter_colonne_updated_at.sql

-- Crée la colonne sur la table existante
ALTER TABLE orders ADD COLUMN updated_at TEXT DEFAULT CURRENT_TIMESTAMP;
```

Notes :
- Aucun `BEGIN`, `COMMIT` — le wrapper `bin/migrate.php` gère les transactions
- Inclure un commentaire au début expliquant l'intention
- Toujours tester en `--dry-run` avant l'application réelle

## Tester une migration

### Essai à blanc (dry-run)

```bash
composer migrate:dry
# ou
php bin/migrate.php --dry-run
```

Affiche le contenu SQL de chaque migration qui s'appliquera **sans l'exécuter**. Effets
secondaires acceptables : si le fichier `data/app.db` n'existait pas, la connexion le crée à
vide (mais les migrations elles-mêmes ne s'exécutent jamais).

### Application locale réelle

```bash
php bin/migrate.php
```

1. Crée `data/backups/` s'il n'existe pas
2. Copie `data/app.db` dans `data/backups/app-<timestamp>-avant-v<N>.db`
3. Applique chaque migration dans une transaction
4. Affiche l'état final

En cas d'erreur, le script quitte avec code 1. La migration courante est annulée ; les
migrations antérieures de la même exécution restent appliquées.

### Tests PHPUnit

```bash
composer test
```

Les tests ne dépendent pas de `data/app.db` — ils reconstruisent leur propre base temporaire
via `setUp()`. Même si le fichier versionnéest corrompu, les tests peuvent réussir.

## Procédure obligatoire — Avant de déployer une migration

### En développement local

1. **Essai à blanc** :
   ```bash
   composer migrate:dry
   ```
   Vérifier que le SQL affiché est correct.

2. **Application locale** :
   ```bash
   php bin/migrate.php
   ```
   Vérifier que la migration s'applique et que la base est dans le bon état.

3. **Sauvegardes** :
   ```bash
   ls -la data/backups/
   ```
   Confirmer qu'une copie horodatée existe : `app-YYYYMMDD-HHmmss-avant-v<N>.db`

4. **Restauration de test** (optionnel, pour les migrations critiques) :
   ```bash
   # Copier la sauvegarde par-dessus la base courante
   cp data/backups/app-YYYYMMDD-HHmmss-avant-v<N>.db data/app.db
   # Vérifier que la base revient à l'état d'avant
   sqlite3 data/app.db ".schema"
   ```

5. **Tests unitaires** :
   ```bash
   composer test
   ```
   Si tout passe, la migration est prête.

6. **Commit et push** :
   ```bash
   git add migrations/NNN_*.sql
   git commit -m "migrations: Ajouter la migration NNN"
   git push origin <ma-branche>
   ```

### En CI (après merge)

- `.github/workflows/ci.yml:17-18` — essai à blanc automatique sur chaque push et PR.
- `.github/workflows/deploy.yml:35-36` — application réelle après les tests, sur `staging` ou `main`.

Si le `deploy.yml` échoue, la version reste celle du déploiement précédent (la branche
`deployed` n'est pas poussée).

## Gestion des sauvegardes

### Où

```
data/backups/app-YYYYMMDD-HHmmss-avant-v<N>.db
```

Exemple : `app-20260808-143056-avant-v2.db` — sauvegarde avant la migration v2, horodatée à
14h30m56s le 8 août 2026.

### Durée de vie

- **En développement local** : fichiers **persistants** dans le dépôt (mais gitignoré). À supprimer
  manuellement si l'espace disque pose problème.
- **En CI** : fichiers **éphémères** — supprimés après le run. Aucune sauvegarde ne persiste après
  un déploiement CI. Si un incident se produit sur l'hôte (après l'application de la migration),
  il faut disposer d'une sauvegarde externe de `data/app.db` sur l'hôte servi.

### Restauration manuelle

Si une migration échoue ou doit être annulée :

1. **Identifier la sauvegarde** :
   ```bash
   ls -lt data/backups/ | head -5
   ```

2. **Restaurer** :
   ```bash
   cp data/backups/app-20260808-143056-avant-v2.db data/app.db
   ```

3. **Vérifier** :
   ```bash
   sqlite3 data/app.db "SELECT MAX(version) FROM schema_migrations;"
   # Doit afficher 1 (version avant la migration 2)
   ```

## Risques et limitations

### Nommage et ordre

- **Tri lexicographique uniquement** : un fichier `10_*.sql` s'applique avant `002_*.sql`. Toujours
  utiliser trois chiffres (`001`, `002`, …).
- **Doublons silencieux** : si deux fichiers portent le même préfixe (ex. `002_a.sql` et
  `002_b.sql`), le premier disparaît sans avertissement. **À éviter absolutement**.

### La base versionnée — question ouverte

`data/app.db` dans le dépôt est actuellement à l'état initial (v1). Si une deuxième migration 
est ajoutée :

- **Stratégie actuelle** : la base versionnée **n'est pas mise à jour en CI**. Un checkout 
  post-déploiement donnera une base à v1, non v2. La base versionnée reste l'état zéro d'où 
  les migrations s'appliquent.
- **Risque** : après un déploiement qui inclut la migration 2, un checkout du dépôt donnera une 
  base à v1, créant un décalage entre le schéma versionné et les migrations présentes.
- **Décision requise** : faut-il mettre à jour `data/app.db` automatiquement en CI après chaque 
  nouvelle migration, ou accepter que la base versionnée reste à v1 et que toutes les migrations 
  s'appliquent à chaque déploiement ? Voir `QUESTIONS_OUVERTES.md`.

### Sauvegardes en CI

Les sauvegardes dans `data/backups/` sont **éphémères en CI** — créées, puis supprimées après 
le run GitHub Actions. Aucune sauvegarde ne persiste sur le serveur après un déploiement CI.

**Conséquence** : en production, un incident après l'application d'une migration exige une 
sauvegarde **externe** de `data/app.db` créée **avant** le déploiement (sur l'hôte, système 
de fichiers, ou service de backup). Les sauvegardes en CI ne servent qu'à l'isolation locale 
du run — une migration en échec rejette immédiatement, sans qu'une sauvegarde CI ne puisse 
aider au rollback.

## Exemples

### Ajouter une colonne

```sql
-- migrations/002_ajouter_email_client.sql
ALTER TABLE orders ADD COLUMN email_client TEXT DEFAULT NULL;
```

### Insérer des données de configuration

```sql
-- migrations/003_ajouter_devises.sql
CREATE TABLE IF NOT EXISTS devises (
  code TEXT PRIMARY KEY,
  nom TEXT NOT NULL
);

INSERT INTO devises (code, nom) VALUES ('XPF', 'Franc CFP');
INSERT INTO devises (code, nom) VALUES ('EUR', 'Euro');
```

### Ajouter une contrainte

```sql
-- migrations/004_contraindre_statut.sql
-- Ajouter une CHECK sur statut (demande une migration de données en deux étapes)
ALTER TABLE orders ADD CONSTRAINT check_statut 
  CHECK (statut IN ('payee', 'annulee', 'en_attente'));
```

## Questions fréquentes

**Q: Puis-je modifier une migration qui n'a jamais été appliquée ?**
Non. Une fois commise et poussée, une migration doit être versionnée comme-est. Si un problème
est découvert, la corriger dans une nouvelle migration (`003_correction_de_002.sql`).

**Q: Puis-je supprimer une migration ?**
Non. Même si elle n'a jamais été appliquée en production, la supprimer risquerait de casser
les checkouts sur les branches qui la contiennent. Marquer-la comme obsolète dans une
nouvelle migration si nécessaire.

**Q: Que se passe-t-il si une migration échoue en déploiement ?**
1. La migration courante est annulée (ROLLBACK par l'instruction `beginTransaction()` du wrapper)
2. Les migrations antérieures de la même exécution **restent appliquées** — la base n'est pas 
   automatiquement restaurée à l'état pré-déploiement
3. Le script `bin/migrate.php` quitte avec code 1
4. Le workflow `deploy.yml` s'arrête — la branche `deployed` n'est pas poussée
5. La version sur `deployed` reste celle du déploiement précédent (source de vérité)
6. **Important** : la base live sur l'hôte est dans un état partiel (certaines migrations 
   appliquées, une échouée). Il faut soit restaurer manuellement depuis une sauvegarde externe, 
   soit fixer le code et redéployer.

**Q: Peut-on rollback manuel une migration ?**
Pas de commande `composer rollback`. Il faut restaurer manuellement une sauvegarde :
```bash
cp data/backups/app-...-avant-v<N>.db data/app.db
```
Puis supprimer la ligne correspondante dans `schema_migrations` si nécessaire.

**Q: Puis-je utiliser des transactions explicites dans une migration ?**
Non. Le wrapper `bin/migrate.php:63` utilise `beginTransaction()` / `commit()` / `rollBack()`.
Ajouter `BEGIN` ou `COMMIT` dans le SQL risque de produire des erreurs ou des comportements
inattendus. Laisser le wrapper gérer les transactions.

## Pour aller plus loin

- Voir `README.md` pour la vue d'ensemble du service
- Voir `.github/workflows/deploy.yml` pour la procédure CI
- Voir `bin/migrate.php` pour les détails techniques du migrateur
