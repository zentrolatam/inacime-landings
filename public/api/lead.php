<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/bootstrap.php';

send_security_headers();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

try {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        json_out(405, ['ok' => false, 'error' => 'Método no permitido']);
    }

    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? ''));
    if (!str_contains($contentType, 'application/json')) {
        json_out(400, ['ok' => false, 'error' => 'Se espera JSON']);
    }

    if (!origin_allowed()) {
        json_out(400, ['ok' => false, 'error' => 'Origen no permitido']);
    }

    $raw = file_get_contents('php://input');
    if ($raw === false || strlen($raw) > 8192) {
        json_out(400, ['ok' => false, 'error' => 'Solicitud inválida']);
    }

    $input = json_decode($raw, true);
    if (!is_array($input)) {
        json_out(400, ['ok' => false, 'error' => 'JSON inválido']);
    }

    $storage = Storage::open();
    $limiter = new RateLimiter($storage->pdo());
    if (!$limiter->allowIp(client_ip())) {
        header('Retry-After: 600');
        json_out(429, ['ok' => false, 'error' => 'Demasiados envíos. Intenta de nuevo en unos minutos.']);
    }

    $honeypot = $input['honeypot'] ?? '';
    if (!is_string($honeypot) || trim($honeypot) !== '') {
        json_out(400, ['ok' => false, 'error' => 'Solicitud inválida']);
    }

    $result = (new Validator())->validate($input, landings());
    if (!$result['ok']) {
        json_out(400, ['ok' => false, 'error' => $result['error']]);
    }

    $lead = $result['data'];
    if (!$limiter->allowPhone($lead['telefono'])) {
        header('Retry-After: 3600');
        json_out(429, ['ok' => false, 'error' => 'Ya recibimos tu solicitud; te escribimos en breve.']);
    }

    $landing = landings()[$lead['slug']];
    $now = app_now();
    $stored = $storage->insertLead(
        $landing,
        $lead,
        client_ip(),
        client_ua(),
        $now->format('Y-m-d\TH:i:sP'),
        $now->getTimestamp()
    );

    $crmOk = (new CrmClient())->send($stored, $landing);
    $storage->setStatus((int) $stored['id'], 'crm_status', $crmOk ? 'sent' : 'pending');

    $capiOk = (new MetaCapi())->send($stored, $landing);
    $storage->setStatus((int) $stored['id'], 'capi_status', $capiOk ? 'sent' : 'pending');

    json_out(200, [
        'ok' => true,
        'folio' => $stored['folio'],
        'whatsapp_url' => whatsapp_url($landing, (string) $stored['folio'], (string) $stored['programa']),
    ]);
} catch (Throwable $exception) {
    app_log('lead_error ' . $exception::class);
    json_out(500, ['ok' => false, 'error' => 'No se pudo registrar. Intenta de nuevo.']);
}
