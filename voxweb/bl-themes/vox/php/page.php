<?php
$isAbout = method_exists($page, 'slug') && $page->slug() === 'hakkimizda';
$pageHeading = $isAbout ? 'Daha İyi Duy, Daha İyi Yaşa!' : $page->title();
?>
<section class="page-hero"><div class="container"><span class="kicker kicker-light"><?php echo $isAbout ? 'Vox İşitme hakkında' : 'Vox İşitme'; ?></span><h1><?php echo $pageHeading; ?></h1><?php if ($page->description()): ?><p><?php echo $page->description(); ?></p><?php endif; ?></div></section>

<?php if ($isAbout): ?>
<section class="section container about-story">
    <div class="page-copy">
        <span class="kicker">Size özel çözümler</span>
        <h2>İşitme sağlığınızı önemsiyoruz.</h2>
        <div class="cms-content"><?php echo $page->content(); ?></div>
    </div>
    <div class="about-photo"><img src="<?php echo DOMAIN_THEME_IMG; ?>hakkimizda-vox-danismanlik.png" alt="Vox İşitme uzmanının danışanına işitme cihazı hakkında bilgi vermesi"><span><strong>Kişiye özel</strong> işitme çözümleri</span></div>
</section>
<section class="why"><div class="section container"><div class="section-heading"><span class="kicker">Vox yaklaşımı</span><h2>İşitme yolculuğunuzun her adımında yanınızdayız.</h2></div><div class="why-grid"><article><span>01</span><h3>Doğru cihaz seçimi</h3><p>İşitme kaybının türüne ve derecesine göre en uygun işitme cihazını seçmenize yardımcı oluruz.</p></article><article><span>02</span><h3>Güncel teknolojiler</h3><p>Yapay zeka destekli, Bluetooth bağlantılı ve şarj edilebilir çözümlerle işitme deneyiminizi geliştiririz.</p></article><article><span>03</span><h3>Kesintisiz destek</h3><p>Ücretsiz işitme testi, yerinde teknik servis, eve hizmet ve cihaz danışmanlığıyla yanınızdayız.</p></article></div></div></section>
<section class="section container about-story about-story-reverse">
    <div class="about-photo"><img src="<?php echo DOMAIN_THEME_IMG; ?>hakkimizda-vox-danismanlik.png" alt="İşitme cihazı danışmanlığı"></div>
    <div class="page-copy"><span class="kicker">Güvenilir hizmet</span><h2>Seslerin dünyasına yeniden merhaba deyin.</h2><p>Dünya çapında tanınmış markalarla çalışarak en yeni işitme cihazı teknolojilerini kullanıcılarımızla buluşturuyoruz. İşitme cihazlarının bakım ve onarım süreçlerinde de hızlı ve güvenilir çözümler sunarak, cihazlarınızın uzun ömürlü ve en yüksek performansla çalışmasını sağlıyoruz.</p><p>İşitme kaybı yaşayan bireylerin sosyal hayatta daha aktif olmalarını sağlamak, bizim en büyük önceliğimizdir. İşitme kaybını bir engel olmaktan çıkarıp seslerin dünyasına yeniden merhaba demeniz için buradayız.</p></div>
</section>
<?php else: ?>
<article class="section container generic-page"><div class="cms-content"><?php echo $page->content(); ?></div></article>
<?php endif; ?>

<section class="cta"><div class="container cta-box"><div><h2>İşitme sağlığınız için ilk adımı bugün atın.</h2><p>Ücretsiz işitme testi ve uzman görüşmesi için size uygun zamanı seçin.</p></div><a class="button" href="<?php echo $appointmentUrl; ?>">Online Randevu Al <b class="arrow">→</b></a></div></section>
