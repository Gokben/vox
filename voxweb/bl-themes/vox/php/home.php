<?php
$heroTitle = trim((string)$site->slogan()) ?: 'Sevdiklerinizin kahkasını, doğanın seslerini ve hayatın tüm güzelliklerini yeniden duymaya hazır olun.';
$heroDescription = trim((string)$site->description()) ?: 'İşitme testinden size en uygun cihazın seçimine kadar, uzman ekibimiz her adımda güvenilir ve kişisel çözümler sunar.';
?>
<section class="hero" style="--hero-image:url('<?php echo DOMAIN_THEME_IMG; ?>hero-vox-hearing-v14.png')">
    <div class="container hero-content">
        <div class="eyebrow">Seslerin Getirdiği Mutluluğu Yeniden Keşfedin</div>
        <h1 class="hero-slogan"><?php echo htmlspecialchars($heroTitle, ENT_QUOTES, 'UTF-8'); ?></h1>
        <p><?php echo htmlspecialchars($heroDescription, ENT_QUOTES, 'UTF-8'); ?></p>
        <div class="hero-actions"><a class="button" href="#hizmetler">Hizmetlerimizi Keşfedin <b class="arrow">→</b></a></div>
    </div>
    <div class="container hero-stats"><div><strong>25+</strong><span>Yıllık deneyim</span></div><div><strong>10K+</strong><span>Mutlu danışan</span></div></div>
</section>

<section id="hizmetler" class="section container">
    <div class="section-heading"><span class="kicker">İşitme çözümlerimiz</span><h2>İşitme sağlığınız için <em>kapsamlı ve güvenilir</em> hizmetler.</h2></div>
    <div class="service-grid">
        <article class="service-card"><span class="service-icon">⌁</span><div><span class="card-number">01</span><h3>Ücretsiz İşitme Testi</h3><p>İşitme seviyenizi uzmanlarımızla hızlı ve güvenilir biçimde değerlendirin.</p></div></article>
        <article class="service-card"><span class="service-icon">✦</span><div><span class="card-number">02</span><h3>Cihaz Danışmanlığı</h3><p>Yaşam tarzınıza ve ihtiyaçlarınıza uygun cihazı birlikte seçelim.</p></div></article>
        <article class="service-card"><span class="service-icon">⚙</span><div><span class="card-number">03</span><h3>Teknik Servis</h3><p>Bakım, ayar ve onarım ihtiyaçlarınız için hızlı teknik destek.</p></div></article>
        <article class="service-card"><span class="service-icon">⌂</span><div><span class="card-number">04</span><h3>Eve Hizmet Avantajı</h3><p>Hareket kısıtlılığı yaşayan danışanlarımız için evde profesyonel destek.</p></div></article>
    </div>
</section>

<section class="impact"><div class="container impact-inner"><div><span class="kicker kicker-light">Vox farkı</span><h2>Her görüşmede, sizi ve ihtiyaçlarınızı gerçekten dinliyoruz.</h2></div><div class="metrics"><div><strong>%98</strong><span>Danışan memnuniyeti</span></div><div><strong>10K+</strong><span>Mutlu müşteri</span></div><div><strong>25+</strong><span>Yıllık uzmanlık</span></div><div><strong>5★</strong><span>Özenli hizmet yaklaşımı</span></div></div></div></section>

<section id="hakkimizda" class="section container about-preview">
    <div class="about-photo"><img src="<?php echo DOMAIN_THEME_IMG; ?>hakkimizda-vox-danismanlik.png" alt="Vox İşitme uzmanı danışanına işitme cihazını anlatıyor" loading="lazy"><span><strong>25+</strong> yıllık deneyim</span></div>
    <div><span class="kicker">Vox İşitme hakkında</span><h2>İyi duymak, hayata daha yakın olmaktır.</h2><p>Vox İşitme Merkezi olarak işitme kaybının hayatın sosyal ve duygusal yönlerini nasıl etkilediğini biliyoruz. Bu nedenle, teknolojiyi samimi ve nitelikli danışmanlıkla bir araya getiriyoruz.</p><div class="checks"><span>Bireye özel çözümler</span><span>Ücretsiz işitme testi</span><span>Güvenilir teknik servis</span><span>Evde hizmet imkânı</span></div><a class="button" href="<?php echo $aboutUrl; ?>">Vox’u Tanıyın <b class="arrow">→</b></a></div>
</section>

<section id="neden-vox" class="why"><div class="section container"><div class="section-heading"><span class="kicker">Neden Vox İşitme?</span><h2>İşitme yolculuğunuzda güvenle ilerlemeniz için buradayız.</h2></div><div class="why-grid"><article><span>01</span><h3>Uzman ve anlayışlı ekip</h3><p>İhtiyacınızı dinler, doğru çözüm için sizi acele etmeden yönlendiririz.</p></article><article><span>02</span><h3>Güncel işitme teknolojileri</h3><p>Farklı yaşam tarzlarına uyum sağlayan modern cihaz seçenekleri sunarız.</p></article><article><span>03</span><h3>Rahat ve erişilebilir destek</h3><p>Şubemizde veya evinizde, ihtiyacınız olan teknik ve danışmanlık desteği yanınızda.</p></article></div></div></section>

<span id="galeri"></span><span id="blog"></span>
<section id="randevu" class="cta"><div class="container cta-box"><div><h2>İşitme sağlığınız için ilk adımı bugün atın.</h2><p>Ücretsiz işitme testi ve uzman görüşmesi için size uygun zamanı seçin.</p></div><a class="button" href="<?php echo $appointmentUrl; ?>">Online Randevu Al <b class="arrow">→</b></a></div></section>
