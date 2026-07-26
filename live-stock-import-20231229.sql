-- 29.12.2023 tarihli DUY AKADEMİ pil stok girişleri.
-- Canlı MySQL veritabanında phpMyAdmin > Import üzerinden çalıştırın.
-- Aynı dosya yeniden çalıştırıldığında stok kartları ve hareketler çoğaltılmaz.

SET NAMES utf8mb4;
START TRANSACTION;

-- Eski canlı şemalarda bulunmayan stok sınıflandırma ve hareket sütunlarını ekler.
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='brands' AND column_name='stock_type');
SET @sql := IF(@exists=0, 'ALTER TABLE brands ADD COLUMN stock_type VARCHAR(50) NULL', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='models' AND column_name='stock_type');
SET @sql := IF(@exists=0, 'ALTER TABLE models ADD COLUMN stock_type VARCHAR(50) NULL', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_cards' AND column_name='stock_type');
SET @sql := IF(@exists=0, 'ALTER TABLE stock_cards ADD COLUMN stock_type VARCHAR(50) NULL', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_movements' AND column_name='current_account_id');
SET @sql := IF(@exists=0, 'ALTER TABLE stock_movements ADD COLUMN current_account_id INT UNSIGNED NULL', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_movements' AND column_name='invoice_no');
SET @sql := IF(@exists=0, 'ALTER TABLE stock_movements ADD COLUMN invoice_no VARCHAR(100) NULL', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_movements' AND column_name='serial_numbers');
SET @sql := IF(@exists=0, 'ALTER TABLE stock_movements ADD COLUMN serial_numbers TEXT NULL', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_movements' AND column_name='purchase_price');
SET @sql := IF(@exists=0, 'ALTER TABLE stock_movements ADD COLUMN purchase_price DECIMAL(12,2) NULL', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_movements' AND column_name='sale_price');
SET @sql := IF(@exists=0, 'ALTER TABLE stock_movements ADD COLUMN sale_price DECIMAL(12,2) NULL', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_movements' AND column_name='vat_rate');
SET @sql := IF(@exists=0, 'ALTER TABLE stock_movements ADD COLUMN vat_rate DECIMAL(5,2) NULL', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_movements' AND column_name='unit_cost');
SET @sql := IF(@exists=0, 'ALTER TABLE stock_movements ADD COLUMN unit_cost DECIMAL(12,2) NULL', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

INSERT INTO current_accounts
  (code, title, short_name, tax_office, tax_number, account_type, currency, phone, email, contact_person, billing_address, shipping_address)
VALUES
  ('CR-07', 'DUY AKADEMİ İŞİTME CİHAZLARI İTHALAT İHRACAT TİCARET LTD. ŞTİ.', 'DUY AKADEMİ', 'MECİDİYEKÖY VERGİ DAİRESİ MÜD.', '3200293441', 'supplier', 'TRY', '', 'mustafasarkut', '', '19 MAYIS M. HALASKARGAZİ C. TERMİNÜS PALAS NO:224/1 ŞİŞLİ / İSTANBUL', '')
ON DUPLICATE KEY UPDATE
  title=VALUES(title), short_name=VALUES(short_name), tax_office=VALUES(tax_office), tax_number=VALUES(tax_number),
  account_type=VALUES(account_type), currency=VALUES(currency), email=VALUES(email), billing_address=VALUES(billing_address);

INSERT INTO brands (name, stock_type) VALUES ('DB Power', 'Pil')
ON DUPLICATE KEY UPDATE stock_type=VALUES(stock_type);

INSERT INTO models (brand_id, name, stock_type)
SELECT id, '10', 'Pil' FROM brands WHERE name='DB Power'
ON DUPLICATE KEY UPDATE stock_type=VALUES(stock_type);
INSERT INTO models (brand_id, name, stock_type)
SELECT id, '13', 'Pil' FROM brands WHERE name='DB Power'
ON DUPLICATE KEY UPDATE stock_type=VALUES(stock_type);
INSERT INTO models (brand_id, name, stock_type)
SELECT id, '312', 'Pil' FROM brands WHERE name='DB Power'
ON DUPLICATE KEY UPDATE stock_type=VALUES(stock_type);
INSERT INTO models (brand_id, name, stock_type)
SELECT id, '675', 'Pil' FROM brands WHERE name='DB Power'
ON DUPLICATE KEY UPDATE stock_type=VALUES(stock_type);

INSERT INTO stock_cards
  (stock_code, stock_name, brand, model, serial_no, min_stock, max_stock, purchase_price, sale_price, vat_rate, unit_cost, stock_type)
VALUES
  ('DBP-10', 'DB POWER 10 PİL', 'DB Power', '10', 'DBP-10', 0, 0, 900.00, 0, 10, 3.75, 'Pil'),
  ('DBP-13', 'DB POWER 13 PİL', 'DB Power', '13', 'DBP-13', 0, 0, 2250.00, 0, 10, 3.75, 'Pil'),
  ('DBP-312', 'DB POWER 312 PİL', 'DB Power', '312', 'DBP-312', 0, 0, 2250.00, 0, 10, 3.75, 'Pil'),
  ('DBP-675', 'DB POWER 675 PİL', 'DB Power', '675', 'DBP-675', 0, 0, 900.00, 0, 10, 3.75, 'Pil')
ON DUPLICATE KEY UPDATE
  stock_name=VALUES(stock_name), brand=VALUES(brand), model=VALUES(model), purchase_price=VALUES(purchase_price),
  sale_price=VALUES(sale_price), vat_rate=VALUES(vat_rate), unit_cost=VALUES(unit_cost), stock_type=VALUES(stock_type);

INSERT INTO stock_movements
  (stock_id, movement_type, quantity, movement_date, description, current_account_id, invoice_no, serial_numbers, purchase_price, sale_price, vat_rate, unit_cost)
SELECT s.id, 'Giriş', v.quantity, '2023-12-29', 'TEMELFATURA / SATIŞ', c.id, 'AK2023000000949', '[]', v.purchase_price, 0, 10, 3.75
FROM (
  SELECT 'DBP-312' AS stock_code, 600 AS quantity, 2250.00 AS purchase_price
  UNION ALL SELECT 'DBP-13', 600, 2250.00
  UNION ALL SELECT 'DBP-675', 240, 900.00
  UNION ALL SELECT 'DBP-10', 240, 900.00
) v
INNER JOIN stock_cards s ON s.stock_code=v.stock_code
INNER JOIN current_accounts c ON c.code='CR-07'
WHERE NOT EXISTS (
  SELECT 1 FROM stock_movements m
  WHERE m.stock_id=s.id AND m.movement_type='Giriş' AND m.invoice_no='AK2023000000949'
);

COMMIT;
