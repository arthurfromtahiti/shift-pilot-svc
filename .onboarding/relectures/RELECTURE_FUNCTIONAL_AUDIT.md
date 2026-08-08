# Relecture — FUNCTIONAL_AUDIT.md

## Verdict global
À corriger — les statuts runtime sont correctement bornés et la référence `schemaVersion` pointe vers `deploy.yml:61`, mais deux formulations présentent encore une intention ou une absence d'impact comme établies sans preuve suffisante.

## Problèmes bloquants

- **Intention non prouvée.** `FUNCTIONAL_AUDIT.md:17` affirme que la priorité du fichier de version sur la base est « intentionnelle ». Le code prouve l'opérateur et le résultat conditionnel, pas l'intention du concepteur. Qualifier cette phrase d'hypothèse ou la supprimer.
- **Gravité non prouvée.** `FUNCTIONAL_AUDIT.md:25` conclut à une « aucune conséquence pratique » pour les méthodes HTTP non filtrées. Cela dépend du périmètre futur et d'un éventuel frontal non décrit ; conserver seulement l'impact non observé/conditionnel.

## Problèmes mineurs

- Les mentions de cohérence et de complétude (`FUNCTIONAL_AUDIT.md:11,29,35-38`) restent des appréciations de lecture statique ; elles ne doivent pas être reformulées comme des réponses HTTP observées.

## Points vérifiés et corrects

- `FUNCTIONAL_AUDIT.md:3,11,15,19,21,23,29,31` distingue correctement `VÉRIFIÉ_CODE`, `HYPOTHÈSE` et `INCONNU` ; les réponses HTTP et l’hôte servi restent non observés.
- `FUNCTIONAL_AUDIT.md:17` distingue le calcul de `$SCHEMA` (`deploy.yml:46`) de son écriture JSON (`deploy.yml:56-64`, clé ligne 61), vérifiée contre le workflow courant.
- `FUNCTIONAL_AUDIT.md:19` limite les cinq lignes à la migration et à la base temporaire des tests ; le contenu courant de `data/app.db` est `INCONNU`.
- `FUNCTIONAL_AUDIT.md:31,37` qualifie la configuration staging/production comme `VÉRIFIÉ_CODE` et l’exécution CI comme `INCONNU`, sans run ID inventé.
- Aucun secret n’est recopié dans l’audit relu.

## Recommandations de correction

1. Retirer l'attribution d'intention non sourcée et la conclusion d'impact nul ; conserver les distinctions actuelles entre chemin de code, effet hypothétique et exécution inconnue.
