<?php

declare(strict_types=1);

final class CrmClient
{
    /**
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $landing
     */
    public function send(array $lead, array $landing): bool
    {
        $url = env('CRM_WEBHOOK_URL');
        if ($url === '') {
            app_log('crm_skip folio=' . (string) $lead['folio'] . ' reason=unconfigured');
            return false;
        }

        $headers = [];
        $token = env('CRM_WEBHOOK_TOKEN');
        if ($token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }

        $payload = [
            'folio' => (string) $lead['folio'],
            'nombre' => (string) $lead['nombre'],
            'telefono' => (string) $lead['telefono'],
            'programa' => (string) $lead['programa'],
            'plantel' => (string) ($landing['plantel'] ?? 'Tecámac'),
            'origen' => 'landing',
            'slug' => (string) $lead['slug'],
            'utm_source' => (string) ($lead['utm_source'] ?? ''),
            'utm_campaign' => (string) ($lead['utm_campaign'] ?? ''),
            'utm_content' => (string) ($lead['utm_content'] ?? ''),
            'fbc' => (string) ($lead['fbc'] ?? ''),
            'created_at' => (string) $lead['created_at'],
        ];

        $result = Http::postJson($url, $payload, $headers, 3);
        if (!$result['ok']) {
            app_log('crm_fail folio=' . (string) $lead['folio'] . ' status=' . $result['status']);
            return false;
        }

        return true;
    }
}
