# Mooglife Local / GoblinsHQ – AI Startup Notes

## 1. Project Overview

This project is a **local dashboard for the MOOG token** (MoonGoblins) running on **WAMP**:

- Stack: **PHP 8**, **MySQL (MariaDB)**, Apache (WampServer).
- DB name: **goblinshq**
- Code root (local): `F:\wamp64\www\mooglife\`
- Purpose: give a clean, single-purpose dashboard for MOOG:
  - Total holders, 24h volume, price
  - Holder list (top 100)
  - OG buyers / OG rewards
  - Airdrops + Tx history
  - X-post bank (social media content)
  - Sync jobs (DexScreener + Birdeye) and cron log
  - Wallet profile pages

External data sources:

- **DexScreener** – price, FDV, liquidity, 24h volume, price change
- **Birdeye** – holders + tx history (holders working, tx still a WIP / rate-limited)

---

## 2. Main Folders & Files

Root: `F:\wamp64\www\mooglife\`

### 2.1 Front controller

- `index.php`
  - Reads `$_GET['p']` (page slug).
  - Includes `includes/layout/header.php`
  - Includes the right file from `pages/`.
  - Includes `includes/layout/footer.php`.

### 2.2 Layout / shared code

- `includes/db.php`
  - **`mg_db()`** – returns a **mysqli** connection to DB `goblinshq`.
  - Sets charset `utf8mb4`.
  - This is the **only** place that should open DB connections.
  - Also defines **`wallet_link($wallet, $label = null, $short = false)`**
    - Returns a clickable link to `?p=wallet&wallet=...`
    - Used everywhere wallets appear.

- `includes/layout/header.php`
  - `<head>` stuff, CSS, base layout, starts `<body>`.
  - Wraps content in main layout container.

- `includes/layout/navbar.php`
  - Left sidebar.
  - Uses `nav_link($slug, $label, $current)` to render menu.
  - Pages:
    - `dashboard`
    - `market`
    - `sync`
    - `holders`
    - `ogbuyers`
    - `ogrewards`
    - `airdrops`
    - `tx`
    - `xposts`
    - `layout` (admin layout tweaks)
    - `settings`
    - `wallet` (via "Jump to wallet…" box)
  - Shows **logo** at top: `/assets/img/logo-mooglife.png`.
  - Contains the **“Jump to wallet…”** form:
    - `method=get`, hidden `p=wallet`, text `name="wallet"`.

- `includes/layout/footer.php`
  - Closes main layout and `<body>` / `<html>`.

- `includes/widgets/`
  - `stats_cards.php` – top stat cards on Dashboard (total holders, 24h vol, price).
  - `mini_holders.php` – “Top 5 holders” table on Dashboard.
  - `mini_tx.php` – recent swaps widget on Dashboard.
  - `sync_cards.php` – cards on Sync page.
  - Other mini widgets as needed.

---

## 3. Pages (in /pages)

Each page assumes **`mg_db()` is available** and that layout header/navbar/footer are already included by `index.php`.

- `dashboard.php`
  - Uses `includes/widgets/stats_cards.php`, `mini_holders.php`, `mini_tx.php`.
  - Shows:
    - Total holders (`mg_moog_holders` count).
    - 24h volume (USD) from `mg_market_cache`.
    - Price (USD) from `mg_market_cache`.
    - Top 5 holders from `mg_moog_holders` joined to `mg_moog_wallets`.
    - Recent swaps from `mg_moog_tx`.

- `market.php`
  - Market history:
    - Reads `mg_market_cache` (DexScreener snapshots).
    - Hero cards: price, FDV, liquidity, SOL price.
    - Chart JS setup for price vs liquidity, SOL price.

- `sync.php`
  - Sync center UI.
  - Shows last sync timestamps for:
    - Market (DexScreener)
    - Holders (Birdeye)
    - Tx history (Birdeye)
  - Provides forms/buttons that `POST` and trigger:
    - `/api/sync_market.php`
    - `/api/sync_holders.php`
    - `/api/sync_tx.php`
  - Also shows recent sync log via `mg_sync_log`.

- `holders.php`
  - Table of the **top 100 holders** from `mg_moog_holders`.
  - Columns: rank, label, wallet (clickable), MOOG amount, %, tags.
  - Summary row at top:
    - Holder count
    - Sum of MOOG in table
    - Average / median, etc (whatever is currently implemented)

- `ogbuyers.php`
  - Planned OG buyer list view from `mg_moog_og_buyers`.
  - Shows: wallet, tier, total bought/sold, eligibility, notes.
  - Useful for future perks / reward decisions.

- `ogrewards.php`
  - Planned OG reward payouts from `mg_moog_og_rewards`.
  - Shows: wallet, amount, status, tx hash, notes.
  - Has form to add new reward records.

- `airdrops.php`
  - Airdrop events table (from relevant airdrop table).
  - Tracks planned/sent airdrops.

- `tx.php`
  - MOOG tx history from `mg_moog_tx`.
  - Filter by wallet, direction, date range (current behavior depends on last code).

- `xposts.php`
  - Social media “post bank” from `mg_x_posts`.
  - Shows counts: total, active, used at least once, never used.
  - Table with:
    - Category
    - Body
    - Tags
    - Active flag
    - Times used
    - Last used / created
    - Copy button (JS copies post text).

- `settings.php`
  - System settings stored in `mg_system_settings`.
  - Fields:
    - Site name
    - Token symbol, mint, decimals
    - Birdeye API key
    - “Enable automatic sync” checkbox
  - Saves to DB and is read by API / cron scripts.

- `layout.php`
  - Admin layout page where widget order and layout can be adjusted (future-facing).

- `wallet.php`
  - **Wallet profile page.**
  - Reads `$_GET['wallet']`.
  - Uses tables:
    - `mg_moog_wallets` (meta: label, type, tags, socials)
    - `mg_moog_holders` (current balance + %)
    - `mg_moog_og_buyers` (OG stats)
    - `mg_moog_og_rewards` (rewards)
    - `mg_moog_tx` (totals and 25 recent swaps)
  - Shows:
    - Current MOOG balance + % of tracked supply
    - Net MOOG flow (in/out) and tx count
    - OG buyer section (tier, totals, first buy, snapshot)
    - OG rewards summary + table
    - Recent swaps table with clickable from/to wallets.

---

## 4. API & Cron Endpoints

All under `/api/` and `/cron/` inside project root.

### 4.1 API

- `api/sync_market.php`
  - Calls DexScreener (token + pair endpoints).
  - Extracts:
    - `priceUsd`
    - `priceNative` (MOOG/SOL)
    - `liquidity.usd`
    - `fdv`
    - `volume.h24`
    - `priceChange.h24`
  - Derives SOL price.
  - Reads holder count from `mg_moog_holders`.
  - Writes to `mg_market_cache` (INSERT … ON DUPLICATE KEY UPDATE).
  - Returns JSON: `{ ok, source, symbol, mint, price_usd, fdv_usd, liquidity_usd, volume24h_usd, change24_pct, holders, sol_price_usd, rows }`.

- `api/sync_holders.php`
  - Calls Birdeye holder API (respecting their `limit` rules).
  - Writes to `mg_moog_holders` (truncate+insert).
  - Also updates `mg_system_settings` for “last holders sync”.
  - Returns JSON with holder count and circ supply used.

- `api/sync_tx.php`
  - Calls Birdeye tx API for MOOG/SOL swaps.
  - Writes into `mg_moog_tx` (currently limited to last ~N trades).
  - Returns `{ ok, symbol, mint, trades_returned, rows_written }`.
  - This endpoint is rate-limited (HTTP 429) by Birdeye; code is built to fail gracefully.

### 4.2 Cron

- `cron/cron_sync_all.php`
  - One-shot script to run all syncs:
    - `sync_market.php`
    - `sync_holders.php`
    - `sync_tx.php` (soft-fails gracefully)
  - Logs each job into `mg_sync_log`:
    - job, ok, step, message, payload_json, duration_ms, created_at
  - Returns JSON: `{ ok, results: { market: {...}, holders: {...}, tx: {...} } }`.

---

## 5. Database Tables (high-level)

_DB name: `goblinshq`._

Only **structure summary**; column names may be richer, but this is enough to reason about code.

- `mg_market_cache`
  - Latest price snapshot (one row per token).
  - Columns: token_symbol, token_mint, price_usd, market_cap_usd, fdv_usd, liquidity_usd,
    volume24h_usd, price_change_24h, holders, sol_price_usd, updated_at.

- `mg_moog_holders`
  - Top MOOG holders (fetched from Birdeye).
  - Columns: wallet (PK), ui_amount (MOOG), percent, rank, label, tags, updated_at.

- `mg_moog_tx`
  - MOOG swap history.
  - Columns: id, block_time, tx_hash, direction (BUY/SELL), amount_moog,
    from_wallet, to_wallet, source, created_at.

- `mg_moog_wallets`
  - Wallet metadata / labels.
  - Columns: wallet (PK), label, type, tags, socials_x, socials_discord,
    socials_telegram, socials_notes, created_at, updated_at.

- `mg_moog_og_buyers`
  - Snapshot of OG buyers.
  - Columns: id, wallet, first_buy_time, first_buy_amount,
    total_bought, total_sold, current_balance,
    buy_tx_count, sell_tx_count,
    is_eligible (0/1), og_tier,
    label_tags, exclude_reason, notes, snapshot_at.

- `mg_moog_og_rewards`
  - Planned / sent OG rewards.
  - Columns: id, wallet, planned_amount, status, tx_hash, notes, created_at, updated_at.

- `mg_x_posts`
  - Social-media post bank.
  - Columns: id, category, body, tags, is_active, times_used, first_used_at, last_used_at, created_at.

- `mg_sync_log`
  - Records each sync or cron operation.
  - Columns: id, job, ok, step, message, payload_json, duration_ms, created_at.

- `mg_system_settings`
  - Key/value config.
  - Columns: key, value, updated_at.
  - Keys used: `site_name`, `token_symbol`, `token_mint`, `token_decimals`,
    `birdeye_api_key`, `sync_enabled`, `last_market_sync`, `last_holders_sync`, `last_tx_sync`.

(If some table names differ slightly, assume the **prefix** is `mg_` and logic is the same.)

---

## 6. Conventions For Any Future AI

When writing new code for this project, always assume:

1. **DB access**
   - Use `mg_db()` from `includes/db.php`.
   - Do **not** create new manual mysqli connections.
   - Use prepared statements where possible.

2. **Wallet links**
   - To show a clickable wallet, use:
     ```php
     echo wallet_link($wallet, $labelOrNull, true);
     ```
   - Never hard-code `?p=wallet&wallet=...` everywhere.

3. **Page routing**
   - All user-facing pages live in `/pages/*.php`.
   - Accessed via `index.php?p=slug`.
   - New pages should be added to:
     - `pages/yourpage.php`
     - `includes/layout/navbar.php` via `nav_link()`.

4. **Styling**
   - Reuse existing CSS classes (`card`, `card-label`, `card-value`, `data` tables, `pill`, `pill-green`, `pill-red`, etc.).
   - Keep dark theme consistent.

5. **Sync / external APIs**
   - All external calls live under `/api/`.
   - Use CURL with:
     - Reasonable timeouts
     - User-Agent header `"MoogLifeBot/1.0 (+https://moongoblins.net)"`.
   - Return JSON with an `ok` boolean plus details.

6. **Cron / logging**
   - Any automated multi-step job should:
     - Live in `/cron/`
     - Log into `mg_sync_log` with job name and JSON payload.

7. **Safety**
   - Never assume extra columns that don’t exist.
   - If a new column is needed, **explicitly say**: “we must ALTER TABLE X to add column Y” instead of silently querying non-existent fields.

---

## 7. What This AI Is Allowed To Change

Safe areas for future AI edits:

- New widgets under `includes/widgets/`.
- New pages under `pages/`.
- Improvements to `wallet.php`, `holders.php`, `market.php`, etc.
- New API endpoints under `/api/`.
- Cron helpers under `/cron/`.
- CSS tweaks inside shared stylesheet / header.

Areas to avoid drastic changes without explicit request:

- `includes/db.php` (except adding helper functions).
- Base routing in `index.php`.
- Table names and core schema unless the user **explicitly says** we’re altering DB.

---

If you (AI) understand this file, you should be able to:

- Read/modify any existing page without guessing DB names.
- Add new pages and widgets cleanly.
- Build new reports (e.g., wallet tier analytics, airdrop summaries) using the `goblinshq` schema.
- Keep everything consistent with the existing Mooglife Local dashboard.
