<?php

declare(strict_types=1);

final class MetaCapi
{
    /**
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $landing
     */
    public function send(array $lead, array $landing): bool
    {
        $pixelId = env('META_PIXEL_ID');
        $token = env('META_CAPI_TOKEN');
        if (!preg_match('/^\d{5,20}$/', $pixelId) || $token === '') {
            app_log('capi_skip folio=' . (string) $lead['folio'] . ' reason=unconfigured');
            return false;
        }

        $version = env('META_API_VERSION', 'v25.0');
        if (preg_match('/^v\d+\.\d+$/', $version) !== 1) {
            $version = 'v25.0';
        }

        $url = 'https://graph.facebook.com/' . $version . '/' . $pixelId
            . '/events?access_token=' . rawurlencode($token);

        $result = Http::postJson($url, $this->payload($lead, $landing), [], 3);
        if (!$result['ok']) {
            app_log('capi_fail folio=' . (string) $lead['folio'] . ' status=' . $result['status']);
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $landing
     * @return array<string, mixed>
     */
    public function payload(array $lead, array $landing): array
    {
        $phone = preg_replace('/\D/', '', (string) $lead['telefono']) ?? '';
        $userData = [
            'ph' => hash('sha256', $phone),
            'fn' => hash('sha256', $this->normalizeName((string) $lead['nombre'])),
            'external_id' => hash('sha256', (string) $lead['folio']),
        ];

        $fbc = (string) ($lead['fbc'] ?? '');
        $fbp = (string) ($lead['fbp'] ?? '');
        if ($fbc !== '') {
            $userData['fbc'] = $fbc;
        }
        if ($fbp !== '') {
            $userData['fbp'] = $fbp;
        }

        $ip = (string) ($lead['ip'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            $userData['client_ip_address'] = $ip;
        }

        $ua = (string) ($lead['user_agent'] ?? '');
        if ($ua !== '') {
            $userData['client_user_agent'] = $ua;
        }

        $eventTime = (int) ($lead['event_time'] ?? time());
        $event = [
            'event_name' => 'Lead',
            'event_time' => $eventTime,
            'event_id' => (string) $lead['event_id'],
            'action_source' => 'website',
            'user_data' => $userData,
            'custom_data' => [
                'content_name' => (string) $lead['programa'],
                'content_category' => (string) ($landing['plantel'] ?? 'Tecámac'),
            ],
        ];
        $pageUrl = (string) ($lead['page_url'] ?? '');
        if (str_starts_with($pageUrl, 'http://') || str_starts_with($pageUrl, 'https://')) {
            $event['event_source_url'] = $pageUrl;
        }

        $payload = ['data' => [$event]];
        $testCode = env('META_TEST_EVENT_CODE');
        if ($testCode !== '') {
            $payload['test_event_code'] = $testCode;
        }

        return $payload;
    }

    public function normalizeName(string $name): string
    {
        $name = mb_strtolower(trim($name), 'UTF-8');
        $name = strtr($name, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n', 'ç' => 'c',
        ]);
        $name = preg_replace('/[^\p{L}\s]/u', '', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }
}
