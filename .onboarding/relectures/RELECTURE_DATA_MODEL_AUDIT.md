# Relecture — DATA_MODEL_AUDIT.md

## Verdict global
À corriger — le schéma SQL est bien décrit, mais l'audit attribue à `VÉRIFIÉ_CODE` des faits qui nécessitent une lecture effective de `data/app.db`, et il ne relève pas un défaut concret du mécanisme de migration : plusieurs fichiers peuvent partager un même préfixe numérique et l'un écrase silencieusement l'autre dans `$aFaire`.

## Problèmes bloquants

- **Base réelle non qualifiée.** « `schema_migrations` contient une ligne », « 5 lignes », timestamp précis et « intégrité confirmée » ne sont pas prouvés par `migrations/001_init.sql` : ce SQL crée les tables et les commandes mais n'insère pas la ligne de registre. Ces assertions doivent être `OBSERVÉ` avec une requête/commande et un résultat minimal, pas `VÉRIFIÉ_CODE`; à défaut elles sont `INCONNU`.
- **Risque de cycle de vie trop affirmatif.** `deploy.yml:35-36` montre une mutation du checkout du runner, mais ne prouve pas quel fichier est réellement servi ni la stratégie opérationnelle de persistance. Le risque de désynchronisation est plausible, pas un état déjà établi; conserver `HYPOTHÈSE` dans le résumé et la gravité.
- **Défaut non couvert.** `bin/migrate.php:24-30` valide seulement l'existence d'un motif numérique puis écrit dans `$aFaire[(int)$m[1]]`. Deux fichiers `002_*.sql` font collision et le second remplace le premier silencieusement. Le rapport doit l'ajouter comme risque concret, distinct du simple tri lexicographique.

## Problèmes mineurs

- L'absence de `CHECK` n'est un risque que si des écritures/futures migrations utilisent ces colonnes; le rapport le dit parfois « nul » et parfois « incohérence silencieuse ». Harmoniser la qualification conditionnelle.
- « Registre immuable » est inexact : la clé primaire empêche les doublons, mais aucune protection ne rend la table immuable (`migrations/001_init.sql:2-5`).

## Points vérifiés et corrects

- Les deux tables, colonnes `NOT NULL` et types du schéma sont bien lisibles dans `migrations/001_init.sql:2-13`.
- Le tri `sort()` et le filtrage `(\int)$m[1] > $courante` sont bien présents (`bin/migrate.php:20-30`).

## Recommandations de correction

1. Refaire la section base réelle avec statut `OBSERVÉ` et preuve reproductible, sans recopier de données personnelles.
2. Ajouter collision de versions/préfixes et proposer un refus explicite des doublons.
3. Remplacer « registre immuable » par « registre avec clé primaire, modifiable par SQL ».
