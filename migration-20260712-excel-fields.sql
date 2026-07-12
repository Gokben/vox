ALTER TABLE items
  ADD COLUMN serial_no VARCHAR(100) NULL AFTER item_no,
  ADD COLUMN related_items VARCHAR(255) NULL AFTER serial_no,
  ADD COLUMN import_order INT UNSIGNED NULL AFTER related_items,
  ADD COLUMN delivered_at DATETIME NULL AFTER status,
  ADD COLUMN delivery_method VARCHAR(100) NULL AFTER delivered_at,
  ADD COLUMN delivered_by VARCHAR(150) NULL AFTER delivery_method,
  ADD COLUMN delivery_form_no VARCHAR(100) NULL AFTER delivered_by,
  MODIFY COLUMN status VARCHAR(100) NOT NULL DEFAULT 'Eşleşme bekliyor',
  ADD INDEX idx_import_order (import_order),
  ADD INDEX idx_serial_no (serial_no);
