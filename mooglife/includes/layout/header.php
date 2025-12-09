<?php
// mooglife/includes/layout/header.php
require_once __DIR__ . '/../helpers.php';
require_once __DIR__ . '/../auth.php';

if (!function_exists('ml_register_error_handlers')) {
    function ml_register_error_handlers(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        error_reporting(E_ALL);
        ini_set('display_errors', '1');

        // Handle warnings/notices in a small bar
        set_error_handler(function ($errno, $errstr, $errfile, $errline) {
            if (!(error_reporting() & $errno)) {
                return false;
            }
            echo '<div style="background:#450a0a;color:#fecaca;padding:6px 10px;font-size:12px;">';
            echo 'PHP error: ' . htmlspecialchars($errstr, ENT_QUOTES, 'UTF-8')
               . ' in ' . htmlspecialchars($errfile, ENT_QUOTES, 'UTF-8')
               . ' on line ' . (int)$errline;
            echo '</div>';
            return true;
        });

        // Handle fatals at shutdown
        register_shutdown_function(function () {
            $e = error_get_last();
            if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                echo '<div style="background:#450a0a;color:#fecaca;padding:6px 10px;font-size:12px;">';
                echo 'Fatal error: ' . htmlspecialchars($e['message'], ENT_QUOTES, 'UTF-8')
                   . ' in ' . htmlspecialchars($e['file'], ENT_QUOTES, 'UTF-8')
                   . ' on line ' . (int)$e['line'];
                echo '</div>';
            }
        });
    }
}
ml_register_error_handlers();
?>
<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <link rel="icon" type="image/png" href="assets/img/favicon.png">
    <title>Mooglife Dashboard</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <style>
        body {
            margin:0;
            font-family: Arial, sans-serif;
            background:#05060b;
            color:#e5e7eb;
        }
        .topbar {
            background:#0f172a;
            padding:12px 20px;
            display:flex;
            align-items:center;
            justify-content:space-between;
            border-bottom:1px solid #020617;
        }
        .topbar .title {
            font-size:18px;
            font-weight:bold;
            color:#7fffd4;
        }
        .topbar .sub {
            font-size:12px;
            color:#9ca3af;
        }
        .layout {
            display:flex;
            min-height:calc(100vh - 48px);
        }
        .sidebar {
            width:220px;
            background:#020617;
            padding:15px 10px;
            box-sizing:border-box;
            display:flex;
            flex-direction:column;
            gap:8px;
        }
        .sidebar a {
            display:block;
            padding:8px 10px;
            margin-bottom:4px;
            border-radius:6px;
            font-size:14px;
            color:#e5e7eb;
            text-decoration:none;
        }
        .sidebar a.active,
        .sidebar a:hover {
            background:#1e293b;
            color:#7fffd4;
        }
        .main {
            flex:1;
            padding:20px;
            box-sizing:border-box;
        }
        .cards {
            display:flex;
            flex-wrap:wrap;
            gap:15px;
        }
        .card {
            flex:1 1 180px;
            min-width:180px;
            background:#020617;
            border-radius:10px;
            padding:10px 12px;
            box-shadow:0 0 0 1px #111827;
        }
        .card-label {
            font-size:12px;
            color:#9ca3af;
            margin-bottom:6px;
        }
        .card-value {
            font-size:20px;
            font-weight:bold;
        }
        .card-sub {
            font-size:11px;
            color:#9ca3af;
            margin-top:4px;
        }
        table.data {
            width:100%;
            border-collapse:collapse;
            font-size:13px;
            margin-top:8px;
        }
        table.data th,
        table.data td {
            padding:6px 8px;
            border-bottom:1px solid #111827;
            text-align:left;
        }
        table.data th {
            background:#020617;
            font-size:12px;
            color:#9ca3af;
        }
        table.data tr:nth-child(even) td {
            background:#020617;
        }
        table.data tr:nth-child(odd) td {
            background:#030712;
        }
        input[type=text],
        input[type=number],
        input[type=password],
        select,
        textarea {
            background:#020617;
            border:1px solid #1f2937;
            color:#e5e7eb;
            border-radius:6px;
            padding:6px 8px;
            font-size:13px;
            box-sizing:border-box;
        }
        textarea { resize:vertical; }
        button,
        .btn {
            background:#22c55e;
            color:#020617;
            border:none;
            border-radius:6px;
            padding:6px 10px;
            font-size:13px;
            font-weight:bold;
            cursor:pointer;
            text-decoration:none;
            display:inline-flex;
            align-items:center;
            gap:4px;
        }
        button.secondary,
        .btn.secondary {
            background:#0f172a;
            color:#e5e7eb;
            border:1px solid #1f2937;
        }
        button.danger,
        .btn.danger {
            background:#b91c1c;
            color:#fee2e2;
        }
        .pill {
            display:inline-block;
            padding:2px 6px;
            border-radius:999px;
            background:#111827;
            color:#e5e7eb;
            font-size:10px;
            text-transform:uppercase;
            letter-spacing:0.03em;
        }
        .muted {
            color:#9ca3af;
            font-size:13px;
        }
        .search-row {
            display:flex;
            gap:10px;
            align-items:center;
            margin-bottom:10px;
        }
        .search-row .grow {
            flex:1 1 auto;
        }
        .badge {
            padding:2px 4px;
            border-radius:4px;
            font-size:11px;
            background:#111827;
            color:#e5e7eb;
        }
        .body-cell {
            max-width:650px;
            white-space:pre-wrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        @media (max-width:768px) {
            .layout {
                flex-direction:column;
            }
            .sidebar {
                width:100%;
                flex-direction:row;
                flex-wrap:wrap;
            }
            .main {
                padding:12px;
            }
        }
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
