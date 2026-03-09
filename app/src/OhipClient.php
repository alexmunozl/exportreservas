<?php
declare(strict_types=1);

require_once __DIR__ . '/Logger.php';

final class OhipClient
{
    public function __construct(
        private string $baseUrl,
        private string $tokenPath,
        private string $clientId,
        private string $clientSecret,
        private string $appKey,
        private string $hotelId,
        private string $enterpriseId,
        private string $enterpriseHeaderName,
        private string $scope,
        private string $tokenCacheFile,
        private Logger $logger,
        private int $clockSkewSeconds = 30,
    ) {
        $this->baseUrl = rtrim($this->baseUrl, '/');
        $dir = dirname($this->tokenCacheFile);
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
    }

    /** @return array{tokenObtained:bool, tokenPrefix:string, expiresAt:int|null} */
    public function testToken(): array
    {
        $token = $this->getAccessToken();
        $cached = $this->readTokenCache();
        $expiresAt = is_array($cached) && isset($cached['expires_at']) ? (int)$cached['expires_at'] : null;

        return [
            'tokenObtained' => true,
            'tokenPrefix' => substr($token, 0, 12) . '...',
            'expiresAt' => $expiresAt,
        ];
    }

/** @return array{status:int, headers:array<string,string>, body:mixed} */
public function getReservation(string $reservationId): array
{
    $path = "/rsv/v1/hotels/" . rawurlencode($this->hotelId) . "/reservations/" . rawurlencode($reservationId);
    return $this->callJson('GET', $path, null);
}


    /** @return array{status:int, headers:array<string,string>, body:mixed} */
    

/** @return array{status:int, headers:array<string,string>, body:mixed} */



public function putReservationAttachCompany(string $reservationId, string $companyProfileId, string $companyName): array
{
    $path = "/rsv/v1/hotels/" . rawurlencode($this->hotelId) . "/reservations/" . rawurlencode($reservationId);

    $idType = (string)(getenv('OHIP_COMPANY_PROFILE_ID_TYPE') ?: 'Profile');

    // GET reservation first; tenant requires roomStay in PUT for linked profile update to apply.
    $get = $this->getReservation($reservationId);
    $roomStay = $this->extractRoomStay($get['body'] ?? null);

    $this->logger->info('ohip.roomStay.required', [
        'reservationId' => $reservationId,
        'roomStayPresent' => $roomStay !== null,
    ]);

    $payload = [
        'reservations' => [[
            'reservationIdList' => [[ 'type' => 'Reservation', 'id' => $reservationId ]],
            'reservationProfiles' => [
                'reservationProfile' => [[
                    'reservationProfileType' => 'Company',
                    'profileIdList' => [[ 'id' => $companyProfileId, 'type' => $idType ]],
                ]],
            ],
        ]],
    ];

    if (is_array($roomStay)) {
        $payload['reservations'][0]['roomStay'] = $roomStay;
    }

    $this->logger->info('ohip.put.payload.company_only', [
        'reservationId' => $reservationId,
        'companyProfileId' => $companyProfileId,
        'roomStayIncluded' => is_array($roomStay),
    ]);

    return $this->callJson('PUT', $path, $payload);
}
public function buildCompanyLinkPayload(string $reservationId, string $companyProfileId, string $companyName): array
{
    $mode = strtolower((string)(getenv('OHIP_PUT_MODE') ?: 'auto'));

    // type used in GET attachedProfiles.profileIdList.type is usually "Profile"
    $idType = (string)(getenv('OHIP_COMPANY_PROFILE_ID_TYPE') ?: 'Profile');

    if ($mode === 'attachedprofiles') {
        return [
            'reservations' => [[
                'reservationIdList' => [['id' => $reservationId, 'type' => 'Reservation']],
                'attachedProfiles' => [[
                    'name' => $companyName,
                    'profileIdList' => [[ 'id' => $companyProfileId, 'type' => $idType ]],
                    'reservationProfileType' => 'Company',
                ]],
            ]],
        ];
    }

    if ($mode === 'reservationprofiles') {
        return [
            'reservations' => [[
                'reservationIdList' => [['id' => $reservationId, 'type' => 'Reservation']],
                'reservationProfiles' => [[
                    'name' => $companyName,
                    'profileIdList' => [[ 'id' => $companyProfileId, 'type' => $idType ]],
                    'reservationProfileType' => 'Company',
                ]],
            ]],
        ];
    }

    if ($mode === 'reservationguests') {
        return [
            'reservations' => [[
                'reservationIdList' => [['id' => $reservationId, 'type' => 'Reservation']],
                'reservationGuests' => [[
                    'profileInfo' => [
                        'profileIdList' => [[ 'id' => $companyProfileId, 'type' => $idType ]],
                        'profile' => [ 'profileType' => 'Company' ],
                    ],
                    'primary' => false,
                ]],
            ]],
        ];
    }

    // auto mode: start with reservationGuests (most common for linking Company in POST examples)
    return [
        'reservations' => [[
            'reservationIdList' => [['id' => $reservationId, 'type' => 'Reservation']],
            'reservationGuests' => [[
                'profileInfo' => [
                    'profileIdList' => [[ 'id' => $companyProfileId, 'type' => $idType ]],
                    'profile' => [ 'profileType' => 'Company' ],
                ],
                'primary' => false,
            ]],
        ]],
    ];
}


    /** @return array{status:int, headers:array<string,string>, body:mixed} */
    public function callJson(string $method, string $pathOrUrl, ?array $body): array
    {
        $token = $this->getAccessToken();

        $headers = [
            'Authorization' => "Bearer {$token}",
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'x-app-key' => $this->appKey,
            'x-hotelid' => $this->hotelId,
            $this->enterpriseHeaderName => $this->enterpriseId,
        ];

        $url = preg_match('#^https?://#i', $pathOrUrl) ? $pathOrUrl : ($this->baseUrl . $pathOrUrl);

        $this->logger->info('ohip.request', ['method' => $method, 'url' => $url]);
        $resp = $this->curlJson($method, $url, $headers, $body);
        $this->logger->info('ohip.response', ['status' => $resp['status'], 'url' => $url]);

        return $resp;
    }

    
/** @return array<int,array<string,mixed>> */
private function extractReservationProfiles(mixed $body): array
{
    if (!is_array($body)) return [];
    $reservations = $body['reservations'] ?? null;
    if (!is_array($reservations)) return [];
    $reservationList = $reservations['reservation'] ?? null;
    if (!is_array($reservationList) || empty($reservationList)) return [];
    $first = $reservationList[0] ?? null;
    if (!is_array($first)) return [];
    $rp = $first['reservationProfiles'] ?? null;
    if (!is_array($rp)) return [];
    $list = $rp['reservationProfile'] ?? [];
    return is_array($list) ? $list : [];
}


/** @return array<string,mixed>|null */
private function extractRoomStay(mixed $body): ?array
{
    if (!is_array($body)) return null;
    $reservations = $body['reservations'] ?? null;
    if (!is_array($reservations)) return null;
    $reservationList = $reservations['reservation'] ?? null;
    if (!is_array($reservationList) || empty($reservationList)) return null;
    $first = $reservationList[0] ?? null;
    if (!is_array($first)) return null;
    $roomStay = $first['roomStay'] ?? null;
    return is_array($roomStay) ? $roomStay : null;
}

private function reservationProfilesHas(array $profiles, string $profileId, string $reservationProfileType): bool
{
    foreach ($profiles as $p) {
        if (!is_array($p)) continue;
        if (strtolower((string)($p['reservationProfileType'] ?? '')) !== strtolower($reservationProfileType)) continue;
        $ids = $p['profileIdList'] ?? null;
        if (!is_array($ids)) continue;
        foreach ($ids as $idObj) {
            if (!is_array($idObj)) continue;
            if ((string)($idObj['id'] ?? '') === $profileId) return true;
        }
    }
    return false;
}

private function getAccessToken(): string
    {
        $cached = $this->readTokenCache();
        if (
            is_array($cached) &&
            isset($cached['access_token'], $cached['expires_at']) &&
            time() < ((int)$cached['expires_at'] - $this->clockSkewSeconds)
        ) {
            return (string)$cached['access_token'];
        }

        $url = $this->baseUrl . $this->tokenPath;

        $headers = [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'x-app-key' => $this->appKey,
            // OHIP token endpoint requires Basic Auth (client_id:client_secret)
            'Authorization' => 'Basic ' . base64_encode($this->clientId . ':' . $this->clientSecret),
            'x-hotelid' => $this->hotelId,
            $this->enterpriseHeaderName => $this->enterpriseId,
        ];

        $form = http_build_query([
            'grant_type' => 'client_credentials',
            'scope' => $this->scope,
        ]);

        $this->logger->info('ohip.token.request', ['url' => $url]);
        $raw = $this->curlRaw('POST', $url, $headers, $form);
        $this->logger->info('ohip.token.response', ['status' => $raw['status']]);

        $json = json_decode($raw['body'], true);
        if (!is_array($json) || !isset($json['access_token'])) {
            throw new RuntimeException("Token response missing access_token. HTTP {$raw['status']}: {$raw['body']}");
        }

        $expiresIn = (int)($json['expires_in'] ?? 3600);
        $cache = [
            'access_token' => (string)$json['access_token'],
            'expires_at' => time() + max(60, $expiresIn),
            'obtained_at' => time(),
        ];
        $this->writeTokenCache($cache);

        return (string)$json['access_token'];
    }

    /** @return array{status:int, headers:array<string,string>, body:string} */
    private function curlRaw(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Failed to init curl');

        $headerLines = [];
        foreach ($headers as $k => $v) $headerLines[] = "{$k}: {$v}";

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 60,
        ]);

        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("cURL error: {$err}");
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        return [
            'status' => $status,
            'headers' => $this->parseHeaders(substr($raw, 0, $headerSize)),
            'body' => substr($raw, $headerSize),
        ];
    }

    /** @return array{status:int, headers:array<string,string>, body:mixed} */
    private function curlJson(string $method, string $url, array $headers, ?array $body): array
    {
        $rawBody = $body === null ? null : json_encode($body, JSON_UNESCAPED_SLASHES);
        $raw = $this->curlRaw($method, $url, $headers, $rawBody);

        $decoded = json_decode($raw['body'], true);
        $outBody = (json_last_error() === JSON_ERROR_NONE) ? $decoded : $raw['body'];

        return ['status' => $raw['status'], 'headers' => $raw['headers'], 'body' => $outBody];
    }

    /** @return array<string,string> */
    private function parseHeaders(string $rawHeaders): array
    {
        $lines = preg_split("/\r\n|\n|\r/", trim($rawHeaders)) ?: [];
        $headers = [];
        foreach ($lines as $line) {
            if (stripos($line, 'HTTP/') === 0) continue;
            $pos = strpos($line, ':');
            if ($pos === false) continue;
            $k = trim(substr($line, 0, $pos));
            $v = trim(substr($line, $pos + 1));
            if ($k !== '') $headers[$k] = $v;
        }
        return $headers;
    }

    private function readTokenCache(): ?array
    {
        if (!is_file($this->tokenCacheFile)) return null;
        $json = @file_get_contents($this->tokenCacheFile);
        if (!is_string($json)) return null;
        $data = json_decode($json, true);
        return is_array($data) ? $data : null;
    }

    private function writeTokenCache(array $data): void
    {
        @file_put_contents($this->tokenCacheFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
