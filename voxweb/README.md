# Vox İşitme — Bludit

Vox İşitme Merkezi için Bludit 3.22 tabanlı, veritabanı gerektirmeyen hafif PHP sitesi.

## Gereksinimler

- PHP 8.0+
- PHP `mbstring`, `gd`, `dom` ve `json` uzantıları
- Apache `mod_rewrite` veya eşdeğer URL yönlendirmesi

## Kurulum

1. Dosyaları PHP destekli web köküne yükleyin.
2. Alan adını tarayıcıda açın.
3. Türkçe dilini seçip `admin` kullanıcısı için güçlü bir parola belirleyin.
4. Kurulum Vox temasını, Hakkımızda sayfasını ve Randevu sayfasını otomatik hazırlar.

Yönetim paneli `/admin/` adresindedir. Randevu talepleri sunucuda `bl-content/workspaces/vox-appointments/requests.jsonl` dosyasında tutulur; bu dosya Git tarafından izlenmez.

## Yerel geliştirme

```powershell
C:\xampp\php\php.exe -d session.save_path=bl-content/tmp/sessions -S 127.0.0.1:8000
```

Özel tema `bl-themes/vox/` klasöründedir.
