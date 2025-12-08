<?php
// mooglife/includes/layout/header.php
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
	<link rel="icon" type="image/png" href="assets/img/favicon.png">
    <title>Mooglife Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body { margin:0; font-family: Arial, sans-serif; background:#05060b; color:#eee; }
        .topbar { background:#0f172a; padding:12px 20px; display:flex; align-items:center; justify-content:space-between; }
        .topbar .title { font-size:18px; font-weight:bold; color:#7fffd4; }
        .topbar .sub { font-size:12px; color:#aaa; }
        .layout { display:flex; min-height: calc(100vh - 48px); }
        .sidebar { width:220px; background:#020617; padding:15px 10px; box-sizing:border-box; }
        .sidebar a { display:block; padding:8px 10px; margin-bottom:4px; color:#cbd5f5; text-decoration:none; font-size:14px; border-radius:6px; }
        .sidebar a.active, .sidebar a:hover { background:#1e293b; color:#7fffd4; }
        .main { flex:1; padding:20px; box-sizing:border-box; }
        .cards { display:flex; flex-wrap:wrap; gap:15px; }
        .card { flex:1 1 180px; background:#111522; border-radius:10px; padding:15px; }
        .card-label { font-size:13px; text-transform:uppercase; color:#94a3b8; }
        .card-value { margin-top:6px; font-size:24px; }

        /* Tables & forms */
        table.data { width:100%; border-collapse:collapse; margin-top:15px; font-size:14px; }
        table.data th, table.data td { padding:8px 10px; border-bottom:1px solid #1f2937; text-align:left; }
        table.data th { background:#020617; color:#9ca3af; position:sticky; top:0; z-index:1; }
        table.data tr:nth-child(even){ background:#020617; }
        .pill { padding:2px 8px; border-radius:999px; font-size:11px; background:#1f2937; color:#e5e7eb; }
        .search-row { display:flex; gap:10px; align-items:center; margin-bottom:10px; }
        .search-row input[type="text"] { flex:1; padding:6px 8px; border-radius:6px; border:1px solid #1f2937; background:#020617; color:#e5e7eb; }
        .search-row button { padding:6px 10px; border-radius:6px; border:none; background:#22c55e; color:#020617; cursor:pointer; }
        .search-row button:hover { opacity:.9; }
        h1 { margin-top:0; }
        .muted { color:#9ca3af; font-size:13px; }

        /* X posts extras */
        .body-cell { max-width:650px; white-space:pre-wrap; overflow:hidden; text-overflow:ellipsis; }
        .badge-cat { padding:2px 6px; border-radius:999px; font-size:11px; background:#111827; color:#e5e7eb; }
    </style>
</head>
<body>
<div class="topbar">
    <div>
        <div class="title">Mooglife Local</div>
        <div class="sub">Rebuilt clean on localhost (WAMP)</div>
    </div>
</div>
<div class="layout">
