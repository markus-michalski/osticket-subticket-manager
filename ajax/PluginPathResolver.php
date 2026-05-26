<?php

/**
 * Resolves the plugin installation directory dynamically.
 *
 * Fixes Issue #3: the plugin was hardcoding 'subticket-manager' as the
 * directory name, causing HTTP 500 when installed as 'osticket-subticket-manager'
 * or any other directory name.
 *
 * Note: This file is NOT required by scp-files/ajax-subticket.php at runtime
 * because we don't know the plugin dir until after the glob runs (bootstrap
 * chicken-and-egg). The function here exists to make the resolution algorithm
 * unit-testable. The inline code in ajax-subticket.php uses the same logic.
 *
 * @param string $includeDir osTicket INCLUDE_DIR (trailing slash normalized automatically)
 * @return string|null Absolute path to the plugin directory, or null if not found
 * @throws RuntimeException if multiple SubticketPlugin installations are detected
 */
function subticket_resolve_plugin_dir(string $includeDir): ?string
{
    $includeDir = rtrim($includeDir, '/') . '/';
    $matches = glob($includeDir . 'plugins/*/class.SubticketPlugin.php');

    if ($matches === false || count($matches) === 0) {
        return null;
    }

    if (count($matches) > 1) {
        throw new RuntimeException(
            'Multiple SubticketPlugin installations detected: ' . implode(', ', $matches)
        );
    }

    return dirname($matches[0]);
}
