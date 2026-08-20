<?php
$viewSlug = ($WHERE_AM_I === 'page' && isset($page) && method_exists($page, 'slug')) ? $page->slug() : '';
$bodyClass = $WHERE_AM_I === 'home' ? 'is-home' : ($viewSlug === 'randevu' ? 'is-appointment' : 'is-inner');
?>
<!doctype html>
<html lang="<?php echo Theme::lang(); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="generator" content="Bludit <?php echo BLUDIT_VERSION; ?>">
    <meta name="theme-color" content="#064b2b">
    <?php echo Theme::metaTagTitle(); ?>
    <?php echo Theme::metaTagDescription(); ?>
    <?php echo Theme::favicon('img/favicon-transparent.png'); ?>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Manrope:wght@600;700;800&display=swap" rel="stylesheet">
    <?php echo Theme::css('css/style.css'); ?>
    <?php Theme::plugins('siteHead'); ?>
</head>
<body class="<?php echo $bodyClass; ?>">
<?php Theme::plugins('siteBodyBegin'); ?>
<a class="skip-link" href="#main-content">İçeriğe geç</a>
<div class="topbar">
    <div class="container topbar-inner">
        <div class="topbar-group"><span>◷ Pazartesi–Cumartesi: 09:00–18:00</span><a href="tel:+905011438043">☎ Sefaköy: +90 501 143 80 43</a></div>
        <div class="topbar-group"><a href="mailto:bilgi@voxisitme.com">✉ bilgi@voxisitme.com</a><span>TR / EN</span></div>
    </div>
</div>
<header class="site-header">
    <div class="container nav">
        <a class="brand" href="<?php echo $homeUrl; ?>" aria-label="Vox İşitme ana sayfa">
            <span class="brand-mark">V<img src="<?php echo DOMAIN_THEME_IMG; ?>favicon-transparent.png" alt="">X</span>
            <span class="brand-label">İŞİTME CİHAZLARI</span>
        </a>
        <button class="nav-toggle" type="button" aria-expanded="false" aria-controls="main-navigation"><span></span><span></span><span></span><b class="sr-only">Menüyü aç</b></button>
        <nav id="main-navigation" class="main-nav" aria-label="Ana menü">
            <a href="<?php echo $homeUrl; ?>">Ana Sayfa</a>
            <a href="<?php echo $aboutUrl; ?>">Hakkımızda</a>
            <a href="<?php echo $homeUrl; ?>#galeri">Galeri</a>
            <a href="<?php echo $homeUrl; ?>#blog">Blog</a>
            <a href="<?php echo $homeUrl; ?>#iletisim">İletişim</a>
        </nav>
        <a href="<?php echo $appointmentUrl; ?>" class="button nav-appointment">Randevu Al <b class="arrow">→</b></a>
    </div>
</header>
<main id="main-content">
