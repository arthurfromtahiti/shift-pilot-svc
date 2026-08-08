# Relecture — DATA_MODEL_AUDIT.md

## Verdict global
Acceptable avec réserves — le schéma SQL, l'observation filesystem et la collision de préfixes sont correctement sourcés et qualifiés. Le cycle de vie de la base reste explicitement hypothétique.

## Problèmes bloquants

- Aucun défaut bloquant dans la version examinée. La base réelle est explicitement limitée à l'observation `git ls-files`/`ls -la`, et le contenu SQL reste `INCONNU` ; la stratégie de cycle de vie est marquée `HYPOTHÈSE`.

## Problèmes mineurs

- L'absence de `CHECK` n'est un risque que si des écritures/futures migrations utilisent ces colonnes; le rapport le dit parfois « nul » et parfois « incohérence silencieuse ». Harmoniser la qualification conditionnelle.
- Les jugements « sain » et « bonne pratique » doivent rester compris comme appréciations bornées au schéma lu, non comme preuve d'intégrité de la base live.

## Points vérifiés et corrects

- Les deux tables, colonnes `NOT NULL` et types du schéma sont bien lisibles dans `migrations/001_init.sql:2-13`.
- Le tri `sort()` et le filtrage `(\int)$m[1] > $courante` sont bien présents (`bin/migrate.php:20-30`).

## Recommandations de correction

1. Maintenir l'observation filesystem séparée du contenu réel de `data/app.db` lors des prochaines modifications.
2. Conserver la qualification hypothétique du cycle de vie et la détection de collision dans les recommandations.
