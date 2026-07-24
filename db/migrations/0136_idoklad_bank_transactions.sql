-- Bankovní pohyby importované z iDokladu.
-- source_ref nese stabilní iDoklad BankStatement.Id a slouží k idempotenci importu.
--
-- POZOR — proč tu NENÍ UNIQUE (source, source_ref):
-- Původní verze migrace UNIQUE přidávala a předem tvrdě zastavila upgrade, pokud
-- v datech existovala duplicita. Na starších instalacích ale historické duplicity
-- reálně existují (email_notice avíza z doby před idempotenčním lookupem, #161),
-- takže se z pojistky proti race stal blokátor celého upgradu aplikace — viz #225.
-- Duplicity samy o sobě nic nerozbíjí (jsou obsahově shodné), zato ruční čištění
-- živé tabulky, na které visí invoice_payments / payment_matches, riskantní je.
--
-- Idempotence proto zůstává jen na aplikační vrstvě, kde už byla:
--   - IdokladBankTransactionImporter::exists()  (source='idoklad'  AND source_ref = ?)
--   - BankEmailNoticeRepository                 (source='email_notice' AND source_ref = ?)
-- Index níže je kvůli nim (a kvůli prefix scanu v lastExternalId()) nutný, ale
-- záměrně NEunikátní.

ALTER TABLE bank_statements
  MODIFY COLUMN source ENUM('gpc','email_notice','pdf','idoklad') NOT NULL DEFAULT 'gpc';

ALTER TABLE bank_transactions
  MODIFY COLUMN source ENUM('statement','email_notice','idoklad') NOT NULL DEFAULT 'statement';

ALTER TABLE bank_transactions
  ADD KEY IF NOT EXISTS idx_bank_transaction_source_ref (source, source_ref);
