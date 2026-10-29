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

        $utms = json_decode((string) ($lead['utms'] ?? ''), true);
        if (!is_array($utms)) {
            $utms = [];
        }
        $extra = static function (string $key) use ($lead, $utms): string {
            return (string) ($lead[$key] ?? $utms[$key] ?? '');
        };

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
            // Datos de atribución. El CRM los guarda en el contacto/negocio y usa
            // ga_client_id + ga_session_id para mandar etapas a GA4 (Measurement Protocol).
            'folio' => (string) $lead['folio'],
            'landing' => (string) $lead['slug'],
            'programa' => (string) $lead['programa'],
            'plantel' => (string) ($landing['plantel'] ?? ''),
            'utm_source' => $extra('utm_source'),
            'utm_campaign' => $extra('utm_campaign'),
            'utm_content' => $extra('utm_content'),
            'ga_client_id' => $extra('ga_client_id'),
            'ga_session_id' => $extra('ga_session_id'),
        ];

        $result = Http::postJson($url, $payload, $headers, $timeoutSeconds);
        if (!$result['ok']) {
            app_log('crm_fail folio=' . (string) $lead['folio'] . ' status=' . $result['status']);
            return false;
        }

        return true;
    }
}
