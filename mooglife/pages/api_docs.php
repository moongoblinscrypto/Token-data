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
    <h2 style="margin-top:0;">Market API</h2>
    <p class="muted" style="font-size:13px;margin-bottom:6px;">
        Latest MOOG market snapshot or historical snapshots.
    </p>
    <ul style="font-size:13px;line-height:1.5;">
        <li>
            <code>/api/market.php</code> – latest snapshot
        </li>
        <li>
            <code>/api/market.php?mode=history&amp;limit=200</code><br>
            Returns last <code>limit</code> rows from <code>mg_market_history</code>
            (newest first, max 1000).
        </li>
    </ul>
</div>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">Holders API</h2>
    <p class="muted" style="font-size:13px;margin-bottom:6px;">
        Top MOOG holders by bag size or a single wallet lookup.
    </p>
    <ul style="font-size:13px;line-height:1.5;">
        <li>
            <code>/api/holders.php</code> – top 100 holders by default
        </li>
        <li>
            <code>/api/holders.php?limit=25</code> – limit results (1–500)
        </li>
        <li>
            <code>/api/holders.php?wallet=YOUR_WALLET</code> – single wallet, with dynamic rank
        </li>
    </ul>
</div>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">Transactions API</h2>
    <p class="muted" style="font-size:13px;margin-bottom:6px;">
        Recent MOOG transactions from <code>mg_moog_tx</code>, with filters similar to Tx History.
    </p>
    <ul style="font-size:13px;line-height:1.5;">
        <li>
            <code>/api/tx.php</code> – last 100 transactions by default
        </li>
        <li>
            <code>/api/tx.php?limit=50</code> – change result size (1–500)
        </li>
        <li>
            <code>/api/tx.php?direction=BUY</code> – filter by <code>BUY</code> or <code>SELL</code>
        </li>
        <li>
            <code>/api/tx.php?wallet=YOUR_WALLET</code> – match wallet in <code>from_wallet</code> or <code>to_wallet</code>
        </li>
        <li>
            <code>/api/tx.php?source=raydium</code> – filter by source (e.g. <code>raydium</code>, <code>birdeye_v3</code>)
        </li>
    </ul>
</div>

<div class="card" style="margin-bottom:16px;">
    <h2 style="margin-top:0;">OG Buyers API</h2>
    <p class="muted" style="font-size:13px;margin-bottom:6px;">
        OG buyer records from <code>mg_og_buyers</code>.
    </p>
    <ul style="font-size:13px;line-height:1.5;">
        <li>
            <code>/api/og_buyers.php</code> – full OG buyers list
        </li>
        <li>
            <code>/api/og_buyers.php?wallet=YOUR_WALLET</code> – single OG buyer row (if any)
        </li>
    </ul>
</div>

<div class="card" style="margin-bottom:20px;">
    <h2 style="margin-top:0;">Notes &amp; Roadmap</h2>
    <ul style="font-size:13px;line-height:1.6;">
        <li>All endpoints are currently <strong>open</strong> on localhost (no API key).</li>
        <li>Responses use a common wrapper: <code>{ ok: true|false, data|error }</code>.</li>
        <li>Errors return an HTTP status (400–500) with an <code>error</code> message.</li>
        <li>Future work:
            <ul>
                <li>Airdrop &amp; OG Rewards APIs</li>
                <li>Per-user API keys &amp; roles</li>
                <li>Public dev documentation on moongoblins.net</li>
            </ul>
        </li>
    </ul>
</div>
