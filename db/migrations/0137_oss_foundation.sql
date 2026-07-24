-- MyInvoice.cz — OSS (One Stop Shop) evidence a EPO podklady.
-- Ruční označení OSS řádků, kvartální přehled a stav období. Idempotentní.

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS oss_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER is_identified,
  ADD COLUMN IF NOT EXISTS oss_valid_from DATE NULL AFTER oss_enabled,
  ADD COLUMN IF NOT EXISTS oss_valid_to DATE NULL AFTER oss_valid_from,
  ADD COLUMN IF NOT EXISTS oss_identification_country CHAR(2) NULL AFTER oss_valid_to,
  ADD COLUMN IF NOT EXISTS oss_return_currency CHAR(3) NOT NULL DEFAULT 'EUR' AFTER oss_identification_country;

ALTER TABLE invoice_items
  ADD COLUMN IF NOT EXISTS oss_applicable TINYINT(1) NOT NULL DEFAULT 0 AFTER vat_classification_code,
  ADD COLUMN IF NOT EXISTS oss_consumer_country CHAR(2) NULL AFTER oss_applicable,
  ADD COLUMN IF NOT EXISTS oss_rate_type VARCHAR(32) NULL AFTER oss_consumer_country,
  ADD COLUMN IF NOT EXISTS oss_supply_type ENUM('goods','services') NULL AFTER oss_rate_type,
  ADD COLUMN IF NOT EXISTS oss_exchange_rate DECIMAL(18,8) NULL AFTER oss_supply_type,
  ADD COLUMN IF NOT EXISTS oss_exchange_rate_date DATE NULL AFTER oss_exchange_rate,
  ADD COLUMN IF NOT EXISTS oss_taxable_amount_return DECIMAL(14,2) NULL AFTER oss_exchange_rate_date,
  ADD COLUMN IF NOT EXISTS oss_vat_amount_return DECIMAL(14,2) NULL AFTER oss_taxable_amount_return,
  ADD COLUMN IF NOT EXISTS oss_original_period CHAR(6) NULL AFTER oss_vat_amount_return;

CREATE INDEX IF NOT EXISTS idx_invoice_items_oss
  ON invoice_items (oss_applicable, oss_consumer_country);
