-- #225 — sjednocení schématu po změně 0136.
--
-- Instalace, které 0136 v původní podobě už úspěšně aplikovaly (tj. neměly
-- duplicity), mají na bank_transactions UNIQUE uq_bank_transaction_source_ref.
-- Nově se unikátnost nevyžaduje vůbec (důvod viz komentář v 0136), takže ji tady
-- zahodíme a nahradíme běžným indexem — aby všechny instalace měly stejné schéma
-- bez ohledu na to, přes kterou verzi 0136 prošly.
--
-- Instalace, které 0136 nikdy neaplikovaly (upgrade spadl na #225), tu UNIQUE
-- nemají a nová 0136 jim rovnou založí idx_bank_transaction_source_ref — pak je
-- tato migrace no-op. Obojí je idempotentní přes MariaDB native IF [NOT] EXISTS.

ALTER TABLE bank_transactions
  ADD KEY IF NOT EXISTS idx_bank_transaction_source_ref (source, source_ref);

ALTER TABLE bank_transactions
  DROP INDEX IF EXISTS uq_bank_transaction_source_ref;
