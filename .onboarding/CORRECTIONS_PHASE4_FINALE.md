# Corrections Phase 4 — Quatres écarts bloquants adressés

**Issue** : SHIAAAAAAAAAAAAAAAAAAAAAAAA-529  
**Date** : 2026-08-08  
**Statut** : Corrections appliquées, en attente de verdict final

---

## Quatre écarts bloquants — adressés

### 1. **Fallback JSON invalide dans CDC et CAHIER_RECETTE**

**Problème** : Le CDC et le CAHIER_RECETTE affirment que l'endpoint `/version` retourne `{"sha": null, "ref": null, "environnement": null, "schemaVersion": N, "deployedAt": null}` quand `deployed-version.json` est absent. Or, selon le code PHP (`public/index.php:16-19`), le fallback retourne seulement trois champs nuls : la clé `environnement` n'existe **pas** en tant qu'objet JSON.

**Preuve du code** :
```php
$version = is_file($versionFile)
    ? json_decode((string) file_get_contents($versionFile), true)
    : ['sha' => null, 'ref' => null, 'deployedAt' => null];  // ← Pas de clé 'environnement'

echo json_encode($version + ['schemaVersion' => Db::schemaVersion($pdo)]);
// Résultat : {"sha": null, "ref": null, "deployedAt": null, "schemaVersion": N}
```

**Corrections appliquées** :
- **CAHIER_RECETTE.md:233** : Remplacé la réponse de `/version` par `{"sha":null,"ref":null,"deployedAt":null,"schemaVersion":1}` (absent de `environnement`)
- **CDC_FONCTIONNEL.md:Règle 4.1** : Clarifié les trois cas distincts de `/version` :
  - Cas 1 (fichier absent) : clé `environnement` absente du JSON
  - Cas 2 (fichier valide) : JSON décodé contient tous les champs
  - Cas 3 (JSON invalide) : TypeError → HTTP 500 sans JSON

---

### 2. **Garantie mensongère : « tout exception produit du JSON d'erreur »**

**Problème** : Le CDC affirme à la Règle 4.1 que « aucun JSON d'erreur structuré n'est produit » pour les exceptions PDO, mais ne distingue pas clairement :
- Les seuls cas où le service retourne du JSON d'erreur structuré : `/orders/{id}` avec ID absent (HTTP 404)
- Les exceptions PDO : aucune gestion, aucun JSON d'erreur, dépend de `display_errors`

**Preuve du code** :
- Ligne 37-39 (`public/index.php`) : `if ($order === null) { ... echo json_encode(['error' => 'Commande introuvable'])` ← Seul cas
- Ligne 11 : `$pdo = Db::connect()` — pas de try/catch autour
- Pas de bloc try/catch global pour les appels Orders/Db

**Corrections appliquées** :
- **CDC_FONCTIONNEL.md:Règle 4.1** : Ajouté un nouveau paragraphe « Gestion des erreurs » qui distingue :
  - Les deux chemins heureux `/orders/{id}` : JSON d'erreur produit si absent
  - Les exceptions PDO : aucun JSON d'erreur, réponse dépend du serveur
  - Endpoints affectés : `/version`, `/orders`, `/orders/{id}` (tous sauf `/health`)

---

### 3. **Exemples du CDC incompatibles avec migrations/001_init.sql**

**Problème** : La Règle 1.1 (CDC) n'affichait pas les données réelles des 5 commandes. Elle disait « 5 commandes fictives » mais sans montrer les montants, clients ou IDs en JSON.

**Preuve réelle** (`migrations/001_init.sql:15-20`) :
```sql
INSERT INTO orders (id, client, montant_cents, devise, statut) VALUES
  (1, 'Heiata', 420000, 'XPF', 'payee'),
  (2, 'Teiki',  180000, 'XPF', 'annulee'),
  (3, 'Manoa',  960000, 'XPF', 'payee'),
  (4, 'Vaite',  305000, 'XPF', 'payee'),
  (5, 'Moana',   75000, 'XPF', 'annulee');
```

**Corrections appliquées** :
- **CDC_FONCTIONNEL.md:Règle 1.1** : Ajouté un bloc JSON complet affichant toutes les 5 commandes avec leurs montants réels, issus directement de `migrations/001_init.sql`

---

### 4. **Sorties attendues fictives/incohérentes dans CAHIER_RECETTE**

**Problème** : Les sections 4.4 et 4.5 du CAHIER_RECETTE étaient trop minimalistes :
- 4.4 : La commande `curl ... | jq '.[0]'` n'affichait que le premier objet ; aucune validation que les 5 commandes existaient réellement
- 4.5 : La commande `curl ... | jq .id` n'affichait que l'ID, pas la commande entière

**Corrections appliquées** :
- **CAHIER_RECETTE.md:4.4** : 
  - Remplacé `jq '.[0]'` par `jq .` (affiche tout le tableau)
  - Ajouté une vérification explicite des 5 commandes avec les montants réels de la migration
- **CAHIER_RECETTE.md:4.5** :
  - Remplacé `jq .id` par `jq .` (affiche la commande entière)
  - Affichage attendu = JSON complet de la commande Heiata

---

## Résumé des changements

| Fichier | Section | Avant | Après |
|---------|---------|-------|-------|
| CDC_FONCTIONNEL.md | Règle 1.1 | Pas d'exemples JSON | 5 commandes + montants réels |
| CDC_FONCTIONNEL.md | Règle 4.1 | Courte mention d'erreurs | Trois cas distincts détaillés |
| CDC_FONCTIONNEL.md | Questions ouvertes | `environnement: null` | Clé absente, non `null` |
| CAHIER_RECETTE.md | 4.3 (/version) | `environnement: null` | Clé absente |
| CAHIER_RECETTE.md | 4.4 (/orders) | Affiche 1 commande | Affiche 5 + montants |
| CAHIER_RECETTE.md | 4.5 (/orders/1) | Retourne juste l'ID | Retourne JSON complet |

---

## Preuves et lien au code

- `public/index.php:16-19` — Fallback de `deployed-version.json`
- `public/index.php:37-39` — Seul cas d'erreur JSON structurée
- `migrations/001_init.sql:15-20` — Données exactes des 5 commandes
- `src/Orders.php` — Pas de try/catch autour de Db::connect()

---

## Statut final

✅ **Quatre écarts bloquants adressés**  
✅ **Documents à nouveau alignés au code**  
✅ **Exemples JSON vérifiables contre la migration**  
✅ **Prêt pour approbation finale du relecteur**
