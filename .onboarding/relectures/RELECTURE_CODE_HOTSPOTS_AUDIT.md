# Relecture — CODE_HOTSPOTS_AUDIT.md

## Verdict global
À corriger — les fichiers chauds et plusieurs défauts sont correctement localisés, mais des risques runtime sont présentés comme établis et le contrôle des migrations omet la collision de préfixes numériques.

## Problèmes bloquants

- `bin/migrate.php:24-30` ne rejette pas deux fichiers portant le même numéro : la clé entière de `$aFaire` est écrasée. C'est un défaut plus concret que le seul exemple `10_`/`002_` et doit figurer dans constats, risques et recommandations.
- « La branche `deployed` serait à jour mais le commit absent » (`deploy.yml:68`) est une conséquence conditionnelle d'un échec particulier de `git commit`; le code prouve le masquage du code de sortie, pas qu'un push réussira ni qu'il poussera un état incorrect. Requalifier l'impact en scénario conditionnel.
- Le message `la base est inchangée` (`bin/migrate.php:73`) est effectivement trompeur si une migration précédente a été commitée, mais ce cas n'est pas exécuté. Le rapport doit distinguer `VÉRIFIÉ_CODE` (transactions séparées) de l'effet observé.

## Problèmes mineurs

- Le risque de rétention des sauvegardes est correctement conditionnel à un hôte persistant; ne pas le compter comme défaut actuel sans preuve d'un tel hôte.
- Les affirmations « exemplaire », « sain » et « aucune logique cachée » sont des jugements larges; les limiter aux fichiers lus et aux tests présents.

## Points vérifiés et corrects

- `deploy.yml:68-69` contient bien le `|| echo` puis `--force-with-lease`.
- `bin/migrate.php:63-75` ouvre une transaction distincte par migration et quitte sur la première erreur.
- `public/index.php:16-19,27` n'a pas de validation du résultat de `json_decode`.

## Recommandations de correction

1. Ajouter la collision de préfixes et un test de non-écrasement/refus.
2. Reformuler les risques CI et rollback en scénarios conditionnels, ou fournir une exécution réelle.
3. Réduire les généralisations de qualité à des propriétés observables.
