<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';
auth_require_login();

header_remove('Set-Cookie');

$stateFile = __DIR__ . '/storage/export_state.json';
$debugFile = __DIR__ . '/storage/export_debug.json';
$client = app_client();

function load_state(string $stateFile): array
{
    if (!is_file($stateFile)) {
        return [
            'started' => false,
            'phase' => 'idle',
            'limit' => 200,
            'offset' => 0,
            'pagesScanned' => 0,
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
            'discoveryStoppedReason' => '',
        ];
    }

    $raw = file_get_contents($stateFile);
    $data = json_decode((string)$raw, true);

    return is_array($data) ? $data : [
        'started' => false,
        'phase' => 'idle',
        'limit' => 200,
        'offset' => 0,
        'pagesScanned' => 0,
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
        'discoveryStoppedReason' => '',
    ];
}

function save_state(string $stateFile, array $state): void
{
    file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function save_debug(string $debugFile, array $payload): void
{
    file_put_contents($debugFile, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
}

function collect_reservation_ids(mixed $body, array &$ids): void
{
    if (!is_array($body)) {
        return;
    }

    if (isset($body['reservations']['reservationInfo']) && is_array($body['reservations']['reservationInfo'])) {
        foreach ($body['reservations']['reservationInfo'] as $reservation) {
            if (!is_array($reservation)) {
                continue;
            }

            foreach (($reservation['reservationIdList'] ?? []) as $idObj) {
                if (!is_array($idObj)) {
                    continue;
                }

                if (($idObj['type'] ?? '') === 'Reservation' && isset($idObj['id']) && $idObj['id'] !== '') {
                    $ids[] = (string)$idObj['id'];
                }
            }
        }

        return;
    }

    if (isset($body['reservationIdList']) && is_array($body['reservationIdList'])) {
        foreach ($body['reservationIdList'] as $idObj) {
            if (!is_array($idObj)) {
                continue;
            }

            if (($idObj['type'] ?? '') === 'Reservation' && isset($idObj['id']) && $idObj['id'] !== '') {
                $ids[] = (string)$idObj['id'];
            }
        }
    }

    foreach ($body as $value) {
        if (is_array($value)) {
            collect_reservation_ids($value, $ids);
        }
    }
}

function search_page_items_count(mixed $body): int
{
    if (!is_array($body)) {
        return 0;
    }

    if (isset($body['reservations']['reservationInfo']) && is_array($body['reservations']['reservationInfo'])) {
        return count($body['reservations']['reservationInfo']);
    }

    if (isset($body['reservations']['reservation']) && is_array($body['reservations']['reservation'])) {
        return count($body['reservations']['reservation']);
    }

    if (isset($body['reservations']) && is_array($body['reservations'])) {
        return count($body['reservations']);
    }

    if (isset($body['reservationInfo']) && is_array($body['reservationInfo'])) {
        return count($body['reservationInfo']);
    }

    return 0;
}

function top_level_keys(mixed $body): array
{
    return is_array($body) ? array_keys($body) : [];
}

function state_payload(array $state, string $message): array
{
    $total = is_array($state['ids'] ?? null) ? count($state['ids']) : 0;
    $done = (int)($state['pos'] ?? 0);
    $startedAt = isset($state['startedAt']) ? (float)$state['startedAt'] : null;
    $etaSeconds = null;

    if ($startedAt !== null) {
        $elapsed = max(0.001, microtime(true) - $startedAt);

        if (($state['phase'] ?? '') === 'export' && $done > 0 && $total > $done) {
            $rate = $done / $elapsed;
            if ($rate > 0) {
                $etaSeconds = ($total - $done) / $rate;
            }
        }
    }

    return [
        'started' => (bool)($state['started'] ?? false),
        'phase' => (string)($state['phase'] ?? 'idle'),
        'done' => $done,
        'total' => $total,
        'idsReady' => $total,
        'percent' => ($state['phase'] ?? '') === 'discover'
            ? 0
            : ($total > 0 ? (int)round(($done / $total) * 100) : 100),
        'finished' => (bool)($state['finished'] ?? false),
        'cancelled' => (bool)($state['cancelled'] ?? false),
        'currentReservationId' => (string)($state['currentReservationId'] ?? ''),
        'rowsReady' => is_array($state['rows'] ?? null) ? count($state['rows']) : 0,
        'successCount' => (int)($state['successCount'] ?? 0),
        'failedCount' => (int)($state['failedCount'] ?? 0),
        'pagesScanned' => (int)($state['pagesScanned'] ?? 0),
        'etaSeconds' => $etaSeconds,
        'message' => $message,
    ];
}

if (isset($_GET['start'])) {
    header('Content-Type: application/json; charset=utf-8');

    $state = [
        'started' => true,
        'phase' => 'discover',
        'limit' => 200,
        'offset' => 0,
        'pagesScanned' => 0,
        'ids' => [],
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
        'discoveryStoppedReason' => '',
    ];

    save_state($stateFile, $state);
    save_debug($debugFile, ['status' => 'started']);

    echo json_encode(state_payload($state, 'Export started. Discovering reservation ids...'));
    exit;
}

if (isset($_GET['cancel'])) {
    header('Content-Type: application/json; charset=utf-8');

    $state = load_state($stateFile);
    $state['cancelled'] = true;
    $state['finishedAt'] = microtime(true);
    $state['lastMessage'] = 'Export cancelled';

    save_state($stateFile, $state);

    echo json_encode(state_payload($state, 'Export cancelled. Completed rows kept for download.'));
    exit;
}

if (isset($_GET['step'])) {
    header('Content-Type: application/json; charset=utf-8');

    $state = load_state($stateFile);

    if (($state['cancelled'] ?? false) === true) {
        echo json_encode(state_payload($state, 'Export already cancelled.'));
        exit;
    }

    if (($state['finished'] ?? false) === true) {
        echo json_encode(state_payload($state, 'Export already finished.'));
        exit;
    }

    $hotelId = (string)getenv('OHIP_HOTEL_ID');

    if (($state['phase'] ?? '') === 'discover') {
        $limit = (int)($state['limit'] ?? 200);
        $offset = (int)($state['offset'] ?? 0);

        $path = "/rsv/v1/hotels/" . rawurlencode($hotelId) . "/reservations?limit={$limit}&offset={$offset}";
        $response = $client->callJson('GET', $path, null);
        $body = $response['body'] ?? [];

        $pageIds = [];
        collect_reservation_ids($body, $pageIds);
        $pageIds = array_values(array_unique(array_filter($pageIds, static fn($v) => is_string($v) && $v !== '')));

        $existing = is_array($state['ids'] ?? null) ? $state['ids'] : [];
        $existingCount = count($existing);

        $merged = array_values(array_unique(array_merge($existing, $pageIds)));
        $newAdded = count($merged) - $existingCount;

        $state['ids'] = $merged;
        $state['pagesScanned'] = (int)($state['pagesScanned'] ?? 0) + 1;

        $pageCount = search_page_items_count($body);
        $state['offset'] = $offset + $limit;

        if ($state['pagesScanned'] === 1) {
            save_debug($debugFile, [
                'searchPath' => $path,
                'topLevelKeys' => top_level_keys($body),
                'pageCount' => $pageCount,
                'pageIdsFound' => count($pageIds),
                'sampleBody' => $body,
            ]);
        }

        if (count($state['ids']) === 0 && $pageCount === 0) {
            $state['finished'] = true;
            $state['phase'] = 'finished';
            $state['lastMessage'] = 'No reservations found to export.';
            save_state($stateFile, $state);
            echo json_encode(state_payload($state, $state['lastMessage']));
            exit;
        }

        if ($newAdded === 0) {
            $state['phase'] = 'export';
            $state['currentReservationId'] = $state['ids'][0] ?? '';
            $state['discoveryStoppedReason'] = 'Pagination returned no new reservation ids.';
            $state['lastMessage'] = 'Discovery stopped at page ' . $state['pagesScanned'] . ' because no new reservation ids were returned. Total discovered: ' . count($state['ids']) . '.';
            save_state($stateFile, $state);
            echo json_encode(state_payload($state, $state['lastMessage']));
            exit;
        }

        if ($pageCount < $limit) {
            $state['phase'] = 'export';
            $state['currentReservationId'] = $state['ids'][0] ?? '';
            $state['discoveryStoppedReason'] = 'Last page returned fewer than limit.';
            $state['lastMessage'] = 'Discovery complete. Found ' . count($state['ids']) . ' reservations in ' . $state['pagesScanned'] . ' page(s).';
        } else {
            $state['lastMessage'] = 'Discovery page ' . $state['pagesScanned'] . ': found ' . count($pageIds) . ' reservation ids, new added ' . $newAdded . ', total discovered ' . count($state['ids']) . '.';
        }

        save_state($stateFile, $state);
        echo json_encode(state_payload($state, $state['lastMessage']));
        exit;
    }

    $ids = is_array($state['ids'] ?? null) ? $state['ids'] : [];
    $pos = (int)($state['pos'] ?? 0);
    $rows = is_array($state['rows'] ?? null) ? $state['rows'] : [];
    $successCount = (int)($state['successCount'] ?? 0);
    $failedCount = (int)($state['failedCount'] ?? 0);
    $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];

    if ($pos >= count($ids)) {
        $state['finished'] = true;
        $state['phase'] = 'finished';
        $state['finishedAt'] = microtime(true);
        $state['currentReservationId'] = '';
        $state['lastMessage'] = 'Export complete. Success: ' . $successCount . ' | Failed: ' . $failedCount . '.';

        save_state($stateFile, $state);

        echo json_encode(state_payload($state, $state['lastMessage']));
        exit;
    }

    $batch = 3;
    $lastId = '';

    for ($i = 0; $i < $batch && $pos < count($ids); $i++, $pos++) {
        $reservationId = (string)$ids[$pos];
        $lastId = $reservationId;
        $state['currentReservationId'] = $reservationId;
        save_state($stateFile, $state);

        try {
            $path = "/rsv/v1/hotels/" . rawurlencode($hotelId) . "/reservations/" . rawurlencode($reservationId);
            $response = $client->callJson('GET', $path, null);
            $body = $response['body'] ?? [];
            $refs = is_array($body) ? ($body['externalReferences'] ?? []) : [];

            $erp = '';
            $importCnf = '';

            if (is_array($refs)) {
                foreach ($refs as $ref) {
                    if (!is_array($ref)) {
                        continue;
                    }

                    if (($ref['idContext'] ?? '') === 'ERP') {
                        $erp = (string)($ref['id'] ?? '');
                    }

                    if (($ref['idContext'] ?? '') === 'IMPORTCNF') {
                        $importCnf = (string)($ref['id'] ?? '');
                    }
                }
            }

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

    $state['phase'] = 'export';
    $state['pos'] = $pos;
    $state['rows'] = $rows;
    $state['successCount'] = $successCount;
    $state['failedCount'] = $failedCount;
    $state['errors'] = $errors;
    $state['currentReservationId'] = $pos < count($ids) ? (string)$ids[$pos] : '';
    $state['finished'] = $pos >= count($ids);

    if ($state['finished']) {
        $state['phase'] = 'finished';
        $state['finishedAt'] = microtime(true);
        $state['lastMessage'] = 'Export complete. Success: ' . $successCount . ' | Failed: ' . $failedCount . '.';
    } else {
        $state['lastMessage'] = 'Processed ' . $pos . ' of ' . count($ids) . ($lastId !== '' ? (' (last: ' . $lastId . ')') : '') . ' | success: ' . $successCount . ' | failed: ' . $failedCount;
    }

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
        fputcsv($out, ['reservationId', 'ERP', 'IMPORTCNF']);

        foreach ($rows as $row) {
            if (is_array($row)) {
                fputcsv($out, $row);
            }
        }
    } else {
        header('Content-Disposition: attachment; filename=discovered_reservation_ids.csv');
        fputcsv($out, ['reservationId']);

        foreach ($ids as $id) {
            fputcsv($out, [$id]);
        }
    }

    fclose($out);
    exit;
}

header('Content-Type: application/json; charset=utf-8');
$state = load_state($stateFile);
echo json_encode(state_payload($state, (string)($state['lastMessage'] ?? 'Idle')));
