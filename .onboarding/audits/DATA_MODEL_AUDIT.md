# Modèle de données — Audit

> Confiance : medium — le schéma SQL est vérifiable par lecture de `migrations/001_init.sql` ; le contenu réel de `data/app.db` (lignes, timestamps) est une observation externe non reproductible par lecture de code seule ; la stratégie de cycle de vie de la base est une hypothèse.

## Compréhension globale

Le modèle de données est constitué de deux tables SQLite : `orders` (données métier) et `schema_migrations` (registre des migrations). Le fichier `data/app.db` est versionné dans le dépôt (12 KB), ce qui rend les migrations réelles et les sauvegardes tangibles. Le schéma est défini dans un unique fichier `migrations/001_init.sql` et correspond à un état initial volontairement minimal — données fictives, pas de relation inter-tables, pas de contrainte CHECK. L'intégrité de la base a été signalée par la carte des domaines (workflow agent précédent), mais n'a pas été vérifiée par requête SQL directe dans cet audit.

## Résumé exécutif

Le modèle est sain et cohérent avec l'objectif du banc d'essai. Les types sont bien choisis (montants en centimes entiers, pas de float), toutes les colonnes sont `NOT NULL`, et le registre de migrations est dans la même base que les données. Les risques identifiés sont structurels et liés à la croissance du projet : absence de contrainte `CHECK` sur `statut` et `devise`, pas d'index secondaire, et surtout la collision silencieuse de préfixes dans le mécanisme de migration. Le cycle de vie de `data/app.db` (base versionnée vs base post-migration CI) est une question stratégique non documentée — hypothèse que la base versionnée constitue l'état zéro, mais ce n'est pas formalisé.

## Constats détaillés

**VÉRIFIÉ_CODE — Schéma minimal et complet.** `migrations/001_init.sql` définit deux tables. `schema_migrations(version INTEGER PRIMARY KEY, applied_at TEXT NOT NULL)` — registre avec clé primaire entière qui empêche les doublons, mais modifiable par SQL direct (aucune protection ne rend la table immuable). `orders(id INTEGER PRIMARY KEY, client TEXT NOT NULL, montant_cents INTEGER NOT NULL, devise TEXT NOT NULL DEFAULT 'XPF', statut TEXT NOT NULL)` — table métier, toutes colonnes `NOT NULL`. Pas de relation inter-tables, pas de clé étrangère dans ce schéma initial.

**VÉRIFIÉ_CODE + OBSERVÉ — Base versionnée.** `data/app.db` est versionné dans le dépôt — VÉRIFIÉ_CODE par `git ls-files data/app.db` (retourne `data/app.db`). Taille et permissions observées dans ce checkout (2026-08-08) : `ls -la data/app.db` → 12288 octets (12 KB), permissions `rw-r--r--` (644), propriétaire `node`. Le contenu de la base (nombre de lignes dans `schema_migrations` et `orders`, timestamp `applied_at`) ne peut pas être établi par lecture du code source ou des migrations seules : `migrations/001_init.sql` crée les tables et insère des données dans `orders`, mais n'insère pas de ligne dans `schema_migrations` (c'est `bin/migrate.php` qui le fait à l'exécution). Toute assertion sur le contenu actuel de `data/app.db` sans commande SQL reproductible (`sqlite3 data/app.db "SELECT COUNT(*) FROM ..."`) est `INCONNU`. La carte des domaines (workflow agent précédent) a signalé `schema_migrations = 1 ligne, orders = 5 lignes` — non re-vérifiable dans cet audit sans exécution SQL.

**VÉRIFIÉ_CODE — Montants en centimes entiers : bonne pratique.** `montant_cents INTEGER NOT NULL` (`migrations/001_init.sql:9`) stocke les montants sans virgule flottante. Le test `testMontantsStockesEnCentimesEntiers` (`tests/OrdersTest.php:44-49`) vérifie que chaque valeur retournée par `Orders::all()` est un entier strictement positif.

**VÉRIFIÉ_CODE — `statut` sans contrainte CHECK.** `statut TEXT NOT NULL` accepte toute valeur textuelle. Les données initiales utilisent `payee` et `annulee` (`migrations/001_init.sql:15-20`), mais rien dans le schéma n'impose ces deux valeurs. Une migration future pourrait insérer `annulée` (avec accent), `cancelled`, ou toute autre variante sans erreur SQLite. Aucune valeur hors vocabulaire n'est présente dans les données initiales du SQL (`migrations/001_init.sql:15-20`) ; le contenu réel de `data/app.db` n'ayant pas été vérifié par requête SQL dans cet audit (INCONNU), affirmer un risque nul sur les données actuelles serait surqualifier. Le risque est conditionnel au contenu de la base live et constitue une dette à documenter pour toute extension du modèle.

**VÉRIFIÉ_CODE — `devise` sans contrainte CHECK.** Même observation : `devise TEXT NOT NULL DEFAULT 'XPF'` n'est pas contraint à une liste de valeurs. Toute chaîne peut être insérée. Aucune devise hors vocabulaire n'est observable dans les données initiales du SQL (`migrations/001_init.sql:15-20`) ; le contenu réel de `data/app.db` n'ayant pas été vérifié dans cet audit (INCONNU), l'absence d'impact opérationnel ne peut être affirmée — elle reste conditionnelle au périmètre d'utilisation et au contenu de la base live.

**VÉRIFIÉ_CODE — Pas d'index secondaire.** La table `orders` n'a pas d'index autre que la clé primaire (INTEGER PK en SQLite = rowid alias, accès O(log n) garanti). `SELECT … ORDER BY id` est efficace sans index supplémentaire. Une future requête par `statut` ou `client` nécessiterait un index pour rester performante sur une table volumineuse. Pour les données fictives actuelles, non pertinent.

**HYPOTHÈSE — Cycle de vie de `data/app.db` non défini.** `deploy.yml:35-36` applique les migrations dans le workspace CI mais ne commite pas `data/app.db` modifié. Hypothèse : la stratégie assumée est que la base versionnée reste à l'état initial et que les migrations s'appliquent à chaque déploiement sur la base du workspace — mais ce n'est pas documenté. Si cette hypothèse est correcte, un checkout post-migration donne une base à l'ancien schéma — mismatch possible entre `data/app.db` et les fichiers de migration présents. Les tests reconstruisent leur propre base via `setUp` (`tests/OrdersTest.php:17-22`) et ne dépendent pas de `data/app.db` — la CI est donc correcte indépendamment de cette question.

**VÉRIFIÉ_CODE — Collision silencieuse de préfixes dans le mécanisme de migration.** `bin/migrate.php:24-30` construit `$aFaire[(int) $m[1]] = $f`. Si deux fichiers portent le même préfixe numérique entier (ex. `002_a.sql` et `002_b.sql`), la valeur pour la clé `2` est d'abord assignée au premier fichier (tri `sort()`) puis silencieusement écrasée par le second. Le premier fichier de migration disparaît sans erreur ni avertissement. Ce défaut de conception est distinct du seul problème d'ordre lexicographique.

**VÉRIFIÉ_CODE (structure) + HYPOTHÈSE (effet transactionnel) — `schema_migrations` créée dans `001_init.sql`.** La table de registre et les données initiales sont dans le même fichier de migration (VÉRIFIÉ_CODE). `bin/migrate.php:63-68` wrappe chaque migration dans `beginTransaction` / `exec` / `insert schema_migrations` / `commit` avec `rollBack` sur exception (VÉRIFIÉ_CODE). HYPOTHÈSE : si `001_init.sql` échoue à mi-chemin, le `rollBack` annulerait l'ensemble de la transaction — y compris les DDL — et `schema_migrations` resterait vide, permettant une relance propre. Cet effet est plausible (SQLite supporte les DDL transactionnels), mais il dépend du mode d'échec exact et n'a pas été vérifié par exécution dans cet audit. Éviter "garantit" sans test identifié. Convention atypique mais sans risque pratique connu dans ce projet.

**VÉRIFIÉ_CODE — Tri lexicographique des migrations, pas de validation de préfixe.** `bin/migrate.php:20-21` collecte les fichiers via `glob()` + `sort()`. Avec un seul fichier `001_init.sql`, le tri est correct. Si un fichier `10_*.sql` était ajouté, il passerait avant `002_*.sql` en tri lexicographique. La convention `NNN_` (3 chiffres) est documentée dans le README mais non vérifiée par le script.

## Forces

- Schéma minimal sans ambiguïté : deux tables, colonnes toutes `NOT NULL`, types cohérents avec l'usage (`migrations/001_init.sql`).
- Montants en centimes entiers — pas de risque d'arrondi flottant (`migrations/001_init.sql:9`).
- `foreign_keys = ON` activé à chaque connexion — prêt pour de futures contraintes référentielles (`src/Db.php:20`).
- Registre de migrations dans la même base : pas de fichier d'état externe à synchroniser (`schema_migrations`).
- Base versionnée dans le dépôt : l'état initial est reproductible par simple checkout (`data/app.db`).

## Dettes techniques

- **Absence de contrainte CHECK sur `statut` et `devise`** : valeurs non contraintes, risque de divergence à la première extension du modèle (`migrations/001_init.sql:10-11`).
- **Cycle de vie de `data/app.db` non documenté** : stratégie non formalisée pour la gestion de la base versionnée après une deuxième migration (`deploy.yml:35-36`).
- **Collision silencieuse de préfixes** : deux fichiers avec le même numéro entier font disparaître le premier sans avertissement (`bin/migrate.php:24-30`).
- **Tri lexicographique sans validation de préfixe** : un fichier mal nommé s'appliquerait dans le mauvais ordre sans avertissement (`bin/migrate.php:20-25`).

## Zones critiques

- **`data/app.db` versionné + migrations en CI sans commit** : un senior regarderait ici avant d'ajouter une deuxième migration — le décalage entre la base versionnée et la base post-migration CI n'est pas géré automatiquement.
- **`bin/migrate.php:24-30` (construction de `$aFaire`)** : convention `NNN_` non vérifiée, collision de préfixe silencieuse — deux défauts distincts dans le même bloc.

## Risques

- **Collision de préfixes numériques** : deux fichiers `002_*.sql` font que le premier est silencieusement ignoré — migration perdue sans erreur ni avertissement. Impact : état de base imprévisible après migration. (`bin/migrate.php:24-30`)
- **Décalage de base versionnée (hypothèse)** : si la stratégie est bien que la base versionnée reste à v1, après une deuxième migration déployée, un checkout frais donnera une base à l'ancien schéma. Impact conditionnel à la stratégie effective. (`deploy.yml:35-36`)
- **Mauvais ordre de migration** : un fichier nommé `10_*.sql` serait appliqué avant `002_*.sql` en tri lexicographique, sans erreur ni avertissement. (`bin/migrate.php:21,25`)
- **Valeurs de `statut` non contraintes** : une migration qui insère un statut non anticipé passerait sans erreur SQLite — incohérence silencieuse. (`migrations/001_init.sql:11`)

## Recommandations priorisées

1. **Détecter et refuser les doublons de préfixe dans `bin/migrate.php`** — comparer le nombre de fichiers filtrés avec le nombre de clés uniques dans `$aFaire` ; sortir en erreur avec la liste des doublons si mismatch — `bin/migrate.php:24-30`.
2. **Documenter la stratégie du cycle de vie de `data/app.db`** — formaliser si la base versionnée est l'état de départ des migrations (appliquées à chaque déploiement) ou si elle doit être mise à jour après chaque migration — README ou commentaire dans `deploy.yml`.
3. **Ajouter une contrainte CHECK sur `statut`** — `CHECK (statut IN ('payee', 'annulee'))` dans une migration `002_*.sql` si le modèle est stabilisé — `migrations/`.
4. **Valider le préfixe des noms de fichier dans `bin/migrate.php`** — avertir ou refuser si un nom ne respecte pas `[0-9]{3}_*.sql` — `bin/migrate.php:24-30`.

## Questions ouvertes

- La stratégie de `data/app.db` est-elle intentionnelle (base versionnée = état zéro des migrations, appliquées à chaque déploiement) ou un oubli de mise à jour après la migration initiale ?
- Si d'autres devises doivent être supportées, une contrainte CHECK ou une table de référence est-elle prévue ?
- Y a-t-il une intention de pagination ou d'un filtre par `statut` sur `/orders` dans les évolutions prévues ?
