<?php
// mooglife/api/holders.php
// Holders API: top holders by bag + single wallet lookup.

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

/** @var mysqli $db */

$wallet = (string) api_get('wallet', '');
$wallet = preg_replace('/\s+/', '', $wallet);

$limit = (int) api_get('limit', 100);
if ($limit < 1)   $limit = 1;
if ($limit > 500) $limit = 500;

// If specific wallet is requested, compute dynamic rank
if ($wallet !== '') {
    try {
        $sql = "
            SELECT ranked.wallet,
                   ranked.ui_amount,
                   ranked.percent,
                   ranked.rank
            FROM (
                SELECT h.wallet,
                       h.ui_amount,
                       h.percent,
                       (@r := @r + 1) AS rank
                FROM mg_moog_holders AS h
                JOIN (SELECT @r := 0) AS vars
                ORDER BY h.ui_amount DESC
            ) AS ranked
            WHERE ranked.wallet = ?
            LIMIT 1
        ";

        $stmt = $db->prepare($sql);
        if (!$stmt) {
            api_error('DB error preparing wallet lookup', 500, $db->error);
        }
        $stmt->bind_param('s', $wallet);
        $stmt->execute();
        $res  = $stmt->get_result();
        $data = $res->fetch_assoc() ?: null;
        $stmt->close();

        if (!$data) {
            api_error('Wallet not found in holders table', 404, ['wallet' => $wallet]);
        }

        api_ok([
            'wallet' => $wallet,
            'holder' => $data,
        ]);
    } catch (Throwable $e) {
        api_error('Error loading wallet holder', 500, $e->getMessage());
    }
}

// Otherwise: top N holders by ui_amount
try {
    $sql = "
        SELECT ranked.wallet,
               ranked.ui_amount,
               ranked.percent,
               ranked.rank
        FROM (
            SELECT h.wallet,
                   h.ui_amount,
                   h.percent,
                   (@r2 := @r2 + 1) AS rank
            FROM mg_moog_holders AS h
            JOIN (SELECT @r2 := 0) AS vars2
            ORDER BY h.ui_amount DESC
        ) AS ranked
        ORDER BY ranked.rank ASC
        LIMIT ?
    ";

    $stmt = $db->prepare($sql);
    if (!$stmt) {
        api_error('DB error preparing top holders query', 500, $db->error);
    }
    $stmt->bind_param('i', $limit);
    $stmt->execute();
    $res = $stmt->get_result();

    $rows = [];
    while ($row = $res->fetch_assoc()) {
        $rows[] = $row;
    }
    $stmt->close();

    api_ok([
        'mode'  => 'top',
        'limit' => $limit,
        'count' => count($rows),
        'items' => $rows,
    ]);
} catch (Throwable $e) {
    api_error('Error loading top holders', 500, $e->getMessage());
}
