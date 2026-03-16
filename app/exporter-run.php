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
            'phase' => 'discover',
            'limit' => 200,
            'offset' => 0,
            'pagesScanned' => 0,
            'seenPageHashes' => [],
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
            'discoveryStopReason' => '',
            'emptyAdvanceCount' => 0,
            'maxEmptyAdvanceCount' => 5,
        ];
    }

    $raw = file_get_contents($stateFile);
    $data = json_decode((string)$raw, true);

    return is_array($data) ? $data : [
        'phase' => 'discover',
        'limit' => 200,
        'offset' => 0,
        'pagesScanned' => 0,
        'seenPageHashes' => [],
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
        'discoveryStopReason' => '',
        'emptyAdvanceCount' => 0,
        'maxEmptyAdvanceCount' => 5,
    ];
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
        'phase' => (string)($state['phase'] ?? 'discover'),
        'done' => $done,
        'total' => $total,
        'idsReady' => $total,
        'percent' => $total > 0 ? (int)round(($done / $total) * 100) : 0,
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

function extract_company_id_from_search_item(array $reservationInfo): string
{
    $profiles = $reservationInfo['attachedProfiles'] ?? [];
    if (!is_array($profiles)) {
        return '';
    }

    foreach ($profiles as $profile) {
        if (!is_array($profile)) {
            continue;
        }

        if (($profile['reservationProfileType'] ?? '') !== 'Company') {
            continue;
        }

        $profileIdList = $profile['profileIdList'] ?? [];
        if (!is_array($profileIdList)) {
            continue;
        }

        foreach ($profileIdList as $idObj) {
            if (!is_array($idObj)) {
                continue;
            }

            if (($idObj['type'] ?? '') === 'Profile' && isset($idObj['id']) && $idObj['id'] !== '') {
                return (string)$idObj['id'];
            }
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

    $state = [
        'phase' => 'discover',
        'limit' => 200,
        'offset' => 0,
        'pagesScanned' => 0,
        'seenPageHashes' => [],
        'ids' => [],
        'pos' => 0,
        'rows' => [],
        'startedAt' => microtime(true),
        'finishedAt' => null,
        'cancelled' => false,
        'finished' => false,
        'currentReservationId' => '',
        'lastMessage' => 'Discovery started',
        'successCount' => 0,
        'failedCount' => 0,
        'errors' => [],
        'discoveryStopReason' => '',
        'emptyAdvanceCount' => 0,
        'maxEmptyAdvanceCount' => 5,
    ];

    save_state($stateFile, $state);

    echo json_encode(state_payload($state, 'Discovery started.'));
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
    $rows = is_array($state['rows'] ?? null) ? $state['rows'] : [];
    $successCount = (int)($state['successCount'] ?? 0);
    $failedCount = (int)($state['failedCount'] ?? 0);
    $errors = is_array($state['errors'] ?? null) ? $state['errors'] : [];

    if (($state['cancelled'] ?? false) === true) {
        echo json_encode(state_payload($state, 'Export already cancelled.'));
        exit;
    }

    if (($state['finished'] ?? false) === true) {
        echo json_encode(state_payload($state, 'Export already finished.'));
        exit;
    }

    $hotelId = (string)getenv('OHIP_HOTEL_ID');

    if (($state['phase'] ?? 'discover') === 'discover') {
        $limit = (int)($state['limit'] ?? 200);
        $offset = (int)($state['offset'] ?? 0);
        $pagesScanned = (int)($state['pagesScanned'] ?? 0);
        $seenPageHashes = is_array($state['seenPageHashes'] ?? null) ? $state['seenPageHashes'] : [];
        $emptyAdvanceCount = (int)($state['emptyAdvanceCount'] ?? 0);
        $maxEmptyAdvanceCount = (int)($state['maxEmptyAdvanceCount'] ?? 5);

        $path = "/rsv/v1/hotels/" . rawurlencode($hotelId) . "/reservations?limit={$limit}&offset={$offset}";
        $response = $client->callJson('GET', $path, null);

        $list = $response['body']['reservations']['reservationInfo'] ?? [];
        if (!is_array($list)) {
            $list = [];
        }

        $pageReservationIds = [];
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

            $pageReservationIds[] = $reservationId;

            if (!isset($ids[$reservationId])) {
                $ids[$reservationId] = [
                    'reservationId' => $reservationId,
                    'importCnf' => extract_importcnf_from_search_item($reservationInfo),
                    'companyId' => extract_company_id_from_search_item($reservationInfo),
                ];
                $newAdded++;
            }
        }

        $pagesScanned++;
        $pageHash = md5(json_encode($pageReservationIds, JSON_UNESCAPED_SLASHES));

        $state['ids'] = $ids;
        $state['pagesScanned'] = $pagesScanned;
        $state['seenPageHashes'] = $seenPageHashes;

        $isRepeatedPage = isset($seenPageHashes[$pageHash]);
        if (!$isRepeatedPage) {
            $seenPageHashes[$pageHash] = true;
            $state['seenPageHashes'] = $seenPageHashes;
        }

        if ($list === []) {
            $emptyAdvanceCount++;
            $state['emptyAdvanceCount'] = $emptyAdvanceCount;

            if ($emptyAdvanceCount >= $maxEmptyAdvanceCount) {
                $state['phase'] = 'export';
                $state['discoveryStopReason'] = "No rows returned for {$emptyAdvanceCount} consecutive page(s).";
                $state['lastMessage'] = 'Discovery finished: no more rows returned after offset ' . $offset . '. Total discovered ' . count($ids) . '.';
                save_state($stateFile, $state);
                echo json_encode(state_payload($state, $state['lastMessage']));
                exit;
            }

            $state['offset'] = $offset + $limit;
            $state['lastMessage'] = 'Discovery page ' . $pagesScanned . ': no rows at offset ' . $offset . ', advancing to ' . ($offset + $limit) . ' (' . $emptyAdvanceCount . '/' . $maxEmptyAdvanceCount . ').';
            save_state($stateFile, $state);
            echo json_encode(state_payload($state, $state['lastMessage']));
            exit;
        }

        if ($isRepeatedPage || $newAdded === 0) {
            $emptyAdvanceCount++;
            $state['emptyAdvanceCount'] = $emptyAdvanceCount;

            if ($emptyAdvanceCount >= $maxEmptyAdvanceCount) {
                $state['phase'] = 'export';
                $state['discoveryStopReason'] = $isRepeatedPage
                    ? "Repeated page detected for {$emptyAdvanceCount} consecutive page(s)."
                    : "No new ids added for {$emptyAdvanceCount} consecutive page(s).";
                $state['lastMessage'] = 'Discovery stopped after ' . $emptyAdvanceCount . ' consecutive repeated/empty pages. Total discovered ' . count($ids) . '.';
                save_state($stateFile, $state);
                echo json_encode(state_payload($state, $state['lastMessage']));
                exit;
            }

            $state['offset'] = $offset + $limit;
            $state['lastMessage'] = 'Discovery page ' . $pagesScanned . ': ' .
                ($isRepeatedPage ? 'repeated page' : '0 new ids') .
                ' at offset ' . $offset . ', advancing to ' . ($offset + $limit) .
                ' (' . $emptyAdvanceCount . '/' . $maxEmptyAdvanceCount . '). Total discovered ' . count($ids) . '.';
            save_state($stateFile, $state);
            echo json_encode(state_payload($state, $state['lastMessage']));
            exit;
        }

        $state['emptyAdvanceCount'] = 0;

        if (count($list) < $limit) {
            $state['phase'] = 'export';
            $state['lastMessage'] = 'Discovery finished: last page returned fewer than ' . $limit . ' rows. Total discovered ' . count($ids) . '.';
            save_state($stateFile, $state);
            echo json_encode(state_payload($state, $state['lastMessage']));
            exit;
        }

        $state['offset'] = $offset + $limit;
        $state['lastMessage'] = 'Discovery page ' . $pagesScanned . ': new added ' . $newAdded . ', total discovered ' . count($ids) . ', next offset ' . ($offset + $limit) . '.';
        save_state($stateFile, $state);

        echo json_encode(state_payload($state, $state['lastMessage']));
        exit;
    }

    $ids = array_values($ids);
    $pos = (int)($state['pos'] ?? 0);

    if ($pos >= count($ids)) {
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
        $companyId = (string)($item['companyId'] ?? '');

        if ($reservationId === '') {
            continue;
        }

        $lastId = $reservationId;
        $state['currentReservationId'] = $reservationId;
        save_state($stateFile, $state);

        try {
            $path = "/rsv/v1/hotels/" . rawurlencode($hotelId) . "/reservations/" . rawurlencode($reservationId);
            $response = $client->callJson('GET', $path, null);
            $body = is_array($response['body'] ?? null) ? $response['body'] : [];

            $erp = extract_erp_from_detail_body($body);

            $rows[] = [$reservationId, $erp, $importCnf, $companyId];
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
        fputcsv($out, ['reservationId', 'ERP', 'IMPORTCNF', 'CompanyID'], ',', '"', '\\');

        foreach ($rows as $row) {
            if (is_array($row)) {
                fputcsv($out, $row, ',', '"', '\\');
            }
        }
    } else {
        header('Content-Disposition: attachment; filename=discovered_reservation_ids.csv');
        fputcsv($out, ['reservationId', 'IMPORTCNF', 'CompanyID'], ',', '"', '\\');

        foreach ($ids as $item) {
            if (is_array($item)) {
                fputcsv($out, [
                    (string)($item['reservationId'] ?? ''),
                    (string)($item['importCnf'] ?? ''),
                    (string)($item['companyId'] ?? ''),
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
