-- Vox canlı ortam çalışan aktarımı
-- phpMyAdmin içinde krpsoftc_vox veritabanı seçiliyken çalıştırın.

INSERT INTO employees (full_name, email, start_date, end_date, job_title, active) VALUES
('Belma Baysan', 'belma@voxisitme.com', '2023-07-16', NULL, 'Odyolog', 1),
('Cansu', 'cansu@voxisitme.com', '2023-07-16', '2026-05-01', 'Odyolog', 0),
('Büşra', 'busra@voxisitme.com', '2026-01-16', NULL, 'Odyolog', 1),
('Yeliz', 'yeliz@voxisitme.com', '2023-07-16', '2025-07-16', 'Odyolog', 0),
('Erva', 'erva@voxisitmet.com', '2024-07-16', NULL, 'Odyolog', 0),
('Güneş', 'gunes@voxisitmet.com', '2024-07-16', NULL, 'Odyolog', 0),
('Merve', 'merve@voxisitme.com', '2024-07-16', NULL, 'Odyolog', 0),
('Şeyma', 'seyma@voxisitme.com', '2026-07-17', NULL, 'Odyolog', 0)
ON DUPLICATE KEY UPDATE
  full_name = VALUES(full_name),
  start_date = VALUES(start_date),
  end_date = VALUES(end_date),
  job_title = VALUES(job_title),
  active = VALUES(active);
