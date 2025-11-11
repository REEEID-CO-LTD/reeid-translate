<?php
// Loads before other MU plugins. Prevents fatals if legacy code calls this.
if (!function_exists('reeid_wc_unified_log')) {
    function reeid_wc_unified_log() { /* no-op */ }
}
