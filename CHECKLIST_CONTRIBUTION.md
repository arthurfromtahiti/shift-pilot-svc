# Checklist de contribution — shift-pilot-svc

Avant de soumettre une modification à shift-pilot-svc, vérifier les points suivants selon le type
de modification.

## Toute modification

- [ ] J'ai lu [INDEX_DOCUMENTATION.md](INDEX_DOCUMENTATION.md)
- [ ] J'ai lu [README.md](README.md) pour comprendre les deux canaux et les domaines
- [ ] J'ai consulté [ARCHITECTURE.md](ARCHITECTURE.md) pour localiser les hotspots
- [ ] J'ai vérifié que mon changement ne casse pas les points de [ARCHITECTURE.md](ARCHITECTURE.md)
      — section « Recette de sécurité »

## Modification du code source

- [ ] `composer test` passe en local
- [ ] Pas de warning PHP (`display_errors` activé)
- [ ] Les hotspots identifiés dans [ARCHITECTURE.md](ARCHITECTURE.md) n'ont pas été modifiés
      accidentellement (`public/index.php:11`, `public/index.php:16-27`, etc.)
- [ ] Si je modifie une zone critique (routeur, connexion BD, migrateur), j'ai lu la section
      correspondante dans [QUESTIONS_OUVERTES.md](QUESTIONS_OUVERTES.md)

## Ajout d'une migration

- [ ] J'ai lu [GUIDE_MIGRATIONS.md](GUIDE_MIGRATIONS.md) complètement
- [ ] Nommage correct : `NNN_description_breve.sql` avec `NNN` = trois chiffres
- [ ] Aucun doublons de préfixe avec les migrations existantes
- [ ] Essai à blanc local réussit : `composer migrate:dry`
- [ ] Application locale réussit : `php bin/migrate.php`
- [ ] Sauvegardes créées : `ls -la data/backups/` affiche `app-YYYYMMDD-HHmmss-avant-v<N>.db`
- [ ] Tests passent : `composer test`
- [ ] Base restaurée correctement depuis sauvegarde (optionnel pour migrations critiques)
- [ ] Aucune transaction explicite dans le SQL (le wrapper gère les transactions)

## Modification du pipeline de déploiement

- [ ] J'ai lu [GUIDE_DEPLOIEMENT.md](GUIDE_DEPLOIEMENT.md)
- [ ] J'ai lu `.github/workflows/deploy.yml` entièrement
- [ ] Pas de modification du mapping d'environnements (`staging` ↔ `production`)
- [ ] Les deux canaux restent distincts et ne s'interfèrent jamais
- [ ] Les tests s'exécutent AVANT les migrations
- [ ] Les migrations s'exécutent AVANT la publication de `version.json`
- [ ] La branche `deployed` n'est modifiée que lors d'un déploiement réussi (tests + migrations)
- [ ] `--force-with-lease` reste en place pour le push de `deployed`

## Modification du routeur ou endpoints

- [ ] Aucune modification ne dépend de la disponibilité de `deployed-version.json` sur l'hôte
      (fichier hors dépôt, existence inconnue)
- [ ] Toute réponse HTTP est JSON
- [ ] Les erreurs 404 retournent `{"error": "...", "code": 404}`
- [ ] L'endpoint `/health` ne dépend que de la disponibilité SQLite (intentionnel ou non ?)
- [ ] Pas d'ajout de dépendances tierces en production (uniquement dev pour tests)

## Modification du modèle de données

- [ ] Exprimée sous forme de migration SQL (jamais modifié `data/app.db` directement)
- [ ] Vérification : pas d'ajout de contrainte CHECK sans validation des données existantes
- [ ] Pas d'ajout de contrainte UNIQUE sans vérification des doublons
- [ ] Pas d'ajout de colonne NOT NULL sans DEFAULT si des lignes existent

## Avant d'ouvrir une PR

- [ ] Title : format `type(domaine): description` (ex. `fix(migrations): refuser les doublons`)
  - Types valides : `feat`, `fix`, `docs`, `refactor`, `test`
  - Domaines : `migrations`, `deploiement`, `routeur`, `bd`, etc.
- [ ] Body : explique le POURQUOI (pas seulement le QUOI)
- [ ] Référence les points de [QUESTIONS_OUVERTES.md](QUESTIONS_OUVERTES.md) s'il y a lien
- [ ] Pas de commit de données réelles ou secrètes
- [ ] Pas de commit de `data/backups/` (gitignoré)

## Avant de fusionner (code review)

- [ ] Aucun conflit de merge
- [ ] Les tests CI passent (`.github/workflows/ci.yml`)
- [ ] Le dry-run des migrations s'affiche correctement
- [ ] Si modifications du pipeline, vérifier le log complet du déploiement test

## Après fusion sur `staging`

- [ ] Attendre le déploiement sur `deployed/staging/version.json` (automatisé)
- [ ] Vérifier : `git show origin/deployed:staging/version.json` affiche le bon SHA
- [ ] Observer les logs de déploiement en cas de problème

## Avant de promouvoir vers `main` (Maintenance/Production)

- [ ] `staging` est sain et stable depuis au moins quelques heures
- [ ] Pas de déploiement échoué sur `staging`
- [ ] Plan de rollback préparé (sauvegarde externe de `data/app.db` sur l'hôte, processus)
- [ ] Créer une PR de promotion `staging` → `main` (geste humain explicite)

## Après fusion sur `main`

- [ ] Attendre le déploiement sur `deployed/production/version.json` (automatisé)
- [ ] Vérifier : `git show origin/deployed:production/version.json` affiche le bon SHA
- [ ] Monitoring de `/health` et `/version` en production
- [ ] Aucune alerte de performance ou d'erreur

## Si quelque chose échoue

1. **Vérifier les logs** : `.github/workflows/ci.yml` ou `.github/workflows/deploy.yml`
2. **Consulter [GUIDE_DEPLOIEMENT.md](GUIDE_DEPLOIEMENT.md)** — section « Dépannage »
3. **En cas de migration échouée** : consulter [GUIDE_MIGRATIONS.md](GUIDE_MIGRATIONS.md) — 
   section « Questions fréquentes »
4. **En cas de doute** : consulter [QUESTIONS_OUVERTES.md](QUESTIONS_OUVERTES.md) pour les 
   points critiques connus

## Questions avant contribution

- Y a-t-il un problème ouvert pour ma modification ?
- Dois-je d'abord clarifier la stratégie (voir [QUESTIONS_OUVERTES.md](QUESTIONS_OUVERTES.md)) ?
- Est-ce une modification mineure (typo, commentaire) ou majeure (logique, données) ?

---

Merci de contribuer à shift-pilot-svc ! 🚀

Pour toute question : consulter [INDEX_DOCUMENTATION.md](INDEX_DOCUMENTATION.md).
