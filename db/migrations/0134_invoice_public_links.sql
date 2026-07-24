-- 0134: Web faktura — trvalý veřejný odkaz na vystavenou fakturu.
--
-- Klient dostane odkaz /invoice/{token}, kde si fakturu bez přihlášení prohlédne
-- v HTML a stáhne PDF (vzor Fakturoid „web faktura"). Token se generuje lazy
-- (první použití v UI / při odeslání e-mailu) jako bin2hex(random_bytes(24))
-- → 48 hex znaků (vzor invoices.approval_token, migrace 0002).
--
--   public_token      — tajný token veřejného odkazu; NULL = odkaz zatím nevznikl.
--                       Regenerace (revokace) = přepsání novým tokenem.
--   public_viewed_at  — poslední zobrazení klientem (anonymní přístup); indikace
--                       „zobrazeno klientem" v detailu faktury.
--
-- Idempotent přes MariaDB native `IF NOT EXISTS` guards (projekt vyžaduje 10.6+).

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS public_token CHAR(48) NULL DEFAULT NULL AFTER pdf_generated_at,
  ADD COLUMN IF NOT EXISTS public_viewed_at DATETIME NULL DEFAULT NULL AFTER public_token,
  ADD UNIQUE KEY IF NOT EXISTS uq_inv_public_token (public_token);
