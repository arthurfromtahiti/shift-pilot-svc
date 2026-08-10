ALTER TABLE orders ADD COLUMN client_ref TEXT NOT NULL DEFAULT '';
UPDATE orders SET client_ref = 'CLI-' || UPPER(client);
