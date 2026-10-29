<?php

declare(strict_types=1);

/**
 * @param array<string, mixed>|null $config
 */
function render_document(string $title, string $description, string $body, ?array $config, string $cache): void
{
    $cssPath = PUBLIC_ROOT . '/assets/css/landing.css';
    $css = file_get_contents($cssPath);
    if ($css === false) {
        throw new RuntimeException('css');
    }

    $pixel = env('META_PIXEL_ID');
    if (preg_match('/^\d{5,20}$/', $pixel) !== 1) {
        $pixel = '';
    }

    send_security_headers();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: ' . $cache);

    echo '<!DOCTYPE html>';
    echo '<html lang="es"><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="theme-color" content="#008069">';
    echo '<title>' . e($title) . '</title>';
    echo '<meta name="description" content="' . e($description) . '">';
    echo '<link rel="icon" href="/assets/img/favicon.webp" type="image/webp" sizes="32x32">';
    if ($config !== null && $pixel !== '') {
        echo '<link rel="preconnect" href="https://connect.facebook.net">';
    }
    echo '<style>' . $css . '</style>';
    echo '</head><body>';
    echo $body;
    if ($config !== null) {
        $json = json_encode(
            $config,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_THROW_ON_ERROR
        );
        echo '<script type="application/json" id="landing-config">' . $json . '</script>';
        echo '<script src="/assets/js/landing.js"></script>';
    }
    if ($config !== null && $pixel !== '') {
        echo '<noscript><img height="1" width="1" alt="" src="https://www.facebook.com/tr?id='
            . e($pixel) . '&amp;ev=PageView&amp;noscript=1" style="display:none"></noscript>';
    }
    echo '</body></html>';
}

/**
 * @param array<string, mixed> $landing
 */
function render_landing(string $slug, array $landing): void
{
    $title = trim((string) ($landing['titulo'] ?? 'INACIME Tecámac'));
    $subtitle = trim((string) ($landing['subtitulo'] ?? ''));
    $selector = !empty($landing['selector']);
    $badge = trim((string) ($landing['badge'] ?? ''));
    $eyebrow = trim((string) ($landing['eyebrow'] ?? ''));
    $promo = !empty($landing['promo']);
    $promoText = trim((string) ($landing['promo_texto'] ?? ''));
    $address = trim((string) ($landing['direccion'] ?? ''));
    $bubbles = [];
    foreach ($landing['burbujas'] ?? [] as $bubble) {
        if (is_string($bubble) && $bubble !== '') {
            $bubbles[] = $bubble;
        }
    }
    $chips = [];
    foreach ($landing['chips'] ?? [] as $chip) {
        if (is_string($chip) && $chip !== '') {
            $chips[] = $chip;
        }
    }

    $options = [];
    if ($selector) {
        $named = is_array($landing['programas'] ?? null) ? $landing['programas'] : [];
        foreach ($landing['opciones'] ?? [] as $option) {
            if (!is_string($option) || $option === '') {
                continue;
            }
            $program = $option;
            if (isset($named[$option]) && is_string($named[$option]) && $named[$option] !== '') {
                $program = $named[$option];
            }
            $options[] = ['label' => $option, 'programa' => $program];
        }
    }

    $pixel = env('META_PIXEL_ID');
    if (preg_match('/^\d{5,20}$/', $pixel) !== 1) {
        $pixel = '';
    }

    $ga4 = env('GA4_MEASUREMENT_ID');
    if (preg_match('/^G-[A-Z0-9]{4,20}$/', $ga4) !== 1) {
        $ga4 = '';
    }

    $config = [
        'slug' => $slug,
        'pixelId' => $pixel,
        'ga4Id' => $ga4,
        'plantel' => (string) ($landing['plantel'] ?? ''),
        'selector' => $selector,
        'opciones' => $options,
        'programa' => $selector ? '' : (string) ($landing['programa'] ?? ''),
        'mensaje' => (string) ($landing['mensaje'] ?? ''),
        'whatsapp' => preg_replace('/\D/', '', (string) ($landing['whatsapp'] ?? '')) ?? '',
    ];

    ob_start();
    ?>
<main class="page">
  <section class="hero">
    <?php if ($badge !== ''): ?>
      <p class="badge"><?= e($badge) ?></p>
    <?php endif; ?>
    <?php if ($eyebrow !== ''): ?>
      <p class="eyebrow"><?= e($eyebrow) ?></p>
    <?php endif; ?>
    <h1 class="title"><?= e($title) ?></h1>
    <?php if ($subtitle !== ''): ?>
      <p class="lead"><?= e($subtitle) ?></p>
    <?php endif; ?>
    <?php if ($promo && $promoText !== ''): ?>
      <p class="promo"><?= e($promoText) ?></p>
    <?php endif; ?>
    <?php if ($chips !== []): ?>
      <ul class="chips">
        <?php foreach ($chips as $chip): ?>
          <li><?= e($chip) ?></li>
        <?php endforeach; ?>
      </ul>
    <?php endif; ?>
    <?php if ($address !== ''): ?>
      <p class="address"><?= e($address) ?></p>
    <?php endif; ?>
  </section>

  <section class="chat" aria-label="Conversación con admisiones">
    <header class="chat-head">
      <img class="avatar" src="/assets/img/marca.webp" width="42" height="42" alt="">
      <div>
        <div class="chat-name">INACIME <?= e((string) ($landing['plantel'] ?? 'Tecámac')) ?></div>
        <div class="chat-status">Admisiones · respondemos en minutos</div>
      </div>
    </header>
    <div class="thread">
      <?php if ($badge !== ''): ?>
        <p class="badge badge-chat"><?= e($badge) ?></p>
      <?php endif; ?>
      <?php if ($promo && $promoText !== ''): ?>
        <p class="promo promo-chat"><?= e($promoText) ?></p>
      <?php endif; ?>
      <?php foreach ($bubbles as $bubble): ?>
        <div class="bubble in">
          <p><?= e($bubble) ?></p>
          <span class="time" aria-hidden="true">9:41</span>
        </div>
      <?php endforeach; ?>

      <form id="lead-form" method="post" action="/api/lead.php" novalidate>
        <div class="sheet">
          <?php if ($selector): ?>
            <div class="field">
              <div class="field-label" id="carrera-label">Carrera</div>
              <div class="careers" id="careers" role="radiogroup" aria-labelledby="carrera-label">
                <?php foreach ($options as $option): ?>
                  <button type="button" class="career" role="radio" aria-checked="false" data-programa="<?= e($option['programa']) ?>">
                    <span class="dot" aria-hidden="true"></span>
                    <span><?= e($option['label']) ?></span>
                  </button>
                <?php endforeach; ?>
              </div>
              <span class="error" id="carrera-error" role="alert" hidden>Elige una carrera.</span>
            </div>
          <?php endif; ?>

          <label class="field" for="nombre">
            <span class="field-label">Nombre</span>
            <input class="control" id="nombre" name="nombre" type="text" autocomplete="given-name" autocapitalize="words" maxlength="60" placeholder="Tu nombre" aria-describedby="nombre-error" required>
            <span class="error" id="nombre-error" role="alert" hidden>Escribe tu nombre (mínimo 2 letras).</span>
          </label>

          <label class="field" for="telefono">
            <span class="field-label">WhatsApp</span>
            <div class="phone">
              <span class="prefix">+52</span>
              <input id="telefono" name="telefono" type="tel" inputmode="numeric" autocomplete="tel-national" maxlength="10" placeholder="10 dígitos" aria-describedby="telefono-error" required>
            </div>
            <span class="error" id="telefono-error" role="alert" hidden>Ingresa tu WhatsApp a 10 dígitos.</span>
          </label>

          <div class="hp" aria-hidden="true">
            <label>Empresa <input id="empresa" name="empresa" type="text" tabindex="-1" autocomplete="off" value=""></label>
          </div>

          <button class="btn" id="submit" type="submit" disabled>
            <span class="spinner" aria-hidden="true"></span>
            <span id="submit-label">Continuar a WhatsApp</span>
          </button>
          <noscript><p class="noscript">Activa JavaScript para continuar a WhatsApp.</p></noscript>
          <p class="legal-note">Se abrirá WhatsApp con tu mensaje y tu folio listos; solo toca enviar. Si no alcanzas, te escribimos nosotros. Al continuar aceptas el <a href="/aviso-de-privacidad">aviso de privacidad</a>.</p>
        </div>
      </form>

      <div class="bubble out" id="preview" hidden>
        <p id="preview-text"></p>
        <span class="time">Listo para enviar</span>
      </div>
      <a class="fallback" id="wa-fallback" hidden rel="noopener noreferrer" href="#">¿No se abrió WhatsApp? Toca aquí</a>

      <?php if ($chips !== [] || $address !== ''): ?>
        <div class="thread-foot">
          <?php if ($chips !== []): ?>
            <ul class="chips">
              <?php foreach ($chips as $chip): ?>
                <li><?= e($chip) ?></li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
          <?php if ($address !== ''): ?>
            <p class="address"><?= e($address) ?></p>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </section>
</main>
    <?php
    $html = ob_get_clean();
    if ($html === false) {
        throw new RuntimeException('template');
    }

    render_document($title . ' · INACIME ' . (string) ($landing['plantel'] ?? 'Tecámac'), $subtitle !== '' ? $subtitle : $title, $html, $config, 'public, max-age=300');
}

function render_not_found(): void
{
    http_response_code(404);
    $body = <<<'HTML'
<main class="simple">
  <header class="bar">
    <img class="avatar" src="/assets/img/marca.webp" width="42" height="42" alt="">
    <div>
      <p class="chat-name">INACIME Tecámac</p>
      <p class="chat-status">Admisiones</p>
    </div>
  </header>
  <article class="simple-body">
    <h1>No encontramos esta página</h1>
    <p>El enlace no corresponde a una licenciatura de INACIME. Ábrelo de nuevo desde el anuncio o pídele el acceso a tu asesor.</p>
  </article>
</main>
HTML;
    render_document('Página no encontrada · INACIME', 'Esta dirección no corresponde a una landing de INACIME.', $body, null, 'no-store');
}

function render_privacy(): void
{
    $body = <<<'HTML'
<main class="simple">
  <header class="bar">
    <img class="avatar" src="/assets/img/marca.webp" width="42" height="42" alt="">
    <div>
      <p class="chat-name">INACIME Tecámac</p>
      <p class="chat-status">Aviso de privacidad</p>
    </div>
  </header>
  <article class="simple-body">
    <h1>Aviso de privacidad</h1>
    <p>[TEXTO LEGAL DE INACIME]</p>
    <p>Para volver al formulario usa el botón atrás de tu navegador.</p>
  </article>
</main>
HTML;
    render_document('Aviso de privacidad · INACIME Tecámac', 'Aviso de privacidad de INACIME.', $body, null, 'public, max-age=3600');
}
