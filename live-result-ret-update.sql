-- Canlı hizmet kartlarındaki eski "Red" sonuçlarını "Ret" olarak günceller.
SET NAMES utf8mb4;
START TRANSACTION;

UPDATE patient_services
SET result_name = 'Ret'
WHERE result_name = 'Red';

UPDATE patient_services
SET service_status = 'Ret'
WHERE service_status = 'Red';

COMMIT;
