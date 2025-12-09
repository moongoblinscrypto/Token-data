<?php
// mooglife/includes/helpers.php
// Shared helper functions for Mooglife (wallet links, formatting, etc).

if (!function_exists('wallet_link')) {
    /**
     * Render a Solana wallet as a short/labelled link to Solscan.
     *
     * @param string      $wallet Full wallet address
     * @param string|null $label  Optional label (if null, use wallet text itself)
     * @param bool        $short  If true, shorten text like ABCD…WXYZ
     *
     * @return string HTML <a> element
     */
    function wallet_link(string $wallet, ?string $label = null, bool $short = false): string
    {
        $text = $label ?? $wallet;

        if ($short && strlen($text) > 12) {
            $text = substr($text, 0, 4) . '…' . substr($text, -4);
        }

        $w = htmlspecialchars($wallet, ENT_QUOTES, 'UTF-8');
        $t = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        $url = 'https://solscan.io/account/' . $w;

        return '<a href="' . $url . '" target="_blank" rel="noopener" '
             . 'style="color:#7dd3fc;text-decoration:none;">'
             . $t
             . '</a>';
    }
}
