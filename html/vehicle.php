<?php
/**
 * Legacy vehicle detail page - redirects to SPA
 * 
 * The main index.php now handles vehicle detail views via client-side routing.
 * This file provides a fallback redirect for old links (vehicle.php?stock=XXX).
 */
$stock = isset($_GET['stock']) ? $_GET['stock'] : '';

if ($stock) {
    // Redirect to the SPA vehicle view
    header('Location: /' . urlencode($stock), true, 301);
    exit;
}

// If no stock provided, redirect to inventory
header('Location: /', true, 302);
exit;
