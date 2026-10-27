<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/template.php';

try {
    $path = request_path();
    $slug = trim($path, '/');

    if ($slug === 'aviso-de-privacidad') {
        render_privacy();
        exit;
    }

    if ($slug === '' || str_contains($slug, '/')) {
        render_not_found();
        exit;
    }

    $all = landings();
    if (!isset($all[$slug])) {
        render_not_found();
        exit;
    }

    render_landing($slug, $all[$slug]);
} catch (Throwable $exception) {
    app_log('page_error ' . $exception::class);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No se pudo mostrar la página.\n";
}
