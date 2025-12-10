<?php
// mooglife/pages/api_docs.php
// Internal Moog API directory (inside the main layout).

require __DIR__ . '/../includes/auth.php';
mg_require_login();
?>
<h1>Moog API Directory</h1>
<p class="muted">
    This section documents the current <strong>Moog API v1</strong> endpoints exposed by Mooglife.<br>
    All endpoints return JSON and are intended for internal tools, dashboards, and future public devs.
</p>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">Base URL</h2>
    <p style="font-size:13px;">
        On this server, API endpoints are available under:<br>
        <code>http://localhost/mooglife/api/</code>
    </p>
</div>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">System Info API</h2>
    <p class="muted" style="font-size:13px;margin-bottom:6px;">
        Returns environment info, DB metadata, table existence, last sync timestamps, and settings from
        <code>mg_settings</code> (if present).
    </p>
    <ul style="font-size:13px;line-height:1.5;">
        <li><code>/api/system.php</code> – full system snapshot</li>
    </ul>
</div>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">Market API</h2>
    <p class="muted" style="font-size:13px;margin-bottom:6px;">
        Latest MOOG market snapshot or historical snapshots.
    </p>
    <ul style="font-size:13px;line-height:1.5;">
        <li><code>/api/market.php</code> – latest snapshot</li>
        <li>
            <code>/api/market.php?mode=history&amp;limit=200</code><br>
            Returns last <code>limit</code> rows from <code>mg_market_history</code>
            (newest first, max 1000).
        </li>
    </ul>
</div>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;font-size:16px;">Holders API</h2>
    <p class="muted" style="font-size:13px;">
        Top MOOG holders by bag size, or single-wallet lookup with rank.
    </p>
    <pre class="inline-code" style="font-size:12px;">
/api/holders.php
    → top holders (limit default 100; tier caps: free 100, pro 500, internal 2000)

/api/holders.php?limit=250
    → same, with limit override (subject to tier caps)

/api/holders.php?wallet=YOUR_WALLET
    → single wallet profile (ui_amount, percent, rank, token account)
    </pre>
</div>


<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">Transactions API</h2>
    <p class="muted" style="font-size:13px;margin-bottom:6px;">
        Recent MOOG transactions from <code>mg_moog_tx</code>, with filters similar to Tx History.
    </p>
    <ul style="font-size:13px;line-height:1.5%;">
        <li><code>/api/tx.php</code> – last 100 transactions by default</li>
        <li><code>/api/tx.php?limit=50</code> – change result size (1–500)</li>
        <li><code>/api/tx.php?direction=BUY</code> – filter by <code>BUY</code> or <code>SELL</code></li>
        <li><code>/api/tx.php?wallet=YOUR_WALLET</code> – match wallet in <code>from_wallet</code> or <code>to_wallet</code></li>
        <li><code>/api/tx.php?source=raydium</code> – filter by source (e.g. <code>raydium</code>, <code>birdeye_v3</code>)</li>
    </ul>
</div>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">OG Buyers API</h2>
    <p class="muted" style="font-size:13px;margin-bottom:6px;">
        OG buyer records from <code>mg_og_buyers</code>.
    </p>
    <ul style="font-size:13px;line-height:1.5%;">
        <li><code>/api/og_buyers.php</code> – full OG buyers list</li>
        <li><code>/api/og_buyers.php?wallet=YOUR_WALLET</code> – single OG buyer row (if any)</li>
    </ul>
</div>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">Airdrops API</h2>
    <p class="muted" style="font-size:13px;margin-bottom:6px;">
        Airdrop records and per-wallet totals from the airdrop table
        (<code>mg_moog_airdrops</code> or <code>moog_airdrops</code>).
    </p>
    <ul style="font-size:13px;line-height:1.5%;">
        <li><code>/api/airdrops.php</code> – latest 100 airdrops globally</li>
        <li><code>/api/airdrops.php?limit=200</code> – change result size (1–1000)</li>
        <li><code>/api/airdrops.php?wallet=YOUR_WALLET</code> – airdrop list for that wallet</li>
        <li><code>/api/airdrops.php?wallet=YOUR_WALLET&amp;summary=1</code> – total MOOG + drop count for that wallet</li>
        <li><code>/api/airdrops.php?summary=1&amp;limit=50</code> – global per-wallet summary (top recipients)</li>
    </ul>
</div>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">OG Rewards API</h2>
    <p class="muted" style="font-size:13px;margin-bottom:6px;">
        OG reward records from the OG rewards table
        (<code>mg_og_rewards</code>, <code>og_rewards</code>, or <code>mg_ogrewards</code>),
        with optional filters.
    </p>
    <ul style="font-size:13px;line-height:1.5%;">
        <li><code>/api/og_rewards.php</code> – latest 100 OG rewards</li>
        <li><code>/api/og_rewards.php?limit=200</code> – change result size (1–1000)</li>
        <li><code>/api/og_rewards.php?wallet=YOUR_WALLET</code> – rewards for a specific wallet (if wallet column exists)</li>
        <li><code>/api/og_rewards.php?type=REWARD_TYPE</code> – filter by <code>reward_type</code> (if present)</li>
        <li><code>/api/og_rewards.php?status=paid</code> – filter by <code>status</code> (if present)</li>
    </ul>
</div>

<div class="card" style="margin-bottom:20px;">
    <h2 style="margin-top:0;">Notes &amp; Roadmap</h2>
    <ul style="font-size:13px;line-height:1.6%;">
        <li>All endpoints are currently <strong>open</strong> on localhost (no API key).</li>
        <li>Responses use a common wrapper: <code>{ ok: true|false, data|error }</code>.</li>
        <li>Errors return an HTTP status (400–500) with an <code>error</code> message.</li>
        <li>Future work:
            <ul>
                <li>Per-user API keys &amp; roles</li>
                <li>Public dev documentation on moongoblins.net</li>
                <li>Webhook/event push system for external bots</li>
            </ul>
        </li>
    </ul>
</div>
