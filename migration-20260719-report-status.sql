-- MySQL / cPanel için rapor durumu alanı.
ALTER TABLE patients MODIFY COLUMN report_status ENUM('Var','Yok','Rapor getirdi','Rapor getirecek','Rapor gerekmedi','Özel Reçete','Özel reçete getirdi','Özel reçete getirecek') NULL;

UPDATE patients
SET report_status = CASE
    WHEN report_status = 'Var' THEN 'Rapor getirdi'
    WHEN report_status = 'Yok' THEN 'Rapor gerekmedi'
    WHEN TRIM(COALESCE(report_info, '')) = '' THEN 'Rapor getirdi'
    WHEN UPPER(COALESCE(report_info, '')) LIKE '%ÖZEL REÇETE%' THEN 'Özel reçete getirdi'
    WHEN UPPER(COALESCE(report_info, '')) LIKE '%ÖZEL SATIŞ%' THEN 'Özel reçete getirdi'
    WHEN TRIM(UPPER(COALESCE(report_info, ''))) = 'ÖZEL' THEN 'Özel reçete getirdi'
    WHEN UPPER(COALESCE(report_info, '')) LIKE '%RAPORLU%' THEN 'Rapor getirdi'
    WHEN UPPER(COALESCE(report_info, '')) LIKE '%TESTİ VAR%' THEN 'Rapor getirdi'
    WHEN UPPER(COALESCE(report_info, '')) LIKE '%RAPOR GELDİ%' THEN 'Rapor getirdi'
    WHEN UPPER(COALESCE(report_info, '')) LIKE '%RAPOR GETİRDİ%' THEN 'Rapor getirdi'
    WHEN UPPER(COALESCE(report_info, '')) LIKE '%GETİRECEK%'
      OR UPPER(COALESCE(report_info, '')) LIKE '%ÇIKARILACAK%'
      OR UPPER(COALESCE(report_info, '')) LIKE '%ÇIKARACAK%' THEN 'Rapor getirecek'
    WHEN UPPER(COALESCE(report_info, '')) LIKE '%RAPOR VAR%'
      OR UPPER(COALESCE(report_info, '')) LIKE '%RAPORU VAR%' THEN 'Rapor getirdi'
    WHEN UPPER(COALESCE(report_info, '')) LIKE '%RAPOR YOK%'
      OR UPPER(COALESCE(report_info, '')) LIKE '%RAPORU YOK%'
      OR UPPER(COALESCE(report_info, '')) LIKE '%GEREKMED%'
      OR UPPER(COALESCE(report_info, '')) LIKE '%KULLANMAYACAK%'
      OR UPPER(COALESCE(report_info, '')) LIKE '%HAKKI YOK%' THEN 'Rapor gerekmedi'
    ELSE report_status
END
WHERE report_status IS NULL OR report_status = '';

UPDATE patients SET report_status = 'Rapor getirdi' WHERE report_status = 'Var';
UPDATE patients SET report_status = 'Rapor gerekmedi' WHERE report_status = 'Yok';
UPDATE patients SET report_status = 'Özel reçete getirdi' WHERE report_status = 'Özel Reçete';
ALTER TABLE patients MODIFY COLUMN report_status ENUM('Rapor getirdi','Rapor getirecek','Rapor gerekmedi','Özel reçete getirdi','Özel reçete getirecek') NULL;
