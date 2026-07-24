-- Zahození `supplier.data_box_type` — sloupec byl matoucí a nepoužívaný.
--
-- Přidala ho 0038 jako „typ datové schránky (OVM/PO/FO)", ale UI pro něj nikdy
-- needitovalo žádné pole, takže byl na všech instalacích NULL. Jediný kód, který
-- ho četl, byl generátor EPO `VetaP` — a ten ho používal chybně jako typ
-- daňového subjektu (`typ_ds`), což rozbilo podání DPH/KH/SHV všem právnickým
-- osobám (viz v4.49.2). Typ subjektu drží `taxpayer_type` (fo/po); tenhle sloupec
-- s ním nijak nesouvisí a jeho jméno k té záměně přímo zvalo.
--
-- Datové schránky zatím implementované nejsou. Až budou, dostanou vlastní pole
-- s jednoznačným pojmenováním — vzkřísit prázdný sloupec se zavádějícím názvem
-- nemá smysl. `data_box_id` zůstává, to UI edituje.
--
-- Žádná data se neztrácejí: sloupec je všude NULL.

ALTER TABLE supplier
    DROP COLUMN IF EXISTS data_box_type;
