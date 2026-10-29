<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    echo "Not found\n";
    exit;
}

require __DIR__ . '/bootstrap.php';

$lockPath = STORAGE_ROOT . '/retry.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "retry already running\n");
    exit(0);
}

$storage = Storage::open();
$crm = new CrmClient();
$capi = new MetaCapi();
$landings = landings();
$sent = 0;
$failed = 0;

foreach ($storage->pending() as $lead) {
    $slug = (string) ($lead['slug'] ?? '');
    $folio = (string) ($lead['folio'] ?? '');
    if (!isset($landings[$slug])) {
        app_log('retry_skip folio=' . $folio . ' reason=unknown_slug');
        $failed++;
        continue;
    }

    $landing = $landings[$slug];
    $id = (int) $lead['id'];

    if (($lead['crm_status'] ?? '') === 'pending') {
        // En el reintento nadie espera en la pantalla: se da más margen al CRM.
        $ok = $crm->send($lead, $landing, 15);
        $storage->setStatus($id, 'crm_status', $ok ? 'sent' : 'pending');
        $ok ? $sent++ : $failed++;
    }

    if (($lead['capi_status'] ?? '') === 'pending') {
        $ok = $capi->send($lead, $landing);
        $storage->setStatus($id, 'capi_status', $ok ? 'sent' : 'pending');
        $ok ? $sent++ : $failed++;
    }
}

fwrite(STDOUT, 'retry done sent=' . $sent . ' pending_or_skipped=' . $failed . "\n");
flock($lock, LOCK_UN);
fclose($lock);
exit(0);
