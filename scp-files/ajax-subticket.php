<?php
/**
 * Subticket AJAX Handler
 *
 * Standalone AJAX endpoint for subticket operations.
 * Deployed to scp/ajax-subticket.php (avoids core file modifications).
 *
 * Usage:
 * - GET  /scp/ajax-subticket.php?action=children&tid=123
 * - GET  /scp/ajax-subticket.php?action=batch_parent_status&ticket_ids=1,2,3
 * - GET  /scp/ajax-subticket.php?action=version            (admins only)
 * - POST /scp/ajax-subticket.php?action=link
 * - POST /scp/ajax-subticket.php?action=unlink
 *
 * @package SubticketManager
 * @license GPL-2.0
 */

define('AJAX_REQUEST', 1);
require('staff.inc.php');

// Clean house
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

if (!defined('INCLUDE_DIR')) {
    Http::response(500, 'Server configuration error');
}

// Check staff access
if (!$thisstaff || !$thisstaff->isStaff()) {
    Http::response(403, 'Access denied');
}

// Resolve plugin directory dynamically -- fixes hardcoded 'subticket-manager' dir name
// (Issue #3: glob finds the plugin regardless of the installed directory name)
$_pluginMatches = glob(INCLUDE_DIR . 'plugins/*/class.SubticketPlugin.php');
if ($_pluginMatches === false || count($_pluginMatches) === 0) {
    Http::response(500, 'SubticketPlugin not found');
}
if (count($_pluginMatches) > 1) {
    error_log('[SUBTICKET-AJAX] Multiple SubticketPlugin installations detected: ' . implode(', ', $_pluginMatches));
    Http::response(500, 'Multiple SubticketPlugin installations detected');
}
$pluginDir = dirname($_pluginMatches[0]);
unset($_pluginMatches);

// Load controller
$controllerFile = $pluginDir . '/ajax/SubticketController.php';
if (!file_exists($controllerFile)) {
    Http::response(500, 'Controller not found');
}

require_once($controllerFile);

// Get plugin instance with info from plugin.php
$pluginFile = $pluginDir . '/class.SubticketPlugin.php';
$pluginInfoFile = $pluginDir . '/plugin.php';

if (!class_exists('SubticketPlugin')) {
    require_once($pluginFile);
}

// Load plugin info and set it on the instance
$pluginInfo = file_exists($pluginInfoFile) ? include($pluginInfoFile) : array();
$plugin = new SubticketPlugin();
$plugin->info = $pluginInfo;  // Set info so getVersion() works

$controller = new SubticketController($plugin);

// Get action
$action = $_REQUEST['action'] ?? '';

// Route to controller method
try {
    switch ($action) {
        case 'version':
            // GET /scp/ajax-subticket.php?action=version
            // Admins only: which plugin version answers here.
            if (!method_exists($thisstaff, 'isAdmin') || !$thisstaff->isAdmin()) {
                Http::response(403, json_encode(array('error' => 'Access denied')), 'application/json');
            }

            Http::response(200, json_encode(array('plugin_version' => $plugin->getVersion())), 'application/json');
            break;

        case 'children':
            // GET /scp/ajax-subticket.php?action=children&tid=123
            $tid = $_GET['tid'] ?? null;
            if (!$tid) {
                Http::response(400, json_encode(array('error' => 'Missing ticket ID')));
            }
            $result = $controller->getChildren($tid);
            Http::response(200, json_encode($result), 'application/json');
            break;

        case 'link':
            // POST /scp/ajax-subticket.php?action=link
            $parentId = $_POST['parent_id'] ?? null;
            $childId = $_POST['child_id'] ?? null;
            $csrfToken = $_POST['__CSRFToken__'] ?? $_POST['csrf_token'] ?? null;

            $result = $controller->linkExistingTicket($childId, $parentId, $csrfToken);
            Http::response(200, json_encode($result), 'application/json');
            break;

        case 'unlink':
            // POST /scp/ajax-subticket.php?action=unlink
            $childId = $_POST['child_id'] ?? null;
            $csrfToken = $_POST['__CSRFToken__'] ?? $_POST['csrf_token'] ?? null;

            $result = $controller->unlinkTicket($childId, $csrfToken);
            Http::response(200, json_encode($result), 'application/json');
            break;

        case 'batch_parent_status':
            // GET /scp/ajax-subticket.php?action=batch_parent_status&ticket_ids=1,2,3
            $ticketIds = $_GET['ticket_ids'] ?? '';
            $result = $controller->getBatchParentStatus($ticketIds);
            Http::response(200, json_encode($result), 'application/json');
            break;

        default:
            Http::response(400, json_encode(array('error' => 'Invalid action')));
    }
} catch (Throwable $e) {
    // Detail goes to the server log only; the client gets a generic message.
    error_log('[SUBTICKET-AJAX] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    Http::response(500, json_encode(array('error' => 'Internal error')), 'application/json');
}
