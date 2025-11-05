<?php
/**
 * Subticket AJAX Handler
 *
 * Standalone AJAX endpoint for subticket operations.
 * Deployed to scp/ajax-subticket.php (avoids core file modifications).
 *
 * Usage:
 * - GET  /scp/ajax-subticket.php?action=children&tid=123
 * - POST /scp/ajax-subticket.php?action=link
 * - POST /scp/ajax-subticket.php?action=unlink
 * - POST /scp/ajax-subticket.php?action=create
 *
 * @package SubticketManager
 * @author  Claude Code
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

// Load controller
$controllerFile = INCLUDE_DIR . 'plugins/subticket-manager/ajax/SubticketController.php';
if (!file_exists($controllerFile)) {
    Http::response(500, 'Controller not found');
}

require_once($controllerFile);

// Get plugin instance with info from plugin.php
$pluginFile = INCLUDE_DIR . 'plugins/subticket-manager/class.SubticketPlugin.php';
$pluginInfoFile = INCLUDE_DIR . 'plugins/subticket-manager/plugin.php';

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
            // Debug endpoint to verify which version is loaded
            $version = $plugin->getVersion();
            $pluginFile = INCLUDE_DIR . 'plugins/subticket-manager/class.SubticketPlugin.php';
            $handlerFile = __FILE__;

            $result = array(
                'plugin_version' => $version,
                'plugin_file' => $pluginFile,
                'plugin_file_exists' => file_exists($pluginFile),
                'plugin_file_size' => file_exists($pluginFile) ? filesize($pluginFile) : 0,
                'plugin_file_modified' => file_exists($pluginFile) ? date('Y-m-d H:i:s', filemtime($pluginFile)) : null,
                'handler_file' => $handlerFile,
                'handler_file_size' => filesize($handlerFile),
                'handler_file_modified' => date('Y-m-d H:i:s', filemtime($handlerFile)),
                'php_version' => PHP_VERSION,
                'opcache_enabled' => function_exists('opcache_get_status') && opcache_get_status() !== false
            );

            Http::response(200, json_encode($result, JSON_PRETTY_PRINT), 'application/json');
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

        case 'create':
            // POST /scp/ajax-subticket.php?action=create
            $parentId = $_POST['parent_id'] ?? null;
            $subject = $_POST['subject'] ?? null;
            $deptId = $_POST['dept_id'] ?? null;
            $message = $_POST['message'] ?? null;
            $csrfToken = $_POST['__CSRFToken__'] ?? $_POST['csrf_token'] ?? null;

            $result = $controller->createSubticket(
                $parentId,
                $subject,
                $deptId,
                $message,
                $csrfToken
            );
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
} catch (Exception $e) {
    error_log('[SUBTICKET-AJAX] Exception: ' . $e->getMessage());
    Http::response(500, json_encode(array('error' => $e->getMessage())));
}
