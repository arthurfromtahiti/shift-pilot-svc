# CDC_FONCTIONNEL — shift-pilot-svc

> **Confiance** : high  
> **Dernière mise à jour** : 2026-08-08  
> **Base de preuve** : audits complets, workflows validés, code PHP relus, migrations testées

## Résumé

Service HTTP de lecture seule exposant un modèle de commandes persistées en SQLite. Deux environnements isolés (staging/production) gérés par un pipeline CI/CD deux canaux. Les migrations de schéma sont atomiques et adossées à une sauvegarde obligatoire.

---

## Acteurs et leurs capacités

### Utilisateurs externes
- **Consommateur API** : Lit les quatre endpoints publics (`/health`, `/version`, `/orders`, `/orders/{id}`) sans authentification. Accès en lecture seule, aucune mutation permise.

### Opérateurs internes
- **Développeur** : Clone le dépôt, exécute `composer test` et `composer migrate:dry` localement. Peut créer des branches de test et pousser sur `staging`. Le déploiement se déclenche automatiquement via CI/CD après le push.
- **Agent CI/CD** (GitHub Actions) : Exécute les tests, applique les migrations (`composer migrate`), publie la version sur la branche `deployed`. Automatisé, pas d'intervention manuelle.
- **Promoteur staging → main** : Documenté comme effectuant un geste manuel (merge/push vers `main`), mais **le rôle, les permissions et les contrôles d'accès ne sont pas configurés dans le dépôt fourni**. Aucune branch protection ni autorisation GitHub n'est visible.

### Systèmes
- **GitHub Actions** : Orchestre CI/CD, exécute les migrations en isolation (`ubuntu-latest`), publie la version sur la branche `deployed`. Déclenchement : push sur `main` ou `staging`.
- **Serveur web** : Héberge la version servie. **Note** : Le code fourni (`public/index.php`) n'implémente **pas** d'enregistrement des appels API — aucun log structuré des requêtes HTTP n'est produit par le service lui-même. Le dépôt ne fournit pas de mécanisme de logging des requêtes (pas de middleware, pas de syslog).

---

## Parcours critiques (par domaine métier)

### 1. Gestion des commandes — lecture pure

**Acteur** : Consommateur API  
**Déclencheur** : Requête HTTP GET  
**Résultat** : JSON des commandes (toutes ou filtrée par ID)

#### Règle 1.1 : Endpoint `/orders` retourne toutes les commandes
- **Énoncé** : Un appel `GET /orders` retourne le tableau JSON de toutes les commandes présentes en base.
- **Données** : Au démarrage, 5 commandes insérées par `001_init.sql` :
  ```json
  [
    {"id": 1, "client": "Heiata", "montant_cents": 420000, "devise": "XPF", "statut": "payee"},
    {"id": 2, "client": "Teiki", "montant_cents": 180000, "devise": "XPF", "statut": "annulee"},
    {"id": 3, "client": "Manoa", "montant_cents": 960000, "devise": "XPF", "statut": "payee"},
    {"id": 4, "client": "Vaite", "montant_cents": 305000, "devise": "XPF", "statut": "payee"},
    {"id": 5, "client": "Moana", "montant_cents": 75000, "devise": "XPF", "statut": "annulee"}
  ]
  ```
- **Règle métier** : Les commandes ne peuvent pas être éditées ni supprimées via l'API — ensemble immuable une fois déployé.
- **Preuve** : `migrations/001_init.sql` lignes 15-20 définissent les données exactes ; `src/Orders.php:13-16` exécute `SELECT id, client, montant_cents, devise, statut FROM orders` ; `tests/OrdersTest.php::testListeToutesLesCommandes()` vérifie le nombre et les types.

#### Règle 1.2 : Endpoint `/orders/{id}` retourne une commande par ID
- **Énoncé** : Un appel `GET /orders/1` retourne un objet JSON avec les champs `id`, `client`, `montant_cents`, `devise`, `statut`.
- **Comportement absent** : Si l'ID n'existe pas, le statut HTTP est 404 et le corps est `{"error": "Commande introuvable"}`.
- **Preuve** : `src/Orders.php:18-24`, méthode `find($id)` retourne `null` si absent ; `public/index.php:34-44` traduit `null` en réponse 404 + JSON d'erreur ; test `OrdersTest.php::testIdentifiantInconnuRenvoieNull()`.

#### Règle 1.3 : Schéma JSON de chaque commande — champs et types
- **Énoncé** : Chaque commande en base porte les champs `id` (INTEGER), `client` (TEXT), `montant_cents` (INTEGER), `devise` (TEXT, défaut 'XPF'), `statut` (TEXT). Tous obligatoires en base.
- **Ce qui est prouvé** : 
  - **Schéma de données** : `001_init.sql` définit les 5 lignes avec les champs et types.
  - **Couche métier** : `src/Orders.php:15,20` récupère exactement ces champs via SELECT.
  - **Couche test** : Cinq tests PHPUnit (`OrdersTest.php`) couvrent les méthodes `all()` et `find()` de la couche Orders en vérifiant les types de retour et montants en centimes entiers.
- **Ce qui n'est pas prouvé** : 
  - **Codes HTTP de réussite** : La suite de tests ne couvre pas `public/index.php` — aucune preuve automatisée que les codes HTTP 200 sont retournés.
  - **Format JSON produit** : Les tests couvrent la couche Orders, pas la sérialisation JSON du routeur (`json_encode()` en `public/index.php:45`).
  - **Codes HTTP d'erreur (404)** : Le code 404 pour un ID absent est implémenté (`public/index.php:39`), mais aucune preuve automatisée. Vérifiable via recette manuelle (`CAHIER_RECETTE.md:4.6`).

---

### 2. Sécurité du cycle de production — deux canaux distincts

**Acteur** : Développeur + CI/CD  
**Déclencheur** : Push sur `staging` ou `main`  
**Résultat** : Code exécuté sur le serveur, version enregistrée sur `deployed`

#### Règle 2.1 : Isolation staging/production — artefact Git (`deployed` branche)
- **Énoncé** : Un push sur `staging` ne doit jamais modifier `deployed/production/version.json` sur la branche Git `deployed`. Les deux environnements restent isolés **au niveau du dépôt**.
- **Champ d'application** : Cette garantie s'applique **uniquement à l'artefact Git** (`deployed/<env>/version.json` sur la branche `deployed`). Le fichier sur le serveur web (`deployed-version.json` à la racine du projet servi) est **hors dépôt** — voir « Questions ouvertes ».
- **Mécanisme** : Deux fichiers de version distincts (`deployed/staging/version.json` et `deployed/production/version.json`) coexistent sur la branche `deployed` sans jamais s'écraser.
- **Règle de pipeline** : La branche cible du `version.json` est déterminée par `github.ref_name` — une variable immuable du déclencheur Git (`main` → `production`, autres branches → `staging`).
- **Preuve** : `.github/workflows/deploy.yml:38-41` — expression GitHub Actions `github.ref_name == 'main' && 'production' || 'staging'` ; `mkdir -p "$CIBLE"` isole les répertoires ; commit da34e1e (correction du bug d'écrasement inter-environnements).

#### Règle 2.2 : Publication conditionnelle à la suite verte — artefact Git (`deployed` branche)
- **Énoncé** : Si la suite de tests échoue, aucune nouvelle version n'est publiée sur la branche `deployed` ; l'artefact Git précédent reste inchangé.
- **Champ d'application** : Cette garantie s'applique **uniquement à l'artefact Git** sur la branche `deployed`. L'état du fichier serveur (`deployed-version.json`) est **hors dépôt** — voir « Questions ouvertes ».
- **Mécanisme** : `composer test` (PHPUnit) s'exécute **avant** les migrations et la publication. Tout `exit` non-zéro arrête le workflow, empêchant le push sur `deployed`.
- **Preuve** : `.github/workflows/deploy.yml:31-32` — ordre des étapes ; commentaire « Test avant publication ».

#### Règle 2.3 : Chaque publication enregistre le SHA et l'horodatage de la branche `deployed`
- **Énoncé** : Le `version.json` publié sur la branche `deployed` porte le SHA du commit source du merge, l'environnement, et l'horodatage UTC au moment du workflow.
- **Données** : Champs `sha` (GitHub Actions `${{ github.sha }}`), `ref` (branche git, déduit de `github.ref_name`), `environnement` (`staging` ou `production`), `schemaVersion` (version lue en base au moment du déploiement CI), `deployedAt` (horodatage UTC généré par le workflow).
- **Preuve** : `.github/workflows/deploy.yml:44-64` — `github.sha` en ligne 47, construction du JSON avec `date -u +%Y-%m-%dT%H:%M:%SZ` en ligne 63.

#### Règle 2.4 : Promotion staging → production — procédure manuelle documentée
- **Énoncé** : La promotion de `staging` vers `main` (et donc vers `production`) est une étape manuelle — aucun agent CI/CD n'effectue automatiquement ce push.
- **Geste documenté** : README.md mentionne la promotion ; le merge de `staging` vers `main` requiert une action intentionnelle (GitHub PR, merge local, ou push direct selon les permissions).
- **Limitation technique** : **Aucun contrôle d'accès au dépôt** (branch protection, règles GitHub) n'est visible dans les fichiers fournis pour **forcer** cette manualité. La séparation entre `staging` et `main` repose uniquement sur la **discipline procédurale**, pas sur une barrière technique.
- **Risque** : Un développeur ou un agent pourrait potentiellement pousser sur `main` sans passer par `staging`, déclenchant le déploiement de `production` en contournant la procédure.
- **Preuve** : `.github/workflows/deploy.yml:11` énumère `[main, staging]` comme branches déclencheurs — les deux déclenche le workflow sans distinction de protocole.

---

### 3. Migrations de schéma — atomicité et sauvegarde

**Acteur** : Développeur (local) ou CI/CD (déploiement)  
**Déclencheur** : Exécution de `bin/migrate.php` ou `composer migrate`  
**Résultat** : Base mise à jour, sauvegarde créée, version enregistrée

#### Règle 3.1 : La sauvegarde est obligatoire avant toute migration
- **Énoncé** : Avant d'appliquer **tout fichier de migration**, une copie horodatée de la base est créée dans `data/backups/app-<YYYYMMDD-HHmmss>-avant-v<N>.db`. Si la copie échoue, aucune migration n'est appliquée.
- **Mécanisme** : `bin/migrate.php:51-59`, méthode `copy()` exécutée **avant** la boucle d'application. Si `copy()` retourne faux : message STDERR + `exit(1)`.
- **Preuve** : `bin/migrate.php:56` — `if (!copy(Db::path(), $sauvegarde)) { ... exit(1); }`.

#### Règle 3.2 : Seules les migrations non encore enregistrées sont appliquées
- **Énoncé** : Une migration n'est appliquée que si son numéro de version (préfixe `NNN_`) est strictement supérieur à la version en base (colonne `MAX(version)` de `schema_migrations`).
- **Mécanisme** : Filtre `(int) $m[1] > $courante`, où `$m[1]` est le préfixe numérique extrait par regex `/(\d+)_/` et `$courante` est lue en base.
- **Preuve** : `bin/migrate.php:24-31`.

#### Règle 3.3 : Chaque migration s'exécute dans sa propre transaction
- **Énoncé** : Un fichier de migration s'exécute dans un bloc `BEGIN TRANSACTION ... COMMIT` en PDO. En cas d'erreur SQL, seule cette migration est annulée (ROLLBACK) ; les migrations déjà appliquées lors des itérations précédentes **restent persistées**.
- **Mécanisme** : `$pdo->beginTransaction()`, `$pdo->exec(file_get_contents($f))`, enregistrement dans `schema_migrations`, `$pdo->commit()`. En cas d'exception, `$pdo->rollBack()` + `exit(1)`.
- **Preuve** : `bin/migrate.php:62-76`.

#### Règle 3.4 : Le registre `schema_migrations` est mis à jour dans la même transaction que le SQL
- **Énoncé** : La ligne `INSERT INTO schema_migrations (version, applied_at)` est enregistrée dans la même transaction que l'exécution du fichier SQL — pas de risque de divergence entre schéma réel et registre.
- **Preuve** : `bin/migrate.php:66-68` — INSERT dans la même `beginTransaction()/commit()`.

#### Règle 3.5 : Essai à blanc (`--dry-run`) affiche le SQL sans l'exécuter
- **Énoncé** : Un appel `php bin/migrate.php --dry-run` affiche le contenu de tous les fichiers de migration à appliquer, sans exécuter aucun d'eux.
- **Limitation** : Aucune validation de syntaxe SQL n'est effectuée — une erreur SQL passe le dry-run et échoue seulement à l'application réelle.
- **Preuve** : `bin/migrate.php:41-48` — sortie anticipée via `file_get_contents()` affichage direct, `exit(0)` avant la boucle d'application.

#### Règle 3.6 : Dry-run s'exécute obligatoirement en CI avant les tests
- **Énoncé** : Chaque push sur `main`/`staging` ou PR déclenche un essai à blanc des migrations dans le workflow CI, avant les tests PHPUnit. C'est une alerte précoce sur les problèmes de migration.
- **Preuve** : `.github/workflows/ci.yml:17-18` — `composer migrate:dry` avant `composer test`.

---

### 4. Versionning et déploiement — source de vérité

**Acteur** : CI/CD, serveur web  
**Déclencheur** : Succès du workflow de déploiement  
**Résultat** : Fichier `version.json` publié et accessible

#### Règle 4.1 : Artefact Git (branche `deployed`) vs fichier serveur — deux domaines distincts
- **Énoncé** : La source de vérité Git (`deployed/<env>/version.json` sur la branche `deployed`) est toujours **publiée** par le workflow CI/CD après un merge réussi. Le fichier à la racine du serveur (`deployed-version.json`, qui contient également `schemaVersion`) est **hors périmètre du dépôt** — sa présence et son contenu dépendent du script d'hébergement.
- **Champ `schemaVersion` — publication et priorité** : 
  - **À la publication** (`.github/workflows/deploy.yml:61`) : Le workflow écrit `"schemaVersion": $SCHEMA` dans l'artefact Git `deployed/<env>/version.json`, où `$SCHEMA` est la version lue en base **au moment du déploiement**.
  - **À la lecture** (`public/index.php:27`) : Le code exécute `$version + ['schemaVersion' => Db::schemaVersion($pdo)]`. **L'opérateur `+` préserve les clés du tableau gauche** (`$version` = contenu décodé du fichier `deployed-version.json`).
    - **Si le fichier contient déjà `schemaVersion`** : La valeur du fichier est **conservée** (pas écrasée par la base).
    - **Si le fichier n'a pas `schemaVersion`** : La valeur live de la base est ajoutée en fallback.
  - **Implication** : En cas nominal (fichier pré-peuplé par le workflow), le `schemaVersion` reste celui du déploiement, pas la base courante. C'est voulu — il traçe la version qui **a été déployée**, pas la version live à chaque requête.
- **Contrat du endpoint `/version` — trois cas mutuellement exclusifs** :
  - **Cas 1 — Fichier `deployed-version.json` absent (nominal local)** : 
    - **Code** : `public/index.php:16-19` fallback vers `['sha' => null, 'ref' => null, 'deployedAt' => null]`
    - **Résultat** : `{"sha": null, "ref": null, "deployedAt": null, "schemaVersion": <N>}` où `<N>` est la version lue en base en temps réel.
    - **Clé `environnement`** : **Absente du JSON** (pas créée par le fallback).
    - **HTTP** : 200 OK (JSON valide produit).
  - **Cas 2 — Fichier présent et JSON valide** :
    - **Code** : `public/index.php:17-18` décode le fichier, `json_decode()` retourne un tableau associatif. Ligne 27 fusionne avec `$version + ['schemaVersion' => <N>]` où `<N>` est lue en base.
    - **Priorité de `schemaVersion`** : L'opérateur `+` de PHP préserve les clés du tableau **gauche** (`$version` = contenu du fichier décodé). 
      - **Si le fichier contient déjà `schemaVersion`** : La valeur du fichier est conservée (n'est pas écrasée par la base).
      - **Si le fichier n'a pas `schemaVersion`** : La valeur de la base est ajoutée.
    - **Résultat** : Tous les champs du fichier décodé sont présents (ex: `sha`, `ref`, `environnement`, `deployedAt`), plus `schemaVersion` depuis le fichier **OU** la base selon sa présence dans le fichier.
    - **HTTP** : 200 OK (JSON complètement peuplé).
  - **Cas 3 — Fichier présent mais JSON invalide ou malformé** :
    - **Code** : `public/index.php:18` `json_decode()` retourne `null` (JSON invalide). Ligne 27 exécute `$version + [...]` où `$version` est `null`.
    - **Erreur** : PHP lève une `TypeError` : « Unsupported operand type(s) for +: null and array » (opérateur `+` non défini pour null).
    - **Résultat** : Aucun JSON d'erreur structuré. Réponse HTTP 500 sans corps, ou body = stack PHP brut (dépend de `display_errors` en production). **Blocage complet du endpoint `/version`**.
    - **Implication métier** : Erreur grave — indique un fichier `deployed-version.json` corrompu sur le serveur de déploiement.
- **Gestion des erreurs globales** : Le routeur `public/index.php:11-47` n'enveloppe pas `Db::connect()` (ligne 11) ni les appels Orders/Db dans un bloc `try/catch` global. Toute exception PDO (base indisponible, requête invalide, etc.) s'échappe sans interception — aucun JSON d'erreur structuré n'est produit, la réponse dépend de la config serveur. En particulier, l'opérateur `+` à la ligne 27 n'est pas protégé : si `$version` est `null` (cas 3 — JSON invalide), PHP lève une `TypeError` qui s'échappe non capturée. Seul le cas `/orders/{id}` absent produit un JSON d'erreur : `{"error": "Commande introuvable"}` (ligne 39).
- **Preuve** : `public/index.php:8-47` — pas de bloc `try/catch` global ; cas 1 (fallback) lignes 16-19 ; cas 2 (fusion) ligne 27 ; cas 3 (TypeError) impossibilité de `null + array` ; `CAHIER_RECETTE.md` section 4.3 décrit le cas fichier absent.

#### Règle 4.2 : Si un déploiement échoue (tests, migrations), aucune nouvelle version n'est publiée
- **Énoncé** : Un merge qui provoque une sortie non-zéro du CI (test rouge, migration échoue, push rejeté) ne produit **aucune nouvelle version** sur la branche `deployed`.
- **Garantie** : Garantie au niveau Git (`deployed/<env>/version.json` inchangé). Le statut du serveur web (fichier `deployed-version.json` sur le disque) dépend du script d'hébergement — voir « Questions ouvertes ».
- **Preuve** : `.github/workflows/deploy.yml` — chaque étape peut sortir avec exit 1, arrêtant le workflow avant le push sur `deployed`.

#### Règle 4.3 : Pushes simultanés sur la même branche sont mis en file
- **Énoncé** : Deux pushes rapides sur `staging` (ou `main`) produisent bien deux publications successives de `version.json`, la deuxième n'annulant pas la première.
- **Mécanisme** : `concurrency.cancel-in-progress: false` (`deploy.yml:14-15`).
- **Preuve** : `.github/workflows/deploy.yml:14-15`.

---

## Données — vue métier et technique

### Notion : Commande (orders table)

**Champs** :
- `id` (INTEGER PRIMARY KEY)
- `client` (TEXT NOT NULL)
- `montant_cents` (INTEGER NOT NULL, en centimes, devises locales)
- `devise` (TEXT NOT NULL, défaut 'XPF')
- `statut` (TEXT NOT NULL, valeurs ex: 'payee', 'annulee')

**État et cycle de vie** :
- État initial : 5 commandes fictives insérées par `001_init.sql` au démarrage (Heiata, Teiki, Manoa, Vaite, Moana).
- Pas de suppression, pas de mise à jour, pas d'insertion via l'API — ensemble immuable une fois déployé.
- **Cycle** : Insertion en base (migration `001_init.sql`) → persistance jusqu'à nouvel ordre. 
  - **Annulation de la migration courante (rollback transactionnel)** : Prouvé par le mécanisme transactionnel de `bin/migrate.php:62-76` (un bloc `BEGIN/ROLLBACK` isole chaque migration). Si une migration échoue, seule cette migration est annulée ; les migrations précédentes restent appliquées.
  - **Restauration d'une sauvegarde antérieure** : Une sauvegarde horodatée est créée obligatoirement **avant** toute application (preuve : `bin/migrate.php:50-59`). La procédure complète de restauration (copie manuelle du backup vers `data/app.db` + redémarrage + vérification) n'est pas automatisée — elle relève de l'intervention opérationnelle manuelle.

**Absence de contraintes métier**:
- Pas de contrainte UNIQUE sur `email` (doublons possibles).
- Pas de contrainte CHECK sur `amount` (valeurs négatives acceptées).
- Pas de clé étrangère (pas d'autres tables liées).

**Preuve** : `migrations/001_init.sql` lu intégralement.

---

## Questions ouvertes et limites

### Chaînon manquant : `deployed-version.json` sur l'hôte — domaine hors dépôt
- **Problème structurel** : `deploy.yml` publie `deployed/<env>/version.json` sur la branche Git `deployed`, mais aucune étape du workflow intégré au dépôt ne copie ce fichier à la racine du projet servi (où `public/index.php:16-19` le lit en tant que `deployed-version.json`). Le mécanisme de copie vers le disque du serveur est **hors périmètre du dépôt** — webhook, script serveur, rsync, système d'artefacts hébergé, ou processus d'orchestration (Kubernetes, Ansible, etc.).
- **État du domaine** : 
  - **Git (`deployed/<env>/version.json` sur branche `deployed`)** : Publication garantie par `deploy.yml` à chaque merge réussi, tracée dans l'historique Git.
  - **Disque du serveur (`deployed-version.json` à la racine du projet)** : **INCONNU** — aucune garantie, dépend du script d'hébergement externe.
- **Impact sur `/version` — trois cas mutuellement exclusifs** : 
  - **Cas 1 — Fichier absent sur le disque (nominal local)** : Fallback PHP retourne `{"sha": null, "ref": null, "deployedAt": null, "schemaVersion": <N>}` sans clé `environnement`. Réponse HTTP 200 valide.
  - **Cas 2 — Fichier présent et JSON valide (production nominale)** : Tous les champs décodés sont fusionnés avec `schemaVersion`. Réponse HTTP 200.
  - **Cas 3 — Fichier présent mais JSON invalide ou malformé (ERREUR GRAVE)** : `json_decode()` retourne `null`. La ligne 27 (`$version + [...]`) lève une `TypeError` : « Unsupported operand type(s) for +: null and array ». Le service répond HTTP 500 sans JSON structuré. **Blocage complet du endpoint `/version`**. 
- **Résolution** : 
  - Côté CI/CD (hors dépôt) : Documenter et valider le script d'hébergement qui copie `deployed/<env>/version.json` vers `deployed-version.json` sur le disque.
  - Côté robustesse (dans le dépôt) : Ajouter une validation PHP du JSON après décodage (ex: `if (!is_array($version))`) avant l'opérateur `+`, ou envelopper le tout dans un `try/catch`. **À implémenter avant la montée en production sur données réelles**.

### Versioning de `data/app.db` et migrations futures
- **Question** : Après chaque nouvelle migration, doit-on mettre à jour et committer `data/app.db` dans le dépôt, ou appliquer les migrations à chaque déploiement contre la base « de départ » versionnée ?
- **État actuel** : Seule migration (001_init.sql), base déjà à v1. Pas de décalage visible.
- **Implication future** : À clarifier avant la 2e migration pour éviter une divergence entre la base versionnée et le schéma appliqué en production.

### Couverture de test incomplète
- **Testés** : Couche Orders (`OrdersTest.php`) — 5 tests couvrant `all()`, `find()` nominal/absent, montants entiers, version schema.
- **Non testés** : Routeur (`public/index.php`), migrateur (`bin/migrate.php`), endpoints `/health` et `/version`, codes HTTP 404 du routeur (vérifiés sur test endpoint seul, pas par la suite).

### Absence de `workflow_dispatch`
- **Observation** : Le workflow `deploy.yml` ne peut être déclenché que par un push. Il n'existe pas de bouton « re-déployer » manuel dans GitHub Actions.
- **Impact** : Impossible de re-déclencher un déploiement sans pousser un nouveau commit (option `.skip-ci` ou équivalent).

### Sécurité : Pas d'authentification ni d'autorisation
- **Endpoints publics** : `/health`, `/version`, `/orders`, `/orders/{id}` sont accessibles sans authentification.
- **Données sensibles** : Aucune (données fictives), mais à vérifier si ce dépôt évoluait vers des données réelles.

### Robustesse des endpoints : pas de gestion centralisée d'exceptions
- **Problème** : `public/index.php` n'enveloppe pas `Db::connect()` ni les appels Orders/Db dans un bloc `try/catch` global. Une exception PDO (base indisponible, requête invalide, etc.) s'échappe sans interception.
- **Comportement** : Aucun JSON d'erreur structuré n'est produit ; la réponse HTTP dépend de la config serveur `display_errors` (HTML, stack trace, ou white screen).
- **Endpoints affectés** : `/version` (lecture schéma), `/orders`, `/orders/{id}` (requête sur Orders).
- **Résolution** : À implémenter avant toute extension — ajouter un middleware ou un `try/catch` au routeur pour produire des JSON d'erreur cohérents (ex: HTTP 500 + `{"error": "..."}` pour toute exception non traitée).

---

## Conformité et risques

### Sécurité et robustesse
Voir `SECURITY_ROBUSTNESS_AUDIT.md` — audit complet pour les risques identifiés et recommandations.

### Tests
Voir `TESTING_AUDIT.md` — zones couvertes et lacunes documentées.

### Données
Voir `DATA_MODEL_AUDIT.md` — schéma SQLite, absent de contraintes, cycle de vie `data/app.db`.

---

## Preuves citées

- `.onboarding/workflows/WORKFLOW_SERVICE.md` — API et endpoints
- `.onboarding/workflows/WORKFLOW_MIGRATION.md` — migrations, sauvegarde, transactions
- `.onboarding/workflows/WORKFLOW_DEPLOIEMENT.md` — deux canaux, publication version.json
- `.onboarding/audits/FUNCTIONAL_AUDIT.md` — endpoints, schéma JSON
- `.onboarding/audits/TESTING_AUDIT.md` — couverture de test
- `public/index.php` — routeur, endpoints, `/version`, fusion de version.json
- `src/Orders.php` — logique Orders, requêtes `SELECT`
- `bin/migrate.php` — application des migrations, sauvegarde, transactions
- `.github/workflows/ci.yml` — CI, dry-run des migrations, tests
- `.github/workflows/deploy.yml` — publication sur branche `deployed`, deux canaux, horodatage
- `migrations/001_init.sql` — schéma, données initiales (5 commandes)
- `tests/OrdersTest.php` — 5 tests PHPUnit couvrant Orders
- `README.md` — contexte, migrations, développement local
- `CAHIER_RECETTE.md` — plan de recette avec 6 phases
