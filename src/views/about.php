<?php
/** @var string $app_version */
/** @var string $api_version */
?>
<section class="card card-narrow about-page" data-testid="about-page">
    <div class="about-hero">
        <div class="about-mark" aria-hidden="true">◈</div>
        <h1 class="title">ShipInventory</h1>
        <p class="subtitle">Servicio de gestión de inventario para barcos</p>
    </div>

    <h2 class="section-title">Aplicación</h2>
    <div class="userinfo">
        <div class="userinfo-row">
            <span class="label">Cliente</span>
            <span class="value">Web PHP</span>
        </div>
        <div class="userinfo-row">
            <span class="label">Versión</span>
            <span class="value"><?= e($app_version) ?></span>
        </div>
        <div class="userinfo-row">
            <span class="label">API</span>
            <span class="value"><?= e($api_version) ?></span>
        </div>
    </div>

    <h2 class="section-title">Información</h2>
    <p class="about-copy">
        ShipInventory permite gestionar inventarios de repuestos de buques con acceso por roles,
        fotografías, auditoría, copias de seguridad y clientes con funcionamiento offline.
    </p>

    <h2 class="section-title">Licencia</h2>
    <div class="about-license">
        <p><strong>MIT License</strong></p>
        <p>Software libre distribuido bajo la licencia MIT.</p>
        <p>© 2026 José Isidro González</p>
        <p class="hint">Las bibliotecas y dependencias de terceros conservan sus respectivas licencias.</p>
    </div>
</section>
