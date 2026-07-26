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
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_cards' AND column_name='power_usage');
SET @sql := IF(@exists=0, 'ALTER TABLE stock_cards ADD COLUMN power_usage VARCHAR(50) NULL', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_cards' AND column_name='product_color');
SET @sql := IF(@exists=0, 'ALTER TABLE stock_cards ADD COLUMN product_color VARCHAR(50) NULL', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @exists := (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='stock_cards' AND column_name='image_path');
SET @sql := IF(@exists=0, 'ALTER TABLE stock_cards ADD COLUMN image_path VARCHAR(255) NULL', 'SELECT 1'); PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
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
INSERT INTO brands (name, stock_type) VALUES ('Resound', 'İşitme Cihazı')
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
INSERT INTO models (brand_id, name, stock_type)
SELECT id, model_name, 'İşitme Cihazı' FROM brands
CROSS JOIN (
  SELECT 'KE261' AS model_name UNION ALL SELECT 'KE267' UNION ALL SELECT 'KE277' UNION ALL SELECT 'KE288' UNION ALL SELECT 'KE298'
  UNION ALL SELECT 'KE498' UNION ALL SELECT 'KE488' UNION ALL SELECT 'KE461' UNION ALL SELECT 'KE461 DRWC'
) model_data
WHERE brands.name='Resound'
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

INSERT INTO stock_cards
  (stock_code, stock_name, brand, model, device_type, serial_no, min_stock, max_stock, purchase_price, sale_price, vat_rate, unit_cost, stock_type, power_usage, product_color, image_path)
VALUES
  ('KE261', 'ReSound Key 261 DRW RIE', 'Resound', 'KE261', 'Kanal İçi Alıcı RIC/RIE', '54435111', 3, 10, 16350.00, 10000.00, 0, 4087.50, 'İşitme Cihazı', 'Pilli', 'Bej', 'assets/uploads/stocks/stock-918037c9017a2d33ced35542.jpg'),
  ('KE267', 'ReSound Key 267 DRW RIE', 'Resound', 'KE267', 'Kanal İçi Alıcı RIC/RIE', '54435', 5, 10, 15000.00, 15000.00, 20, 15000.00, 'İşitme Cihazı', 'Pilli', 'Bej', 'assets/uploads/stocks/stock-5dd37f3dc5608e206fe8a461.jpg'),
  ('KE277', 'ReSound Key 277 DRW RIE', 'Resound', 'KE277', 'Kanal İçi Alıcı RIC/RIE', 'KE277', 5, 10, 0, 15000.00, 20, 0, 'İşitme Cihazı', 'Pilli', 'Bej', 'assets/uploads/stocks/stock-8b2210338ef59612d7854b69.jpg'),
  ('KE288', 'ReSound Key 288 DWH', 'Resound', 'KE288', 'Kanal İçi Alıcı RIC/RIE', 'KE288', 5, 10, 0, 15000.00, 20, 0, 'İşitme Cihazı', 'Pilli', 'Bej', 'assets/uploads/stocks/stock-3160bb6552653731b11c48a9.jpg'),
  ('KE298', 'ReSound Key 298 DW', 'Resound', 'KE298', 'Kanal İçi Alıcı RIC/RIE', 'KE298', 5, 10, 0, 15000.00, 20, 0, 'İşitme Cihazı', 'Pilli', 'Bej', 'assets/uploads/stocks/stock-244d374df186cbf1cb14c2ea.jpg'),
  ('Key498', 'ReSound Key 498 DW', 'Resound', 'KE498', 'Kanal İçi Alıcı RIC/RIE', 'Key498', 5, 10, 8736.00, 15000.00, 0, 4368.00, 'İşitme Cihazı', 'Pilli', 'Bej', 'assets/uploads/stocks/stock-e957ec128ac29447770d16fb.jpg'),
  ('Key488', 'ReSound Key 488 DWH', 'Resound', 'KE488', 'Kanal İçi Alıcı RIC/RIE', 'Key488', 5, 10, 17472.00, 15000.00, 0, 4368.00, 'İşitme Cihazı', 'Pilli', 'Bej', 'assets/uploads/stocks/stock-fcf80b8eff608a3e7afb04fb.jpg'),
  ('Key461', 'ReSound Key 461 DRW RIE', 'Resound', 'KE461', 'Kanal İçi Alıcı RIC/RIE', 'Key461', 5, 10, 9555.00, 15000.00, 0, 4777.50, 'İşitme Cihazı', 'Pilli', 'Bej', 'assets/uploads/stocks/stock-6df64059faa448e4daf0601d.jpg'),
  ('Key461C', 'ReSound Key 461 DRWC RIE', 'Resound', 'KE461 DRWC', 'Kanal İçi Alıcı RIC/RIE', 'Key461C', 5, 10, 13440.00, 15000.00, 0, 6720.00, 'İşitme Cihazı', 'Şarjlı', 'Bej', 'assets/uploads/stocks/stock-277183d8e1b286189692410a.jpg'),
  ('DETAX', 'DETAX İZ ALMA MACUNU A+B', '', '', '', 'DTX-IZ-MACUNU-AB', 10, 20, 1100.00, 0, 20, 1100.00, 'Sarf Malzeme', '', '', NULL)
ON DUPLICATE KEY UPDATE
  stock_name=VALUES(stock_name), brand=VALUES(brand), model=VALUES(model), device_type=VALUES(device_type), min_stock=VALUES(min_stock), max_stock=VALUES(max_stock),
  purchase_price=VALUES(purchase_price), sale_price=VALUES(sale_price), vat_rate=VALUES(vat_rate), unit_cost=VALUES(unit_cost), stock_type=VALUES(stock_type),
  power_usage=VALUES(power_usage), product_color=VALUES(product_color), image_path=VALUES(image_path);

INSERT INTO stock_movements
  (stock_id, movement_type, quantity, movement_date, description, current_account_id, invoice_no, serial_numbers, purchase_price, sale_price, vat_rate, unit_cost)
SELECT s.id, 'Giriş', v.quantity, '2023-10-11', 'TEMELFATURA / SATIŞ', c.id, 'AKM2023000000109', v.serial_numbers, v.purchase_price, 0, 0, v.unit_cost
FROM (
  SELECT 'Key498' AS stock_code, 2 AS quantity, 8736.00 AS purchase_price, 4368.00 AS unit_cost, '[]' AS serial_numbers
  UNION ALL SELECT 'Key488', 4, 17472.00, 4368.00, '[]'
  UNION ALL SELECT 'Key461', 2, 9555.00, 4777.50, '["22532352352o","2253235235255"]'
  UNION ALL SELECT 'Key461C', 2, 13440.00, 6720.00, '[]'
  UNION ALL SELECT 'KE261', 4, 16350.00, 4087.50, '[]'
) v
INNER JOIN stock_cards s ON s.stock_code=v.stock_code
INNER JOIN current_accounts c ON c.code='CR-01'
WHERE NOT EXISTS (
  SELECT 1 FROM stock_movements m WHERE m.stock_id=s.id AND m.movement_type='Giriş' AND m.invoice_no='AKM2023000000109'
);

INSERT INTO stock_movements
  (stock_id, movement_type, quantity, movement_date, description, current_account_id, invoice_no, serial_numbers, purchase_price, sale_price, vat_rate, unit_cost)
SELECT s.id, 'Giriş', 1, '2023-12-29', 'TEMELFATURA / SATIŞ', c.id, 'AK2023000000949', '[]', 1100.00, 0, 20, 1100.00
FROM stock_cards s INNER JOIN current_accounts c ON c.code='CR-07'
WHERE s.stock_code='DETAX' AND NOT EXISTS (
  SELECT 1 FROM stock_movements m WHERE m.stock_id=s.id AND m.movement_type='Giriş' AND m.invoice_no='AK2023000000949'
);

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
