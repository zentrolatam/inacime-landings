<?php

declare(strict_types=1);

final class Validator
{
    /**
     * @param array<string, mixed> $input
     * @param array<string, array<string, mixed>> $landings
     * @return array{ok: true, data: array<string, string>}|array{ok: false, error: string}
     */
    public function validate(array $input, array $landings): array
    {
        $slug = $input['slug'] ?? '';
        if (!is_string($slug) || !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) || !isset($landings[$slug])) {
            return $this->fail('Landing no encontrada.');
        }

        $landing = $landings[$slug];
        $name = $this->cleanName($input['nombre'] ?? '');
        if ($name === null) {
            return $this->fail('Escribe tu nombre (mínimo 2 letras).');
        }

        $phone = $this->normalizePhone($input['telefono'] ?? '');
        if ($phone === null) {
            return $this->fail('Ingresa tu WhatsApp a 10 dígitos.');
        }

        $program = $this->resolveProgram($input['programa'] ?? '', $landing);
        if ($program === null) {
            return $this->fail(!empty($landing['selector']) ? 'Elige una carrera.' : 'Programa no permitido.');
        }

        $eventId = $input['event_id'] ?? '';
        if (!is_string($eventId) || preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $eventId) !== 1) {
            return $this->fail('Solicitud inválida.');
        }

        $utmSource = $this->cleanUtm($input['utm_source'] ?? '');
        $utmCampaign = $this->cleanUtm($input['utm_campaign'] ?? '');
        $utmContent = $this->cleanUtm($input['utm_content'] ?? '');
        $fbc = $this->cleanFb($input['fbc'] ?? '', $_COOKIE['_fbc'] ?? '');
        $fbp = $this->cleanFb($input['fbp'] ?? '', $_COOKIE['_fbp'] ?? '');
        // Identificadores de Google Analytics 4 para atar el lead a la sesión (opcionales).
        $gaClientId = $this->cleanGa($input['ga_client_id'] ?? '', '/^\d{1,12}\.\d{9,11}$/');
        $gaSessionId = $this->cleanGa($input['ga_session_id'] ?? '', '/^\d{9,11}$/');

        return [
            'ok' => true,
            'data' => [
                'slug' => $slug,
                'nombre' => $name,
                'telefono' => $phone,
                'programa' => $program,
                'utm_source' => $utmSource,
                'utm_campaign' => $utmCampaign,
                'utm_content' => $utmContent,
                'fbc' => $fbc,
                'fbp' => $fbp,
                'ad_id' => $utmContent,
                'ga_client_id' => $gaClientId,
                'ga_session_id' => $gaSessionId,
                'event_id' => strtolower($eventId),
                'page_url' => $this->cleanPageUrl($input['page_url'] ?? '', $slug),
            ],
        ];
    }

    /**
     * @return array{ok: false, error: string}
     */
    private function fail(string $error): array
    {
        return ['ok' => false, 'error' => $error];
    }

    private function cleanName(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $name = trim(preg_replace('/[\x00-\x1F\x7F]/u', '', $value) ?? '');
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;
        if (mb_strlen($name) > 60) {
            $name = trim(mb_substr($name, 0, 60));
        }

        $letters = preg_match_all('/\p{L}/u', $name);
        if ($letters === false || $letters < 2) {
            return null;
        }

        return $name;
    }

    private function normalizePhone(mixed $value): ?string
    {
        if (!is_string($value) && !is_int($value)) {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $value) ?? '';
        if (strlen($digits) === 12 && str_starts_with($digits, '52')) {
            $digits = substr($digits, 2);
        }
        if (preg_match('/^\d{10}$/', $digits) !== 1) {
            return null;
        }

        return '52' . $digits;
    }

    /**
     * @param array<string, mixed> $landing
     */
    private function resolveProgram(mixed $value, array $landing): ?string
    {
        $sent = is_string($value) ? trim($value) : '';
        if (mb_strlen($sent) > 120) {
            return null;
        }

        if (empty($landing['selector'])) {
            $expected = trim((string) ($landing['programa'] ?? ''));
            if ($expected === '') {
                return null;
            }
            if ($sent !== '' && $sent !== $expected) {
                return null;
            }

            return $expected;
        }

        $map = $this->programMap($landing);
        if ($sent === '' || !isset($map[$sent])) {
            return null;
        }

        return $map[$sent];
    }

    /**
     * @param array<string, mixed> $landing
     * @return array<string, string>
     */
    private function programMap(array $landing): array
    {
        $map = [];
        $options = $landing['opciones'] ?? [];
        $named = $landing['programas'] ?? [];
        if (!is_array($options)) {
            return $map;
        }

        foreach ($options as $option) {
            if (!is_string($option) || trim($option) === '') {
                continue;
            }
            $canonical = $option;
            if (is_array($named) && isset($named[$option]) && is_string($named[$option]) && trim($named[$option]) !== '') {
                $canonical = trim($named[$option]);
            }
            $map[$option] = $canonical;
            $map[$canonical] = $canonical;
        }

        return $map;
    }

    private function cleanGa(mixed $value, string $pattern): string
    {
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);

        return preg_match($pattern, $value) === 1 ? $value : '';
    }

    private function cleanUtm(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        $value = trim(preg_replace('/[\x00-\x1F\x7F]/', '', $value) ?? '');

        return mb_substr($value, 0, 200);
    }

    private function cleanFb(mixed $primary, mixed $fallback): string
    {
        foreach ([$primary, $fallback] as $candidate) {
            if (!is_string($candidate)) {
                continue;
            }
            $candidate = trim($candidate);
            if ($candidate === '' || strlen($candidate) > 512) {
                continue;
            }
            if (preg_match('/^fb\.\d+\.\d+\.[A-Za-z0-9_\-\.]+$/', $candidate) === 1) {
                return $candidate;
            }
        }

        return '';
    }

    private function cleanPageUrl(mixed $value, string $slug): string
    {
        $origins = allowed_origins();
        $fallback = ($origins[0] ?? '') . '/' . $slug;
        if (!is_string($value)) {
            return $fallback;
        }

        $value = trim($value);
        $parts = parse_url($value);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $fallback;
        }
        if (!in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)) {
            return $fallback;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'];
        if (!empty($parts['port'])) {
            $origin .= ':' . $parts['port'];
        }

        $allowed = false;
        foreach ($origins as $item) {
            if (strcasecmp($item, $origin) === 0) {
                $allowed = true;
                break;
            }
        }
        if (!$allowed) {
            return $fallback;
        }

        $url = $origin . ($parts['path'] ?? '/');
        if (!empty($parts['query']) && is_string($parts['query'])) {
            $url .= '?' . $parts['query'];
        }

        return mb_substr($url, 0, 2000);
    }
}
