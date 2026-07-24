-- MyInvoice.cz — označení dávky hromadného AI importu (#232).
-- Po hromadném AI importu (~100 dokladů najednou) potřebuje účetní dohledat,
-- co a kam se naimportovalo, aby doklady „nezapadly" mezi stovkami ostatních.
-- import_batch_id = náhodný identifikátor jedné importní dávky (vygeneruje FE);
-- NULL = doklad nevznikl hromadným importem. Idempotentní.

ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS import_batch_id VARCHAR(32) NULL DEFAULT NULL AFTER received_at;

ALTER TABLE purchase_invoices
  ADD INDEX IF NOT EXISTS idx_pi_import_batch (supplier_id, import_batch_id);
