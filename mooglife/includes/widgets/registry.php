<?php
// mooglife/includes/widgets/registry.php
// Master list of available widgets.

return [
    'stats_cards' => [
        'section' => 'dashboard',
        'title'   => 'Top Stats (Holders / Volume / Price)',
        'file'    => __DIR__ . '/stats_cards.php',
    ],
    'mini_holders' => [
        'section' => 'dashboard',
        'title'   => 'Top Holders (mini table)',
        'file'    => __DIR__ . '/mini_holders.php',
    ],
    'mini_tx' => [
        'section' => 'dashboard',
        'title'   => 'Recent Tx (mini table)',
        'file'    => __DIR__ . '/mini_tx.php',
    ],
];
