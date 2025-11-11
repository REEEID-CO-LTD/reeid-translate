<?php
if (!defined('ABSPATH')) exit;

$base = dirname(__DIR__); // .../includes

// 1) Load standard includes if present (idempotent)
foreach (['rt-wc-i18n-lite.php','rt-wc-store.php','[ "rt-wc-i18n-lite.php","rt-wc-store.php","rt-core-shims.php","rt-compat-shims.php" ]'] as $f) {
    $p = $base . '/' . $f;
    if (is_readable($p)) require_once $p;
}

// 2) Load migrated files (natural order, quarantine first)
$mu_dir = $base . '/compat';
if (is_dir($mu_dir)) {
    $files = glob($mu_dir.'/*.php') ?: [];
    usort($files, function($a,$b){
        $qa = (int)preg_match('/quarantine/i', basename($a));
        $qb = (int)preg_match('/quarantine/i', basename($b));
        if ($qa !== $qb) return $qb <=> $qa; // quarantine first
        return strnatcasecmp(basename($a), basename($b));
    });
    foreach ($files as $f) require_once $f;
}

// 3) Early hook space 
add_action('plugins_loaded', static function(){}, 1);
