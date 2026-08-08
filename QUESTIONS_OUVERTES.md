# Questions ouvertes et recommandations — shift-pilot-svc

Ce document synthétise les points signalés par les audits du projet et demandant une clarification
ou une action avant d'étendre le service. Chaque question est sourcée par le code et priorisée.

## Questions critiques — À résoudre avant l'extension

### 1. Mécanisme de dépôt de `deployed-version.json`

**Description** :
Le pipeline `deploy.yml` écrit `deployed/<env>/version.json` sur la branche `deployed`. 
Cependant, `public/index.php:16-19` lit `deployed-version.json` à la racine du projet servi.

**Aucun code dans ce dépôt ne dépose ce fichier à la racine.**

**Hypothèse** : un mécanisme externe (hébergement, webhook, script post-deploy, CI/CD de 
l'hébergeur) copie le fichier. Mais ce mécanisme :
- N'est pas documenté
- N'est pas versionné dans ce dépôt
- N'est pas vérifiable sans accès à l'hôte

**Conséquence** : si ce mécanisme est absent ou défaillant, `/version` retourne `sha: null, 
ref: null, deployedAt: null` sans erreur HTTP — la fonctionnalité principale du banc d'essai
devient silencieusement incomplète.

**À faire** :
- [ ] Identifier le mécanisme (hébergement ? webhook ? script externe ?)
- [ ] Versionner ou documenter son fonctionnement
- [ ] Ou créer un script post-deploy dans ce dépôt qui extrait le fichier depuis la branche 
      `deployed` et le dépose à la racine

**Sévérité** : Critique — affecte la fonctionnalité centrale du banc d'essai.

---

### 2. Cycle de vie de `data/app.db` avec migrations

**Description** :
`data/app.db` est versionné dans le dépôt. Le pipeline `deploy.yml:35-36` applique les 
migrations en CI mais ne commite pas le résultat.

**Trois scénarios possibles** :

| Scénario | Impact | État actuel |
|----------|--------|---|
| A. Base versionnée = état zéro des migrations (appliquées à chaque déploiement) | Normal ; fonctionne actuellement avec une seule migration | Hypothesis |
| B. Base versionnée doit être mise à jour après chaque migration | `data/app.db` doit être commité post-migration en CI | Non implémenté |
| C. Stratégie mixte (v1-v2 en CI, v3+ appliquées à la main) | Complexe et fragile | Non documenté |

Actuellement (une seule migration `001_init.sql`), le scénario A fonctionne par défaut.
À la deuxième migration, le choix devient critique.

**À faire** :
- [ ] Clarifier la stratégie intentionnelle (A, B, ou autre)
- [ ] Documenter dans le README ou un commentaire `deploy.yml`
- [ ] Si scénario B, modifier `deploy.yml` pour commiter `data/app.db` post-migration

**Sévérité** : Haute — nécessaire avant d'ajouter une deuxième migration.

---

### 3. Intention de `/health` : dépendre de SQLite ?

**Description** :
`public/index.php:11` ouvre la connexion SQLite avant tout routage. Cela signifie que 
`GET /health` est inaccessible si la base est down.

**Deux interprétations** :

| Intention | Logique |
|-----------|---------|
| Deep health check | `/health` teste aussi l'accès à la base — comportement volontaire |
| Oubli de conception | `/health` devrait répondre même si la base est down — indicateur distinct |

**À faire** :
- [ ] Clarifier : est-ce intentionnel (deep check) ou un oubli ?
- [ ] Si intentionnel, ajouter un commentaire en code expliquant le choix
- [ ] Si oubli, déplacer `Db::connect()` après le case `/health` ou envelopper dans un
      `try/catch`

**Sévérité** : Moyenne — affecte la monitoring et les incidents, non la fonctionnalité métier.

---

## Questions importantes — À résoudre pour la robustesse

### 4. Gestion d'exceptions dans le routeur

**Description** :
`public/index.php` n'a aucun `try/catch`. Toute exception (PDO, JSON, …) s'échappe sans
interception.

**Conséquence** :
La réponse HTTP dépend de `display_errors` sur l'hôte (non dans le dépôt, donc INCONNU).
Possible résultat : réponse non-JSON, exposant une stack trace, etc.

**À faire** :
- [ ] Envelopper le corps du routeur dans un `try/catch` global
- [ ] Retourner une réponse JSON cohérente pour toutes les erreurs :
  ```json
  {"error": "Internal Server Error", "code": 500}
  ```

**Sévérité** : Moyenne — risque d'incohérence observationnelle, pas de logique brisée.

---

### 5. Sécurité de `json_decode` sur `deployed-version.json`

**Description** :
```php
$version = @json_decode(file_get_contents('deployed-version.json'), true) ?? [];
```

Risques :
- `json_decode` peut retourner `null` si le JSON est invalide
- La fusion `$version + [...]` avec un `null` lève une `TypeError` en PHP 8+
- Suppression d'erreur avec `@` masque les avertissements

**À faire** :
- [ ] Protéger la lecture :
  ```php
  $v = @json_decode(file_get_contents('deployed-version.json'), true);
  $version = is_array($v) ? $v : ['sha' => null, 'ref' => null, 'deployedAt' => null];
  ```

**Sévérité** : Basse — risque mineur si le fichier est bien formé ; pas exposé via l'API.

---

### 6. Priorité de `schemaVersion` — fichier vs base live

**Description** :
```php
return $version + ['schemaVersion' => Db::schemaVersion($pdo)];
```

L'opérateur `+` PHP conserve la clé du tableau gauche. Si `deployed-version.json` contient
un `schemaVersion`, c'est cette valeur qui est retournée, jamais celle de la base live.

**Intention supposée** : la version déployée (fichier) fait foi, car elle reflète la version
schéma **appliquée à ce déploiement**. La base live peut diverger si modifiée hors cycle.

**Risque** : confusion silencieuse si la base est restaurée depuis sauvegarde ou modifiée
manuellement hors déploiement.

**À faire** :
- [ ] Ajouter un commentaire expliquant l'intention du `+` operator
- [ ] Ou documenter le comportement dans le README

**Sévérité** : Basse — comportement attendu, pas de bug observable actuellement.

---

## Problèmes de conception — À corriger avant l'extension

### 7. Tri lexicographique des migrations — risque de mauvais ordre

**Description** :
`bin/migrate.php:20-30` trie les fichiers lexicographiquement. Convention supposée : 
`NNN_*.sql` avec trois chiffres. Mais aucune validation n'imposte cette convention.

**Résultats possibles** :
- Un fichier `10_*.sql` s'applique **avant** `002_*.sql` (mauvais ordre)
- Un fichier `foo_*.sql` est silencieusement ignoré (pas de nombre en préfixe)

**À faire** :
- [ ] Ajouter une validation du préfixe dans `bin/migrate.php` — refuser ou avertir sur les
      fichiers mal nommés :
  ```php
  if (!preg_match('#^migrations/(\d{3})_#', $file)) {
    throw new Exception("Fichier de migration mal nommé : $file");
  }
  ```

**Sévérité** : Moyenne — risque accidentel d'ordre variable qui rompt la reproductibilité.

---

### 8. Collision silencieuse de préfixes de migration

**Description** :
`bin/migrate.php:24-30` construit `$aFaire[(int)$m[1]] = $f`. Si deux fichiers ont le même
préfixe numérique (ex. `002_a.sql` et `002_b.sql`), le premier est silencieusement écrasé.

**Résultat** : migration perdue, sans erreur ni avertissement.

**À faire** :
- [ ] Détecter et refuser les doublons :
  ```php
  if (count($files) !== count($aFaire)) {
    die("Erreur : doublons dans les préfixes de migration.\n");
  }
  ```

**Sévérité** : Haute — risque de perte de migration invisible.

---

### 9. Absence de contrainte CHECK sur `statut` et `devise`

**Description** :
Les colonnes `statut` et `devise` dans la table `orders` n'ont pas de contrainte CHECK.
N'importe quelle valeur textuelle peut être insérée.

**Données actuelles** :
- `statut` : `payee`, `annulee` (5 lignes de test)
- `devise` : `XPF` (5 lignes de test)

Aucun risque observable actuellement (données fictives contrôlées), mais manque de validation
SQL.

**À faire** (si le modèle est stabilisé) :
- [ ] Ajouter une migration `002_*.sql` avec une contrainte CHECK :
  ```sql
  ALTER TABLE orders ADD CONSTRAINT check_statut 
    CHECK (statut IN ('payee', 'annulee'));
  ```

**Sévérité** : Basse — cosmétique pour un banc d'essai. Important si le service évolue vers
des données réelles.

---

## Recommandations mineures — Nettoyage et documentation

### 10. Message opérationnel trompeur sur erreur de migration

**Description** :
`bin/migrate.php:73` affiche « la base est inchangée » après un `rollBack`. Techniquement
trompeur : les migrations antérieures de la même exécution restent appliquées.

**À faire** :
- [ ] Clarifier le message :
  ```php
  "Erreur sur la migration courante. Les migrations antérieures restent appliquées.\n"
  ```

**Sévérité** : Très basse — documentation opérationnelle.

---

### 11. Absence de vérification post-déploiement

**Description** :
`deploy.yml` n'exécute pas de `GET /version` après publication pour confirmer que la
version servie est accessible et correcte.

**À faire** :
- [ ] Ajouter une étape post-push qui vérifie la version servie (si un endpoint de
      déploiement est disponible)

**Sévérité** : Très basse — amélioration observationnelle pour le monitoring.

---

## Récapitulatif par priorité

| Priorité | Numéro | Titre | Blocker ? |
|----------|--------|-------|-----------|
| 🔴 Critique | 1 | Mécanisme de dépôt de `deployed-version.json` | Oui — fonctionnalité centrale |
| 🟠 Haute | 2 | Cycle de vie de `data/app.db` | Oui — avant 2e migration |
| 🟠 Haute | 8 | Collision silencieuse de préfixes | Oui — intégrité des migrations |
| 🟡 Moyenne | 3 | Intention de `/health` | Non — clarification utile |
| 🟡 Moyenne | 4 | Gestion d'exceptions dans le routeur | Non — robustesse |
| 🟡 Moyenne | 7 | Tri lexicographique sans validation | Non — risque accidentel |
| 🟢 Basse | 5 | Sécurité de `json_decode` | Non — cas limite |
| 🟢 Basse | 6 | Priorité de `schemaVersion` | Non — comportement attendu |
| 🟢 Basse | 9 | Contrainte CHECK sur `statut`/`devise` | Non — cosmétique |
| ⚪ Trivial | 10 | Message opérationnel trompeur | Non — documentation |
| ⚪ Trivial | 11 | Absence de vérification post-déploiement | Non — monitoring |

## Actions prochaines

**Avant la first production use** :
- [ ] Résoudre Question 1 (mécanisme de `deployed-version.json`)
- [ ] Documenter stratégie Question 2 (`data/app.db`)

**Avant une deuxième migration** :
- [ ] Finaliser stratégie Question 2
- [ ] Implémenter protection Question 7 (validation de préfixes)
- [ ] Implémenter protection Question 8 (détection de doublons)

**Avant l'extension fonctionnelle** :
- [ ] Clarifier Question 3 (`/health`)
- [ ] Ajouter `try/catch` Question 4

---

## Retrouver la source

Les détails techniques complets de chaque question se trouvent dans :
- **ARCHITECTURE.md** — pour les hotspots de code
- **Audits dans `.onboarding/audits/`** — pour les analyses complètes
  - `ARCHITECTURE_AUDIT.md` — zones critiques (Questions 1, 4)
  - `DATA_MODEL_AUDIT.md` — modèle de données (Questions 2, 8, 9)
  - `CODE_HOTSPOTS_AUDIT.md` — points chauds (Questions 1, 3, 4, 5, 6, 7)
  - `WORKFLOW_DEPLOIEMENT.md` — pipeline (Question 1)
  - `WORKFLOW_MIGRATION.md` — migrateur (Questions 2, 7, 8, 10)
