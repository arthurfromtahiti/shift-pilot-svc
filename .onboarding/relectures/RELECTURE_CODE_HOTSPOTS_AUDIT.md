# Relecture — CODE_HOTSPOTS_AUDIT.md

## Verdict global
Acceptable avec réserves — les fichiers chauds, la collision de préfixes et les effets runtime conditionnels sont correctement localisés et qualifiés.

## Problèmes bloquants

- Aucun défaut bloquant dans la version examinée. La collision est explicitement présente (`CODE_HOTSPOTS_AUDIT.md:17`), et les scénarios CI/rollback restent conditionnels.

## Problèmes mineurs

- Le risque de rétention des sauvegardes est correctement conditionnel à un hôte persistant; ne pas le compter comme défaut actuel sans preuve d'un tel hôte.
- Les affirmations « exemplaire », « sain » et « aucune logique cachée » sont des jugements larges; les limiter aux fichiers lus et aux tests présents.

## Points vérifiés et corrects

- `deploy.yml:68-69` contient bien le `|| echo` puis `--force-with-lease`.
- `bin/migrate.php:63-75` ouvre une transaction distincte par migration et quitte sur la première erreur.
- `public/index.php:16-19,27` n'a pas de validation du résultat de `json_decode`.

## Recommandations de correction

1. Conserver les risques CI et rollback comme scénarios conditionnels.
2. Réduire les généralisations de qualité à des propriétés observables.
