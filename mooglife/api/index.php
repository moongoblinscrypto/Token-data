<?php
// mooglife/api/index.php
// Human-readable API index for developers.

require_once __DIR__ . '/../includes/db.php';
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
            margin-bottom:10px;
        }
        .desc {
            color:#94a3b8;
            margin-bottom:25px;
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
    </style>
</head>
<body>

<h1>Moog API v1</h1>
<div class="desc">
    Public &amp; internal API endpoints provided by the Mooglife system.<br>
    All responses are returned as JSON.<br>
    No authentication is needed for local development (this will change later).
</div>

<div class="endpoint">
    <h2>Base URL</h2>
    <p>
        On this server, endpoints are available under:<br>
        <code>http://localhost/mooglife/api/</code>
    </p>
</div>

<div class="endpoint">
    <h2>System Info API</h2>
    <p>Environment, DB metadata, tables, last sync times, and settings snapshot.</p>
    <p><a href="system.php">system.php</a></p>
</div>

<div class="endpoint">
    <h2>Market API</h2>
    <p>Latest snapshot or full history.</p>
    <p><a href="market.php">market.php</a></p>
    <p><code>?mode=history&amp;limit=200</code></p>
</div>

<div class="endpoint">
    <h2>Holders API</h2>
    <p>Top wallets by bag size or a single holder lookup.</p>
    <p><a href="holders.php">holders.php</a></p>
    <p>
        <code>?limit=100</code> — Top holders<br>
        <code>?wallet=YOUR_WALLET</code> — Single wallet lookup
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
    <h2>OG Buyers API</h2>
    <p>Complete OG buyers list or single wallet record.</p>
    <p><a href="og_buyers.php">og_buyers.php</a></p>
    <p><code>?wallet=YOUR_WALLET</code></p>
</div>

<div class="endpoint">
    <h2>Airdrops API</h2>
    <p>Airdrop records and per-wallet totals.</p>
    <p><a href="airdrops.php">airdrops.php</a></p>
    <p>
        <code>?limit=200</code> — latest N airdrops<br>
        <code>?wallet=YOUR_WALLET</code> — airdrops for that wallet<br>
        <code>?wallet=YOUR_WALLET&amp;summary=1</code> — totals for that wallet<br>
        <code>?summary=1&amp;limit=50</code> — global per-wallet summary (top recipients)
    </p>
</div>

<div class="endpoint">
    <h2>OG Rewards API</h2>
    <p>OG rewards table with flexible filters.</p>
    <p><a href="og_rewards.php">og_rewards.php</a></p>
    <p>
        <code>?limit=200</code> — latest N rewards<br>
        <code>?wallet=YOUR_WALLET</code> — rewards for that wallet (if wallet column exists)<br>
        <code>?type=REWARD_TYPE</code> — filter by <code>reward_type</code> (if present)<br>
        <code>?status=paid</code> — filter by <code>status</code> (if present)
    </p>
</div>

<hr style="border:0;border-top:1px solid #1e293b;margin:35px 0;">

<h2 style="color:#7dd3fc;margin-top:0;">Coming Soon</h2>
<ul style="color:#94a3b8;font-size:14px;">
    <li>Authentication and API keys for external devs</li>
    <li>Versioned endpoints (<code>/v1</code>, <code>/v2</code>) as the ecosystem grows</li>
    <li>Webhook/event endpoints for real-time updates</li>
</ul>

</body>
</html>
