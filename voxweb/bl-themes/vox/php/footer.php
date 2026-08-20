</main>
<footer id="iletisim">
    <div class="container footer-grid">
        <div>
            <a class="brand footer-brand" href="<?php echo $homeUrl; ?>" aria-label="Vox İşitme ana sayfa">
                <span class="brand-mark">V<img src="<?php echo DOMAIN_THEME_IMG; ?>favicon-transparent.png" alt="">X</span>
                <span class="brand-label">İŞİTME CİHAZLARI</span>
            </a>
            <p>Daha iyi duymanız ve hayata daha yakından katılmanız için yanınızdayız.</p>
        </div>
        <div><h4>Menü</h4><ul><li><a href="<?php echo $homeUrl; ?>">Ana Sayfa</a></li><li><a href="<?php echo $aboutUrl; ?>">Hakkımızda</a></li><li><a href="<?php echo $homeUrl; ?>#galeri">Galeri</a></li><li><a href="<?php echo $homeUrl; ?>#blog">Blog</a></li><li><a href="<?php echo $homeUrl; ?>#iletisim">İletişim</a></li></ul></div>
        <div><h4>Hizmetler</h4><ul><li>İşitme Testi</li><li>Cihaz Danışmanlığı</li><li>Teknik Servis</li><li>Evde Hizmet</li></ul></div>
        <div>
            <h4>Sefaköy Şubesi <small>(Merkez)</small></h4>
            <p>Kartaltepe Mah. Süvari Cad. No:8E<br>(Torkam E-5 AVM yanık/çevresi)<br>Küçükçekmece / İstanbul</p>
            <p><a href="tel:+905011438043">+90 501 143 80 43</a></p>
            <h4>Bahçeşehir Şubesi</h4>
            <p>Bahçeşehir 1. Kısım Mah. Kemal Sunal Cad. No:21C<br>Başakşehir / İstanbul</p>
            <p><a href="tel:+905010008043">+90 501 000 80 43</a><br><a href="mailto:bilgi@voxisitme.com">bilgi@voxisitme.com</a></p>
        </div>
    </div>
    <div class="container copyright"><span>© <?php echo date('Y'); ?> Vox İşitme Merkezi. Tüm hakları saklıdır.</span><span>Gizlilik Politikası · Kullanım Koşulları</span></div>
</footer>
<?php echo Theme::js('js/site.js'); ?>
<?php Theme::plugins('siteBodyEnd'); ?>
</body>
</html>
