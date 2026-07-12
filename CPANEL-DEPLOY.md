# cPanel Git Version Control ile yayın

Hedef adres: `https://krpsoft.com.tr/lf`

## Güvenli dağıtım davranışı

`.cpanel.yml` dosyası uygulamayı `$HOME/public_html/lf/` klasörüne kopyalar. Şu dosyalara dokunmaz:

- `config.php`
- mevcut veritabanı ve veriler
- SQL ve yerel kurulum dosyaları

Dağıtım komutunda silme seçeneği yoktur; sunucudaki ek dosyalar otomatik silinmez.

## İlk kurulum

1. cPanel Git Version Control içinde GitHub depo adresini klonlayın.
2. Repository ekranından `Update from Remote`, ardından `Deploy HEAD Commit` çalıştırın.
3. `config.example.php` içeriğini örnek alarak `public_html/lf/config.php` dosyasını elle oluşturun.
4. phpMyAdmin ile önce `database.sql`, ardından `database-item-definitions.sql` dosyasını seçilen boş veritabanına içe aktarın.
5. Yönetici şifre özetini yerelde üretip `users` tablosuna ekleyin.

Canlı ve dolu bir veritabanında `database.sql` dosyasını tekrar çalıştırmayın. Şema değişiklikleri ayrıca hazırlanmış migration dosyalarıyla uygulanmalıdır.
