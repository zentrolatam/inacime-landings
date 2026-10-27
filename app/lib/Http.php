<?php

declare(strict_types=1);

final class Http
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, string> $headers
     * @return array{ok: bool, status: int}
     */
    public static function postJson(string $url, array $payload, array $headers, int $timeoutSeconds): array
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);
        if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
            return ['ok' => false, 'status' => 0];
        }

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return ['ok' => false, 'status' => 0];
        }

        $headerLines = ['Content-Type: application/json', 'Accept: application/json'];
        foreach ($headers as $name => $value) {
            $headerLines[] = $name . ': ' . $value;
        }

        if (function_exists('curl_init')) {
            return self::postCurl($url, $body, $headerLines, $timeoutSeconds);
        }

        return self::postStream($url, $body, $headerLines, $timeoutSeconds);
    }

    /**
     * @param list<string> $headerLines
     * @return array{ok: bool, status: int}
     */
    private static function postCurl(string $url, string $body, array $headerLines, int $timeoutSeconds): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'status' => 0];
        }

        $timeoutMs = max(1, $timeoutSeconds) * 1000;
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT_MS => $timeoutMs,
            CURLOPT_CONNECTTIMEOUT_MS => $timeoutMs,
            CURLOPT_NOSIGNAL => 1,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $failed = $response === false;
        curl_close($ch);

        if ($failed) {
            return ['ok' => false, 'status' => 0];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status];
    }

    /**
     * @param list<string> $headerLines
     * @return array{ok: bool, status: int}
     */
    private static function postStream(string $url, string $body, array $headerLines, int $timeoutSeconds): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headerLines),
                'content' => $body,
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches) === 1) {
                    $status = (int) $matches[1];
                }
            }
        }

        if ($response === false || $status === 0) {
            return ['ok' => false, 'status' => 0];
        }

        return ['ok' => $status >= 200 && $status < 300, 'status' => $status];
    }
}
