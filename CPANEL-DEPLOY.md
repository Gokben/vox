# Vox CRM — voxisitme.com kurulumu

Hedef adres: `https://voxisitme.com/crm`

## Kod dağıtımı

`.cpanel.yml` uygulama dosyalarını `$HOME/public_html/crm/` klasörüne kopyalar. CRM ile ilgisi olmayan `voxweb` klasörü dağıtıma dahil edilmez.

1. Odeaweb cPanel > Git Version Control bölümünde `https://github.com/Gokben/vox.git` repository'sini klonlayın.
2. Dal olarak `main` kullanın.
3. `Update from Remote`, ardından `Deploy HEAD Commit` çalıştırın.

## Özel veritabanı ayarı

`public_html/crm/config.local.php` dosyasını sunucuda oluşturun. Bu dosya Git tarafından takip edilmez:

```php
<?php
define('LOCAL_DB_DRIVER', 'mysql');
define('DB_HOST', 'localhost');
define('DB_NAME', 'CPANEL_VERITABANI_ADI');
define('DB_USER', 'CPANEL_VERITABANI_KULLANICISI');
define('DB_PASS', 'GUCLU_VERITABANI_SIFRESI');
```

## Mevcut verileri taşıma

1. Eski `krpsoft.com.tr/vox` kurulumunun kullandığı MySQL veritabanını phpMyAdmin ile dışa aktarın.
2. Odeaweb'de boş bir MySQL veritabanı ve kullanıcı oluşturup kullanıcıya tüm yetkileri verin.
3. Dışa aktarılan SQL dosyasını yeni veritabanına içe aktarın.
4. Eski sunucudaki `assets/uploads/` içeriğini yeni sunucuda `public_html/crm/assets/uploads/` altına kopyalayın.
5. Giriş ve örnek kayıtları doğrulamadan eski kurulumu kapatmayın.

Canlı ve dolu veritabanında `database.sql` veya diğer toplu SQL dosyalarını çalıştırmayın; mevcut veri aktarımında yalnızca eski sistemden alınan tam dışa aktarımı kullanın.

## Gereksinimler

- PHP 8.1 veya üzeri
- PDO MySQL ve Fileinfo uzantıları
- MySQL 5.7+ veya MariaDB 10.4+
- `public_html/crm/assets/uploads/` için PHP yazma izni
