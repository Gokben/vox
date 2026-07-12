# KRP Lost & Found kurulum

1. Hosting panelinden bir MySQL veritabanı ve kullanıcı oluşturun.
2. `database.sql` dosyasını phpMyAdmin ile içe aktarın.
3. `config.php` içindeki `DB_NAME`, `DB_USER` ve `DB_PASS` değerlerini düzenleyin.
4. Dosyaları sitenin `public_html/lostfound` klasörüne yükleyin.
5. Geçici olarak `https://krpsoft.com.tr/lostfound/password.php` adresini açıp en az 10 karakterli yönetici şifresi için özet üretin.
6. Üretilen özeti kullanarak phpMyAdmin'de şu sorguyu çalıştırın:

```sql
INSERT INTO users (name,email,password_hash,role)
VALUES ('Sofitel Yönetici','admin@sofitel.com','URETILEN_OZET','Yönetici');
```

7. `password.php` ve `database.sql` dosyalarını sunucudan silin.
8. `https://krpsoft.com.tr/lostfound/login.php` adresinden giriş yapın.

Gereksinim: PHP 8.1+ ve MySQL 5.7+/MariaDB 10.4+.
