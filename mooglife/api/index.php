<?php
// mooglife/api/index.php
// Human-readable API index for developers.

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/bootstrap.php'; // so we can show tier info

$tier = moog_api_effective_tier();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Moog API Directory</title>
    <style>
        body {
            background:#020617;
            color:#e2e8f0;
            font-family: Arial, sans-serif;
            padding:20px 30px;
        }
        h1 {
            color:#7dd3fc;
            margin-bottom:6px;
        }
        .desc {
            color:#94a3b8;
            margin-bottom:18px;
            font-size:14px;
        }
        .endpoint {
            background:#0f172a;
            border:1px solid #1e293b;
            padding:15px 20px;
            border-radius:8px;
            margin-bottom:18px;
        }
        .endpoint h2 {
            margin:0 0 6px 0;
            font-size:18px;
            color:#93c5fd;
        }
        .endpoint a {
            color:#38bdf8;
            text-decoration:none;
        }
        code {
            background:#1e293b;
            padding:2px 6px;
            border-radius:4px;
            color:#f1f5f9;
            font-size:13px;
        }
        .tier-pill {
            display:inline-block;
            padding:2px 8px;
            border-radius:999px;
            font-size:11px;
            margin-top:4px;
            background:#1f2937;
        }
    </style>
</head>
<body>

<h1>Moog API v1 (Internal Layer)</h1>
<div class="desc">
    JSON endpoints served by the Mooglife system.<br>
    This layer powers dashboards and the public <code>/api/v1/</code> routes.<br>
    Current effective tier: <span class="tier-pill"><?php echo htmlspecialchars($tier, ENT_QUOTES, 'UTF-8'); ?></span>
</div>

<div class="endpoint">
    <h2>Versioned Public API</h2>
    <p>Production-facing entrypoint for external devs:</p>
    <p><a href="v1/index.php">/api/v1/index.php</a></p>
    <p style="font-size:12px;color:#94a3b8;">
        Use this for anything you want to publish externally (widgets, bots, dashboards).
        It wraps the internal endpoints below.
    </p>
</div>

<div class="endpoint">
    <h2>System Info API</h2>
    <p>Environment, DB metadata, tables, last sync times, and settings snapshot.</p>
    <p><a href="system.php">system.php</a></p>
</div>

<div class="endpoint">
    <h2>Market API</h2>
    <p>Latest price / FDV / liquidity snapshot or historical series.</p>
    <p><a href="market.php">market.php</a></p>
    <p><code>?mode=history&amp;limit=200</code> – PRO / INTERNAL only for external callers.</p>
</div>

<div class="endpoint">
    <h2>Holders API</h2>
    <p>Top MOOG holders or single-wallet profile.</p>
    <p><a href="holders.php">holders.php</a></p>
    <p>
        <code>?limit=100</code> – top holders (tier-capped)<br>
        <code>?wallet=YOUR_WALLET</code> – profile if present
    </p>
</div>

<div class="endpoint">
    <h2>Transactions API</h2>
    <p>Recent MOOG transactions with filters.</p>
    <p><a href="tx.php">tx.php</a></p>
    <p>
        <code>?limit=100</code><br>
        <code>?direction=BUY</code><br>
        <code>?wallet=YOUR_WALLET</code><br>
        <code>?source=raydium</code>
    </p>
</div>

<div class="endpoint">
    <h2>Airdrops API</h2>
    <p>Airdrop records and per-wallet / global summaries.</p>
    <p><a href="airdrops.php">airdrops.php</a></p>
    <p>
        <code>?limit=200</code> – latest rows (tier-capped)<br>
        <code>?wallet=YOUR_WALLET</code> – airdrops for that wallet<br>
        <code>?wallet=YOUR_WALLET&amp;summary=1</code> – per-wallet summary (PRO+)<br>
        <code>?summary=1&amp;limit=50</code> – global summary (PRO+)
    </p>
</div>

<div class="endpoint">
    <h2>OG Buyers API</h2>
    <p>Flexible OG buyers view with wallet filter.</p>
    <p><a href="og_buyers.php">og_buyers.php</a></p>
    <p>
        <code>?limit=100</code> – latest OG buyers (tier-capped)<br>
        <code>?wallet=YOUR_WALLET</code> – filter by wallet (if wallet column exists)
    </p>
</div>

<div class="endpoint">
    <h2>OG Rewards API</h2>
    <p>Flexible OG rewards view with wallet / type / status filters.</p>
    <p><a href="og_rewards.php">og_rewards.php</a></p>
    <p>
        <code>?limit=100</code> – latest rewards (tier-capped)<br>
        <code>?wallet=YOUR_WALLET</code> – if wallet column exists<br>
        <code>?type=SOMETHING</code> – if reward_type / type column exists<br>
        <code>?status=paid</code> – if status column exists
    </p>
</div>

<hr style="border:0;border-top:1px solid #1e293b;margin:35px 0;">

<h2 style="color:#7dd3fc;margin-top:0;">Notes &amp; Roadmap</h2>
<ul style="color:#94a3b8;font-size:14px;">
    <li>All endpoints are JSON and share the wrapper: <code>{ ok: true|false, data|error }</code>.</li>
    <li>API keys are optional for localhost; on VPS you can require them via <code>mg_settings.api_require_key</code>.</li>
    <li>Per-tier request quotas and feature gates are enforced in <code>api/bootstrap.php</code>.</li>
    <li>Use the versioned <code>/api/v1/</code> routes for anything public or third-party.</li>
</ul>

</body>
</html>
