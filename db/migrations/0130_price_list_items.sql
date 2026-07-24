-- Ceníkové položky, explicitní ceny v měnách a zákaznické cenové výjimky.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS price_list_items (
  id                             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id                    INT UNSIGNED NOT NULL,
  code                           VARCHAR(50) NOT NULL,
  name                           VARCHAR(150) NOT NULL,
  description                    VARCHAR(500) NOT NULL,
  unit                           VARCHAR(20) NOT NULL,
  vat_rate_id                    INT UNSIGNED NOT NULL,
  prices_include_vat             TINYINT(1) NOT NULL DEFAULT 0,
  base_currency_code             CHAR(3) NOT NULL,
  allow_exchange_rate_conversion TINYINT(1) NOT NULL DEFAULT 0,
  archived                       TINYINT(1) NOT NULL DEFAULT 0,
  created_at                     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_price_list_item_code (supplier_id, code),
  KEY idx_price_list_items_list (supplier_id, archived, name),
  KEY idx_price_list_items_vat (vat_rate_id),
  KEY idx_price_list_items_base_currency (supplier_id, base_currency_code),
  CONSTRAINT fk_price_list_item_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_price_list_item_vat
    FOREIGN KEY (vat_rate_id) REFERENCES vat_rates(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_list_item_prices (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  price_list_item_id BIGINT UNSIGNED NOT NULL,
  currency_code      CHAR(3) NOT NULL,
  unit_price         DECIMAL(12,2) NOT NULL,
  archived           TINYINT(1) NOT NULL DEFAULT 0,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_price_list_item_currency (supplier_id, price_list_item_id, currency_code),
  KEY idx_price_list_prices_item (price_list_item_id, archived),
  KEY idx_price_list_prices_currency (supplier_id, currency_code, archived),
  CONSTRAINT fk_price_list_price_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_price_list_price_item
    FOREIGN KEY (price_list_item_id) REFERENCES price_list_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS price_list_customer_overrides (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        INT UNSIGNED NOT NULL,
  price_list_item_id BIGINT UNSIGNED NOT NULL,
  client_id          BIGINT UNSIGNED NOT NULL,
  currency_code      CHAR(3) NOT NULL,
  unit_price         DECIMAL(12,2) NOT NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_price_list_customer_currency (supplier_id, price_list_item_id, client_id, currency_code),
  KEY idx_price_list_customer_lookup (supplier_id, client_id, currency_code),
  KEY idx_price_list_customer_item (price_list_item_id),
  CONSTRAINT fk_price_list_customer_supplier
    FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE,
  CONSTRAINT fk_price_list_customer_item
    FOREIGN KEY (price_list_item_id) REFERENCES price_list_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_price_list_customer_client
    FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE recurring_invoice_template_items
  ADD COLUMN IF NOT EXISTS price_list_item_id BIGINT UNSIGNED NULL AFTER template_id,
  ADD COLUMN IF NOT EXISTS catalog_policy VARCHAR(24) NOT NULL DEFAULT 'fixed' AFTER price_list_item_id,
  ADD COLUMN IF NOT EXISTS description_source VARCHAR(16) NOT NULL DEFAULT 'template' AFTER catalog_policy,
  ADD COLUMN IF NOT EXISTS catalog_price_source VARCHAR(32) NULL AFTER description_source,
  ADD COLUMN IF NOT EXISTS catalog_source_currency_code CHAR(3) NULL AFTER catalog_price_source,
  ADD COLUMN IF NOT EXISTS catalog_source_unit_price DECIMAL(12,2) NULL AFTER catalog_source_currency_code,
  ADD COLUMN IF NOT EXISTS catalog_exchange_rate DECIMAL(18,8) NULL AFTER catalog_source_unit_price,
  ADD COLUMN IF NOT EXISTS catalog_exchange_rate_date DATE NULL AFTER catalog_exchange_rate;

ALTER TABLE recurring_invoice_template_items
  ADD KEY IF NOT EXISTS idx_recurring_item_price_list (price_list_item_id);

ALTER TABLE recurring_invoice_template_items
  ADD CONSTRAINT fk_recurring_item_price_list
    FOREIGN KEY IF NOT EXISTS (price_list_item_id)
    REFERENCES price_list_items(id) ON DELETE RESTRICT;
