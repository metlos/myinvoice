-- MyInvoice.cz — invoices.created_by smí být NULL pro systémové/automatické akce (#235).
-- Background procesy bez přihlášené relace (bankovní párování → PaymentTaxDocumentCreator,
-- StatementMatcher) předávají userId = 0 a doklad zakládají jako systémovou akci; NOT NULL
-- na created_by je shazoval na "1048 Column 'created_by' cannot be null".
-- NULL = "systém / automatická úloha", stejně jako u invoice_payments/signing_profiles/…
-- FK fk_inv_user zůstává (na nullable sloupci se NULL nekontroluje). Idempotentní.

ALTER TABLE invoices
  MODIFY COLUMN created_by BIGINT UNSIGNED NULL DEFAULT NULL;
