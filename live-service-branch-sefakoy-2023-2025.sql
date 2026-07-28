-- 2023, 2024 ve 2025 hastalarının hizmet kartlarını SEFAKÖY şubesine bağlar.
SET NAMES utf8mb4;
START TRANSACTION;

SET @sefakoy_branch_id = (
    SELECT id
    FROM branches
    WHERE name = 'SEFAKÖY'
    LIMIT 1
);

UPDATE patient_services ps
JOIN patients p ON p.id = ps.patient_id
JOIN branches b ON b.id = @sefakoy_branch_id
SET ps.branch_id = b.id,
    ps.branch_name = b.name
WHERE p.record_date >= '2023-01-01'
  AND p.record_date < '2026-01-01';

COMMIT;
