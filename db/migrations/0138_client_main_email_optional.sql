-- MyInvoice.cz — hlavní e-mail klienta je nepovinný (#221).
-- Historické doklady často e-mail protistrany nemají; NULL = "nevíme", '' se nepoužívá.
-- Zároveň uklízí placeholder z importů, který NOT NULL obcházel. Idempotentní.

ALTER TABLE clients
  MODIFY COLUMN main_email VARCHAR(190) NULL;

-- Importy (iDoklad/Fakturoid/ClientResolver) dosud dosazovaly fiktivní adresu,
-- aby prošly přes NOT NULL. Teď je z nich NULL, ať se na ně nedá omylem odeslat.
UPDATE clients SET main_email = NULL WHERE main_email = 'unknown@import.local';

-- Prázdné řetězce sjednotit na NULL (starší data mohla projít mimo validaci).
UPDATE clients SET main_email = NULL WHERE main_email = '';
