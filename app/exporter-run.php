<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
auth_require_login();

header_remove('Set-Cookie');

$stateFile = __DIR__ . '/storage/export_state.json';
$client = app_client();

function load_state(string $stateFile): array
{
    if (!is_file($stateFile)) {
        return [
            'ids' => [],
            'pos' => 0,
            'rows' => [],
            'startedAt' => null,
            'finishedAt' => null,
            'cancelled' => false,
            'finished' => false,
            'currentReservationId' => '',
            'lastMessage' => 'Idle',
            'successCount' => 0,
            'failedCount' => 0,
            'errors' => [],
        ];
    }

    $raw = file_get_contents($stateFile);
    $data = json_decode((string)$raw, true);

    return is_array($data) ? $data : [];
}

function save_state(string $stateFile, array $state): void
{
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function state_payload(array $state, string $message): array
{
    $total = is_array($state['ids'] ?? null) ? count($state['ids']) : 0;
    $done = (int)($state['pos'] ?? 0);
    $startedAt = isset($state['startedAt']) ? (float)$state['startedAt'] : null;
    $etaSeconds = null;

    if ($startedAt !== null && $done > 0 && $total > $done) {
        $elapsed = max(0.001, microtime(true) - $startedAt);
        $rate = $done / $elapsed;
        if ($rate > 0) {
            $etaSeconds = ($total - $done) / $rate;
        }
    }

    return [
        'done' => $done,
        'total' => $total,
        'idsReady' => $total,
        'percent' => $total > 0 ? (int)round(($done / $total) * 100) : 100,
        'finished' => (bool)($state['finished'] ?? false),
        'cancelled' => (bool)($state['cancelled'] ?? false),
        'currentReservationId' => (string)($state['currentReservationId'] ?? ''),
        'rowsReady' => is_array($state['rows'] ?? null) ? count($state['rows']) : 0,
        'successCount' => (int)($state['successCount'] ?? 0),
        'failedCount' => (int)($state['failedCount'] ?? 0),
        'etaSeconds' => $etaSeconds,
        'message' => $message,
    ];
}

function extract_importcnf_from_search_item(array $reservationInfo): string
{
    $refs = $reservationInfo['externalReferences'] ?? [];
    if (!is_array($refs)) {
        return '';
    }

    foreach ($refs as $ref) {
        if (!is_array($ref)) {
            continue;
        }
        if (($ref['idContext'] ?? '') === 'IMPORTCNF') {
            return (string)($ref['id'] ?? '');
        }
    }

    return '';
}

function extract_erp_from_detail_body(array $body): string
{
    $refs = $body['reservations']['reservation'][0]['externalReferences'] ?? [];
    if (!is_array($refs)) {
        return '';
    }

    foreach ($refs as $ref) {
        if (!is_array($ref)) {
            continue;
        }
        if (($ref['idContext'] ?? '') === 'ERP') {
            return (string)($ref['id'] ?? '');
        }
    }

    return '';
}

if (isset($_GET['start'])) {
    header('Content-Type: application/json; charset=utf-8');

    $limit = 200;
    $offset = 0;
    $ids = [];

    while (true) {
        $path = "/rsv/v1/hotels/" . rawurlencode((string)getenv('OHIP_HOTEL_ID')) . "/reservations?limit={$limit}&offset={$offset}";
        $response = $client->callJson('GET', $path, null);

        $list = $response['body']['reservations']['reservationInfo'] ?? [];
        if (!is_array($list)) {
            $list = [];
        }

        $newAdded = 0;

        foreach ($list as $reservationInfo) {
            if (!is_array($reservationInfo)) {
                continue;
            }

            $reservationId = '';
            foreach (($reservationInfo['reservationIdList'] ?? []) as $idObj) {
                if (!is_array($idObj)) {
                    continue;
                }
                if (($idObj['type'] ?? '') === 'Reservation' && isset($idObj['id'])) {
                    $reservationId = (string)$idObj['id'];
                    break;
                }
            }

            if ($reservationId === '') {
                continue;
            }

            if (!isset($ids[$reservationId])) {
                $ids[$reservationId] = [
                    'reservationId' => $reservationId,
                    'importCnf' => extract_importcnf_from_search_item($reservationInfo),
                ];
                $newAdded++;
            }
        }

        if (count($list) < $limit || $newAdded === 0) {
            break;
        }

        $offset += $limit;
    }

    $state = [
        'ids' => array_values($ids),
        'pos' => 0,
        'rows' => [],
        'startedAt' => microtime(true),
        'finishedAt' => null,
        'cancelled' => false,
        'finished' => false,
        'currentReservationId' => '',
        'lastMessage' => 'Export started',
        'successCount' => 0,
        'failedCount' => 0,
        'errors' => [],
    ];
    save_state($stateFile, $state);

    echo json_encode(state_payload($state, 'Export started. Found ' . count($state['ids']) . ' reservations.'));
    exit;
}

if (isset($_GET['cancel'])) {
    header('Content-Type: application/json; charset=utf-8');

    $state = load_state($stateFile);
    $state['cancelled'] = true;
    $state['finished'] = false;
    $state['finishedAt'] = microtime(true);
    $state['lastMessage'] = 'Export cancelled';
    save_state($stateFile, $state);

    echo json_encode(state_payload($state, 'Export cancelled. Completed rows kept for download.'));
    exit;
}

if (isset($_GET['step'])) {
    header('Content-Type: application/json; charset=utf-8');

    $state = load_state($stateFile);
    $ids = is_array($state['ids'] ?? null) ? $state['ids'] : [];
    $pos = (int)($state['pos'] ?? 0);
    $rows = is_array($state['rows'] ?? null) ? $state['rows'] : [];
    $successCount = (int)($state['successCount'] ?? 0);
    $failedCount = (int)($state['failedCount'] ?? 0);
    $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];

    if (($state['cancelled'] ?? false) === true) {
        echo json_encode(state_payload($state, 'Export already cancelled.'));
        exit;
    }

    if (($state['finished'] ?? false) === true || $pos >= count($ids)) {
        $state['finished'] = true;
        if (!isset($state['finishedAt']) || $state['finishedAt'] === null) {
            $state['finishedAt'] = microtime(true);
        }
        $state['currentReservationId'] = '';
        save_state($stateFile, $state);
        echo json_encode(state_payload($state, 'Export already finished.'));
        exit;
    }

    $batch = 5;
    $lastId = '';

    for ($i = 0; $i < $batch && $pos < count($ids); $i++, $pos++) {
        $item = $ids[$pos];
        if (!is_array($item)) {
            continue;
        }

        $reservationId = (string)($item['reservationId'] ?? '');
        $importCnf = (string)($item['importCnf'] ?? '');

        if ($reservationId === '') {
            continue;
        }

        $lastId = $reservationId;
        $state['currentReservationId'] = $reservationId;
        save_state($stateFile, $state);

        try {
            $path = "/rsv/v1/hotels/" . rawurlencode((string)getenv('OHIP_HOTEL_ID')) . "/reservations/" . rawurlencode($reservationId);
            $response = $client->callJson('GET', $path, null);
            $body = is_array($response['body'] ?? null) ? $response['body'] : [];

            $erp = extract_erp_from_detail_body($body);

            $rows[] = [$reservationId, $erp, $importCnf];
            $successCount++;
        } catch (Throwable $e) {
            $failedCount++;
            $errors[] = [
                'reservationId' => $reservationId,
                'error' => $e->getMessage(),
            ];
        }
    }

    $state['pos'] = $pos;
    $state['rows'] = $rows;
    $state['successCount'] = $successCount;
    $state['failedCount'] = $failedCount;
    $state['errors'] = $errors;
    $state['currentReservationId'] = $pos < count($ids)
        ? (string)(is_array($ids[$pos] ?? null) ? ($ids[$pos]['reservationId'] ?? '') : '')
        : '';
    $state['finished'] = $pos >= count($ids);

    if ($state['finished']) {
        $state['finishedAt'] = microtime(true);
    }

    $state['lastMessage'] = 'Processed ' . $pos . ' of ' . count($ids) . ($lastId !== '' ? (' (last: ' . $lastId . ')') : '') . ' | success: ' . $successCount . ' | failed: ' . $failedCount;
    save_state($stateFile, $state);

    echo json_encode(state_payload($state, $state['lastMessage']));
    exit;
}

if (isset($_GET['download'])) {
    $state = load_state($stateFile);

    header('Content-Type: text/csv; charset=utf-8');

    $rows = is_array($state['rows'] ?? null) ? $state['rows'] : [];
    $ids = is_array($state['ids'] ?? null) ? $state['ids'] : [];

    $out = fopen('php://output', 'w');

    if (count($rows) > 0) {
        header('Content-Disposition: attachment; filename=external_references_completed.csv');
        fputcsv($out, ['reservationId', 'ERP', 'IMPORTCNF'], ',', '"', '\\');

        foreach ($rows as $row) {
            if (is_array($row)) {
                fputcsv($out, $row, ',', '"', '\\');
            }
        }
    } else {
        header('Content-Disposition: attachment; filename=discovered_reservation_ids.csv');
        fputcsv($out, ['reservationId', 'IMPORTCNF'], ',', '"', '\\');

        foreach ($ids as $item) {
            if (is_array($item)) {
                fputcsv($out, [
                    (string)($item['reservationId'] ?? ''),
                    (string)($item['importCnf'] ?? ''),
                ], ',', '"', '\\');
            }
        }
    }

    fclose($out);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$state = load_state($stateFile);
echo json_encode(state_payload($state, (string)($state['lastMessage'] ?? 'Idle')));
