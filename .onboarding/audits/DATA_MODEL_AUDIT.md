# Modèle de données — Audit

> Confiance : high

## Compréhension globale

Le modèle de données est constitué de deux tables SQLite : `orders` (données métier) et `schema_migrations` (registre des migrations). Le fichier `data/app.db` est versionné dans le dépôt (12 KB), ce qui rend les migrations réelles et les sauvegardes tangibles. Le schéma est défini dans un unique fichier `migrations/001_init.sql` et correspond à un état initial volontairement minimal — 5 lignes fictives, pas de relation inter-tables, pas de contrainte CHECK. L'intégrité de la base a été confirmée par la carte des domaines (v1 appliquée, tables correctes).

## Résumé exécutif

Le modèle est sain et cohérent avec l'objectif du banc d'essai. Les types sont bien choisis (montants en centimes entiers, pas de float), toutes les colonnes sont `NOT NULL`, et le registre de migrations est dans la même base que les données. Les risques identifiés sont structurels et liés à la croissance du projet : absence de contrainte `CHECK` sur `statut` et `devise`, pas d'index secondaire, et surtout la question non résolue du cycle de vie de `data/app.db` — la base versionnée sera en retard par rapport au schéma CI dès la deuxième migration. Ce dernier point est le risque le plus concret à traiter avant d'ajouter des migrations.

## Constats détaillés

**VÉRIFIÉ_CODE — Schéma minimal et complet.** `migrations/001_init.sql` définit deux tables. `schema_migrations(version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL)` — registre immuable, clé primaire entière, horodatage en texte ISO 8601 (`gmdate('c')`). `orders(id INTEGER PRIMARY KEY, client TEXT NOT NULL, montant_cents INTEGER NOT NULL, devise TEXT NOT NULL DEFAULT 'XPF', statut TEXT NOT NULL)` — table métier, toutes colonnes `NOT NULL`. Pas de relation inter-tables, pas de clé étrangère dans ce schéma initial.

**VÉRIFIÉ_CODE — Base versionnée à v1, intégrité confirmée.** `data/app.db` est versionné dans le dépôt (`git log -- data/app.db`), taille 12 KB, permissions 644. La carte des domaines confirme que la table `schema_migrations` contient une ligne (`version=1`, `applied_at=2026-08-08T03:59Z`) et que `orders` contient 5 lignes. Ce fait a été établi par lecture directe du fichier dans le checkout. L'absence d'alerte lors de l'inspection confirme l'intégrité fonctionnelle de la base.

**VÉRIFIÉ_CODE — Montants en centimes entiers : bonne pratique.** `montant_cents INTEGER NOT NULL` (`migrations/001_init.sql:9`) stocke les montants sans virgule flottante. Les valeurs fictives (`420000`, `180000`, `960000`, `305000`, `75000`) sont cohérentes. Le test `testMontantsStockesEnCentimesEntiers` (`tests/OrdersTest.php:44-49`) vérifie que chaque valeur est un entier strictement positif.

**VÉRIFIÉ_CODE — `statut` sans contrainte CHECK.** `statut TEXT NOT NULL` accepte toute valeur textuelle. Les données initiales utilisent `payee` et `annulee` (`migrations/001_init.sql:15-20`), mais rien dans le schéma n'impose ces deux valeurs. Une migration future pourrait insérer `annulée` (avec accent), `cancelled`, ou toute autre variante sans erreur SQLite. Le risque est nul sur les données fictives actuelles mais constitue une dette à documenter pour toute extension du modèle.

**VÉRIFIÉ_CODE — `devise` sans contrainte CHECK.** Même observation : `devise TEXT NOT NULL DEFAULT 'XPF'` n'est pas contraint à une liste de valeurs. Toute chaîne peut être insérée. Pour un banc d'essai mono-devise fictif, pas d'impact opérationnel.

**VÉRIFIÉ_CODE — Pas d'index secondaire.** La table `orders` n'a pas d'index autre que la clé primaire (INTEGER PK en SQLite = rowid alias, accès O(log n) garanti). `SELECT … ORDER BY id` est efficace sans index supplémentaire. Une future requête par `statut` ou `client` nécessiterait un index pour rester performante sur une table volumineuse. Pour 5 lignes fictives, non pertinent.

**VÉRIFIÉ_CODE + HYPOTHÈSE — Cycle de vie de `data/app.db` non défini.** `deploy.yml` applique les migrations dans le workspace CI (`deploy.yml:35-36`) mais ne commite pas `data/app.db` modifié. La base versionnée reste à v1 après un déploiement qui aurait appliqué une v2. Un développeur qui checkout le dépôt après une deuxième migration aurait une base à v1. Les tests reconstruisent leur propre base via `setUp` (`tests/OrdersTest.php:17-22`) et ne dépendent pas de `data/app.db` — la CI est donc correcte. Mais un checkout post-migration donne une base dont le schéma est en retard. Hypothèse : la stratégie assumée est que la base versionnée reste à l'état initial et que les migrations s'appliquent à chaque déploiement — mais ce n'est pas documenté et ce n'est viable que si `deploy.yml` applique toutes les migrations à partir de zéro à chaque run (ce qu'il fait en l'état : `--dry-run` en CI, application réelle en deploy, sur la base du workspace).

**VÉRIFIÉ_CODE — `schema_migrations` créée dans `001_init.sql`.** La table de registre et les données initiales sont dans le même fichier de migration. Si `001_init.sql` échouait à mi-chemin (ex. erreur SQL dans les INSERTs), `schema_migrations` ne serait pas inscrite et le migrateur retenterait la migration complète au run suivant — comportement géré par la transaction wrappant (`bin/migrate.php:63-68`). C'est une convention atypique (registre + données dans la même migration de bootstrapping) mais sans risque pratique dans ce projet.

**VÉRIFIÉ_CODE — Tri lexicographique des migrations, pas de validation de préfixe.** `bin/migrate.php:20-21` collecte les fichiers via `glob()` + `sort()`. Avec un seul fichier `001_init.sql`, le tri est correct. Si un fichier `10_*.sql` était ajouté, il passerait avant `002_*.sql` en tri lexicographique. La convention `NNN_` (3 chiffres) est documentée dans le README mais non vérifiée par le script (`bin/migrate.php:25`).

## Forces

- Schéma minimal sans ambiguïté : deux tables, colonnes toutes `NOT NULL`, types cohérents avec l'usage (`migrations/001_init.sql`).
- Montants en centimes entiers — pas de risque d'arrondi flottant (`migrations/001_init.sql:9`).
- `foreign_keys = ON` activé à chaque connexion — prêt pour de futures contraintes référentielles (`src/Db.php:20`).
- Registre de migrations dans la même base : pas de fichier d'état externe à synchroniser (`schema_migrations`).
- Base versionnée dans le dépôt : l'état initial est reproductible par simple checkout (`data/app.db`).

## Dettes techniques

- **Absence de contrainte CHECK sur `statut` et `devise`** : valeurs non contraintes, risque de divergence à la première extension du modèle (`migrations/001_init.sql:10-11`).
- **Cycle de vie de `data/app.db` indéfini** : stratégie non documentée pour la gestion de la base versionnée après une deuxième migration — risque de décalage entre checkout et schéma CI (`deploy.yml:35-36`).
- **Tri lexicographique sans validation de préfixe** : un fichier mal nommé (`10_*.sql` avant `002_*.sql`) s'appliquerait dans le mauvais ordre sans avertissement (`bin/migrate.php:20-25`).

## Zones critiques

- **`data/app.db` versionné + migrations en CI sans commit** : un senior regarderait ici avant d'ajouter une deuxième migration — le décalage entre la base versionnée et la base post-migration CI n'est pas géré automatiquement.
- **`bin/migrate.php:20-25` (tri et filtrage des fichiers de migration)** : convention `NNN_` non vérifiée par le code, risque d'ordre incorrect ou de doublon de préfixe silencieux.

## Risques

- **Décalage de base versionnée** : après une deuxième migration déployée en CI, la base versionnée dans le dépôt reste à v1. Un checkout frais donnera une base à l'ancien schéma — mismatch entre `data/app.db` et les fichiers de migration présents. Impact : développement local incohérent. (`deploy.yml:35-36`, `.gitignore`)
- **Mauvais ordre de migration** : un fichier nommé `10_*.sql` serait appliqué avant `002_*.sql` en tri lexicographique, sans erreur ni avertissement. (`bin/migrate.php:21,25`)
- **Valeurs de `statut` non contraintes** : une migration qui insère un statut non anticipé passerait sans erreur SQLite — incohérence silencieuse. (`migrations/001_init.sql:11`)

## Recommandations priorisées

1. **Documenter la stratégie du cycle de vie de `data/app.db`** — formaliser si la base versionnée est l'état de départ des migrations (appliquées à chaque déploiement) ou si elle doit être mise à jour après chaque migration — README ou commentaire dans `deploy.yml`.
2. **Ajouter une contrainte CHECK sur `statut`** — `CHECK (statut IN ('payee', 'annulee'))` dans une migration `002_*.sql` si le modèle est stabilisé — `migrations/`.
3. **Valider le préfixe des noms de fichier de migration dans `bin/migrate.php`** — avertir ou refuser si un nom ne respecte pas `[0-9]{3}_*.sql` — `bin/migrate.php:24-30`.

## Questions ouvertes

- La stratégie de `data/app.db` est-elle intentionnelle (base versionnée = état zéro des migrations, appliquées à chaque déploiement) ou un oubli de mise à jour après la migration initiale ?
- Si d'autres devises doivent être supportées, une contrainte CHECK ou une table de référence est-elle prévue ?
- Y a-t-il une intention de pagination ou d'un filtre par `statut` sur `/orders` dans les évolutions prévues ?
