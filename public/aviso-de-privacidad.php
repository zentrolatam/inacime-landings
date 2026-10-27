<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';
require dirname(__DIR__) . '/app/template.php';

try {
    render_privacy();
} catch (Throwable $exception) {
    app_log('page_error ' . $exception::class);
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "No se pudo mostrar la página.\n";
}
