-- Hizmet kartlarında satış stoğu bağlantısını ve eski kısa ilgili kişi adlarını günceller.
SET NAMES utf8mb4;
START TRANSACTION;

SET @has_stock_id = (
    SELECT COUNT(*)
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = 'patient_services'
      AND column_name = 'stock_id'
);
SET @stock_id_sql = IF(
    @has_stock_id = 0,
    'ALTER TABLE patient_services ADD COLUMN stock_id BIGINT NULL',
    'SELECT 1'
);
PREPARE stock_id_statement FROM @stock_id_sql;
EXECUTE stock_id_statement;
DEALLOCATE PREPARE stock_id_statement;

UPDATE patient_services
SET contact_person = CASE contact_person
    WHEN 'Yeliz' THEN 'Yeliz Girgin Özkan'
    WHEN 'Büşra' THEN 'Büşra Akar Avcı'
    WHEN 'Erva' THEN 'Erva Özsarı'
    WHEN 'Güneş' THEN 'Güneş İba'
    WHEN 'Merve' THEN 'Merve Koçal'
    WHEN 'Şeyma' THEN 'Şeyma Nur Büyükkayın'
    WHEN 'Cansu, Belma Baysan' THEN 'Merve Cansu Eryılmaz, Belma Baysan'
    WHEN 'Büşra, Belma Baysan' THEN 'Büşra Akar Avcı, Belma Baysan'
    WHEN 'Cansu, Büşra' THEN 'Merve Cansu Eryılmaz, Büşra Akar Avcı'
    ELSE contact_person
END
WHERE contact_person IN (
    'Yeliz', 'Büşra', 'Erva', 'Güneş', 'Merve', 'Şeyma',
    'Cansu, Belma Baysan', 'Büşra, Belma Baysan', 'Cansu, Büşra'
);

COMMIT;
