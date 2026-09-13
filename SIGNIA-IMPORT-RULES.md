# Signia fiyat listesi aktarım kuralları

Bu kurallar kullanıcı tarafından 2026 Temmuz Signia fiyat listesi aktarımında onaylandı. Sonraki Signia aktarımlarında aynı kararları tekrar sorma; yalnızca yeni, çelişkili veya belirsiz bilgileri sor. Kullanıcının daha sonraki açık talimatı bu kuralları değiştirir.

## Model ve stok kartları

- PDF'deki model ve performans seviyesini birleştir: Styletto 7IX, Pure 312 7AX gibi.
- Modelleri Signia marka model listesine de ekle. Aynı marka içindeki mevcut eşleşmeleri kullan; mükerrer oluşturma. Boşluk, harf büyüklüğü ve ayraç farklarını incele; farklı teknik varyantları birleştirme.
- Stok kodu için model adını kullan. Kit stok kodunun sonuna `Kit` ekle; model tanımı temel model adını korur.
- `Intuis CIC 50/55/65` tek model ve tek stok kalemi olarak kalır; 50, 55 ve 65 diye bölünmez.
- PDF'de birlikte verilen diğer ürün adlarını da koru; yeni belirsizlik varsa sor.

## Kitler

- `Set` yerine `Kit` kullan.
- PDF'de iki cihaz ve şarj cihazı dahil belirtilen fiyatı aynen koru; ikiye bölme, tek cihaz fiyatına dönüştürme.
- Kit iki cihaz ve şarj cihazıyla birlikte satılmalı; parçaları ayrı satılamaz. Bu yalnızca bir etiket değildir, satış doğrulaması gerektirir.
- Temmuz 2026 listesindeki kit modelleri: Active Pro 7IX, Active 1IX, Silk C&G 7IX, Silk C&G 3IX, Silk C&G 1IX.
- Yeni PDF'deki kit içeriğini kontrol et; geçmiş kit fiyatlarını yeni döneme varsayılan olarak taşıma.

## Cihaz tipi eşleştirmesi

| PDF ifadesi | Uygulamadaki seçenek |
|---|---|
| RIC, SLIM-RIC | Kanal İçi Alıcı RIC/RIE |
| BTE, BTE-Li | Kulak arkası (BTE) |
| Hazır Kanal İçi | Kanal içi (CIC) |
| Insio IX için Kanal İçi / Kulak İçi | Kanal içi (CIC+TC) |

`Kanal içi (CIC+TC)` kullanıcının istediği tam yazımdır; ITC olarak değiştirme. Güç kullanımı ayrı alandır; cihaz tipi eşleştirmesiyle yeni bir güç kullanımı kuralı varsayma.

## Aksesuarlar

- PDF'deki aksesuarları da fiyat listesine dahil et.
- Şarj cihazları dahil bütün aksesuar satırlarını `Sarf Malzeme` olarak kaydet. Yeni `Aksesuar` tipi oluşturma.
- Temmuz 2026 örnekleri: StreamLine TV / TV Sound, StreamLine Mic, Charger (X-AX-IX), Perfectdry Lux, Multicharger (P-SP), CROS/BICROS.

## Fiyat, dönem, açıklama ve görseller

- Fiyatları yeni kaynak PDF'den aynen aktar; kit ve tek cihaz fiyatlarını karıştırma. Kaynaktaki para birimi ve KDV bilgisini koru, ayrıca KDV ekleme.
- 01.07.2026–31.12.2026 yalnızca Temmuz 2026 listesi için onaylanmış dönemdir. Sonraki PDF'de açık tarihler varsa onları kullan; bitiş tarihi belirsizse sor. Aynı dönemi tekrar aktarırken mükerrer liste oluşturma.
- Fiyat listesinde model üzerine fare geldiğinde kaynaktaki modele ait açıklama görünsün. Yeni PDF ile açıklamaları yeniden kontrol et.
- Görselleri öncelikle resmi üretici sitelerinden bul; model neslini ve varyantını doğrula. Kitlerde mümkünse cihazlar ve şarj kutusunun birlikte olduğu görseli kullan.
- Aynı kasayı kullanan performans seviyeleri ortak görsel kullanabilir. Temsili seri görsellerini kaynak kaydında belirt; yanlış modeli eşleştirme.
- Fiyat listelerinde düzenleme yetkisi yalnızca admindedir.
- Yerel aktarım sürüm numarasını artırmaz ve canlıya gönderim anlamına gelmez. Sürüm/yayın kuralları ancak kullanıcı canlıya al dediğinde uygulanır.

## Doğrulama ve mevcut durum

- Satır sayısı, marka-model bağlantısı, stok kodu tekilliği, tüm fiyatlar ve görsel dosyalarının açılması kontrol edilmeli.
- Önceki dönem fiyatlarını koru; değişiklikleri hedef dönemle sınırla.
- İlk aktarımda 50 işitme cihazı/kit ve 6 sarf malzeme, toplam 56 satır aktarıldı. Bu sayıyı gelecekteki listelere zorunlu kılma.
- Mevcut yerel dönem: fiyat listesi ID 5. ID başka ortamlarda sabit kabul edilmemeli.
- Kitlerin ayrı satışını engelleyen uygulama doğrulaması henüz tamamlanmadı; tamamlanmış gibi raporlama. Sonraki çalışmada satış akışını kontrol et.
- Ayrıntılı ilk aktarım kayıtları: `signia-import-plan.json`; model açıklamaları: `assets/data/signia-model-descriptions.json`; görsel kaynakları: `assets/uploads/stocks/signia-image-sources.json`.
