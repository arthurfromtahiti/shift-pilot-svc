-- Schéma initial du service pilote.
CREATE TABLE schema_migrations (
  version    INTEGER PRIMARY KEY,
  applied_at TEXT NOT NULL
);

CREATE TABLE orders (
  id            INTEGER PRIMARY KEY,
  client        TEXT    NOT NULL,
  montant_cents INTEGER NOT NULL,
  devise        TEXT    NOT NULL DEFAULT 'XPF',
  statut        TEXT    NOT NULL
);

INSERT INTO orders (id, client, montant_cents, devise, statut) VALUES
  (1, 'Heiata', 420000, 'XPF', 'payee'),
  (2, 'Teiki',  180000, 'XPF', 'annulee'),
  (3, 'Manoa',  960000, 'XPF', 'payee'),
  (4, 'Vaite',  305000, 'XPF', 'payee'),
  (5, 'Moana',   75000, 'XPF', 'annulee');
