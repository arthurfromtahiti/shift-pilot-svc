# Corrections Phase 4 — Blocages de relecture adressés

**Issue** : SHIAAAAAAAAAAAAAAAAAAAAAAAA-529  
**Dates** : 2026-08-08 (corrections finales appliquées), 2026-08-08 (blocages de relecture adressés), 2026-08-08 (clarification validité JSON)  
**Statut** : Corrections appliquées, documents finalisés pour approbation

---

## Problèmes identifiés par le relecteur

Le relecteur a demandé des corrections supplémentaires pour le CDC et le CAHIER_RECETTE sur quatre points :

1. **Exemples de commandes inventés**, incompatibles avec `migrations/001_init.sql`
2. **Garantie erronée** que la lecture JSON n'échoue jamais
3. **Promesse que toute erreur retourne du JSON**, contredite par les exceptions PDO non interceptées
4. **Exemple `/version` où `environnement` doit être explicitement limité** au cas d'un fichier valide

---

## Corrections appliquées

### 1. Exemples de commandes = données réelles de la migration

**Changement** : CDC_FONCTIONNEL.md, section "Réponse" pour `/orders` (lignes 82-125)

**Avant** : Affichait des noms fictifs (`"Clients A"`, `"Client B"`) avec montants invités (`1500`, `75000`)

**Après** : Affiche maintenant les **5 commandes réelles** depuis `migrations/001_init.sql:15-20` :
```json
[
  {"id": 1, "client": "Heiata", "montant_cents": 420000, ...},
  {"id": 2, "client": "Teiki",  "montant_cents": 180000, ...},
  {"id": 3, "client": "Manoa",  "montant_cents": 960000, ...},
  {"id": 4, "client": "Vaite",  "montant_cents": 305000, ...},
  {"id": 5, "client": "Moana",  "montant_cents": 75000, ...}
]
```

**Preuve** : Identique à `migrations/001_init.sql:15-20`, colonne par colonne.

---

### 2. Clé `environnement` absente en fallback = clarification

**Changement** : CDC_FONCTIONNEL.md, section "GET /version" (lignes 34-76)

**Avant** : Montrait un seul exemple avec `"environnement": "staging"` et affirmait "champs `sha/ref/environnement/deployedAt` valent `null`" en fallback.

**Après** : Montre **deux cas distincts** :
- Cas 1 (fichier absent) : `{"sha": null, "ref": null, "deployedAt": null, "schemaVersion": 1}` **sans `environnement`**
- Cas 2 (fichier présent) : Tous les champs présents, y compris `environnement`

**Note explicite** : « En l'absence du fichier, la clé `environnement` **n'existe pas** (pas créée par le fallback, ligne 19 de `index.php`) »

**Preuve du code** (`index.php:17-19`) :
```php
$version = is_file($versionFile)
    ? json_decode((string) file_get_contents($versionFile), true)
    : ['sha' => null, 'ref' => null, 'deployedAt' => null];  // ← Pas de clé 'environnement'
```

---

### 3. Gestion d'erreurs = distinction PDO vs. cas gérés

**Changement** : CDC_FONCTIONNEL.md, section "Modèle de contrat minimal" (lignes 272-280)

**Avant** : Affirmait « En cas d'erreur → toujours retourner un JSON avec champ `error` »

**Après** : Distingue clairement :
- **Cas d'erreur géré** : `/orders/{id}` absent → HTTP 404 + `{"error": "Commande introuvable"}`
- **Exceptions PDO** : Aucun try/catch → Aucun JSON structuré, réponse dépend de config PHP
  - Affectés : `/version`, `/orders`, `/orders/{id}` (tous).
  - **Preuve** : Pas de try/catch aux lignes 11, 36 de `index.php`

**Note** : Ajouté en section "Cas d'erreur" de `/version` : « Panne SQLite lors de la lecture du schéma → `PDOException` non attrapée (pas de try/catch) → réponse HTTP dépend de la config PHP (ligne 11 de `index.php`) »

---

### 4. CAHIER_RECETTE section 2.3 (/version) = clé `environnement` absente

**Changement** : CAHIER_RECETTE.md, section "2.3 — Endpoint `GET /version`" (lignes 135-142)

**Avant** : Listait 5 champs attendus, incluant `"environnement": null`

**Après** : Listait 4 champs attendus, explicitement noté :
```
- [ ] Réponse JSON avec 4 champs :
  - `sha` : `null`
  - `ref` : `null`
  - `deployedAt` : `null`
  - `schemaVersion` : `1`
- [ ] **Important** : clé `environnement` **absent**
```

---

### 5. Validation JSON — clarification des trois cas de `/version`

**Changement** : CDC_FONCTIONNEL.md, section "Règle 4.1 : La version servie ne se déduit jamais du code"

**Blocage identifié** : Le CDC énumère trois cas (fichier absent, fichier valide, JSON invalide) mais la description n'était pas **assez explicite** sur l'implication du cas 3. Le relecteur a demandé de clarifier : le code n'effectue **aucune validation** du JSON après décodage ; un fichier invalide provoque une `TypeError` lors de `null + array` (opérateur + non défini pour null).

**Avant** :
```
Cas 3 — Fichier présent mais JSON invalide : json_decode() retourne null (ligne 18), 
et la fusion null + array à la ligne 27 lève une TypeError. Le service renvoie HTTP 500 
sans contenu JSON structuré.
```

**Après** : Énoncé en trois sous-cas imbriqués (`Cas 1`, `Cas 2`, `Cas 3`), chacun décrivant :
- **Code** : Numéro de ligne exact et opération
- **Résultat** : JSON retourné ou exception levée
- **HTTP** : Statut HTTP précis
- **Clé `environnement`** : Présence/absence explicite

Exemple du cas 3 enrichi :
```
Cas 3 — Fichier présent mais JSON invalide ou malformé (ERREUR GRAVE) :
- Code : json_decode() retourne null. La ligne 27 (null + [...]) lève TypeError.
- Résultat : Aucun JSON d'erreur structuré. HTTP 500 sans corps ou body = stack PHP.
- Implication métier : Blocage complet du endpoint /version.
```

**Changement corollaire** : Section "Questions ouvertes — Chaînon manquant" réécrite pour distinguer deux problèmes :
1. Problème **structurel** (CI/CD hors-dépôt) : Copie de `deployed/<env>/version.json` vers l'hôte
2. Problème **robustesse** (dans le dépôt) : Validation du JSON après décodage avant utilisation

**Preuve** : `public/index.php:27` — `$version + [...]` où `$version` peut être `null` après `json_decode()`.

---

## Synthèse des modifications

| Fichier | Section | Problème | Correction |
|---------|---------|----------|-----------|
| CDC_FONCTIONNEL.md | `/orders` exemple | Noms fictifs | Noms réels (Heiata, Teiki, etc.) |
| CDC_FONCTIONNEL.md | `/orders` données | Montants invités | Montants réels (420000, 180000, etc.) |
| CDC_FONCTIONNEL.md | `/version` fallback | Un seul exemple | Deux cas (fichier absent/présent) |
| CDC_FONCTIONNEL.md | `/version` fallback | `environnement: null` | `environnement` absent |
| CDC_FONCTIONNEL.md | Contrat minimal | Garantie fausse | Distinction PDO vs. cas gérés |
| CAHIER_RECETTE.md | 2.3 `/version` | 5 champs attendus | 4 champs + note "absent" |
| CDC_FONCTIONNEL.md | Règle 4.1 | 3 cas vaguement décrits | Trois cas explicites avec code/résultat/HTTP/implication |
| CDC_FONCTIONNEL.md | Questions ouvertes | JSON invalide = un problème | Deux problèmes distincts : CI/CD vs. robustesse code |

---

## Preuves et références

| Point | Code | Preuve |
|-------|------|--------|
| Données 5 commandes | `migrations/001_init.sql:15-20` | INSERT exact |
| Fallback environnement | `public/index.php:17-19` | Pas de clé créée dans le tableau fallback |
| PDO exception | `public/index.php:11` | `Db::connect()` sans try/catch |
| Seul cas d'erreur JSON | `public/index.php:37-39` | `if ($order === null)` |
| Cas 1 fichier absent | `public/index.php:16-19` | `is_file()` → fallback array |
| Cas 2 fichier valide | `public/index.php:17-18` | `json_decode(..., true)` → array |
| Cas 3 JSON invalide | `public/index.php:27` | `$version + [...]` où `$version` est `null` |
| TypeError sur + | PHP opérateur `+` | Non défini pour `null + array` |
| Pas de validation | `public/index.php:18` suivi de ligne 27 | Aucun `is_array()` entre le décodage et l'utilisation |

---

## Résultat

✅ Exemples du CDC conformes à la migration  
✅ `environnement` correctement limité au cas fichier présent  
✅ Exceptions PDO correctement documentées (pas de JSON structuré)  
✅ CAHIER_RECETTE cohérent avec la réalité du code  
✅ Trois cas de `/version` explicitement structurés (absent/valide/invalide)  
✅ JSON invalide documenté comme blocage avec implication métier  
✅ Distinction problem structurel (CI/CD) vs. robustesse (code)  
✅ Prêt pour approbation finale du relecteur
