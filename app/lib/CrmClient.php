<?php

declare(strict_types=1);

final class CrmClient
{
    /**
     * @param array<string, mixed> $lead
     * @param array<string, mixed> $landing
     * @param int $timeoutSeconds 3 s en el registro (la persona espera); el reintento usa más.
     */
    public function send(array $lead, array $landing, int $timeoutSeconds = 3): bool
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

        // Formato de la API pública de Zentro CRM: POST /api/v1/contacts
        // (phone y name; tags por nombre, el CRM los crea si no existen).
        // El teléfono ya viene normalizado como 52 + 10 dígitos.
        $payload = [
            'phone' => (string) $lead['telefono'],
            'name' => (string) $lead['nombre'],
            'tags' => [
                'Landing Meta',
                (string) ($landing['plantel'] ?? 'Tecámac'),
                (string) $lead['programa'],
            ],
            'lead_source' => 'meta_ads',
        ];

        $result = Http::postJson($url, $payload, $headers, $timeoutSeconds);
        if (!$result['ok']) {
            app_log('crm_fail folio=' . (string) $lead['folio'] . ' status=' . $result['status']);
            return false;
        }

        return true;
    }
}
