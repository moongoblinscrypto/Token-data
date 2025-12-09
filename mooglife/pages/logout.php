<?php
// mooglife/pages/logout.php
require __DIR__ . '/../includes/auth.php';

mg_logout_user();

// We can't use header() because output already started via header/navbar.
$redirect_to = '?p=login';
?>
<h1>Logged Out</h1>
<div class="card">
    <p>You have been logged out.</p>
    <p class="muted" style="font-size:13px;">
        Redirecting to login…
        <br>
        <a href="<?php echo htmlspecialchars($redirect_to, ENT_QUOTES, 'UTF-8'); ?>">Click here if it doesn&apos;t redirect.</a>
    </p>
</div>
<script>
    setTimeout(function () {
        window.location.href = <?php echo json_encode($redirect_to); ?>;
    }, 600);
</script>
