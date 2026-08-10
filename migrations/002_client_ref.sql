-- Ajoute client_ref = 'CLI-' || UPPER(client) sur la table orders.
-- Idempotent : recréation de table via orders_new pour compatibilité SQLite
-- (ALTER TABLE ADD COLUMN n'est pas idempotent dans SQLite).
CREATE TABLE IF NOT EXISTS orders_new (
  id            INTEGER PRIMARY KEY,
  client        TEXT    NOT NULL,
  montant_cents INTEGER NOT NULL,
  devise        TEXT    NOT NULL DEFAULT 'XPF',
  statut        TEXT    NOT NULL,
  client_ref    TEXT    NOT NULL DEFAULT ''
);
INSERT OR IGNORE INTO orders_new (id, client, montant_cents, devise, statut, client_ref)
  SELECT id, client, montant_cents, devise, statut, 'CLI-' || UPPER(client)
  FROM orders;
DROP TABLE IF EXISTS orders;
ALTER TABLE orders_new RENAME TO orders;
