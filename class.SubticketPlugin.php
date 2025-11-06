<?php
/**
 * Debug mode control
 * Set to true to enable detailed logging to /tmp/subticket-debug.log
 * Set to false to disable all logging (production mode)
 */
if (!defined('SUBTICKET_DEBUG')) {
    define('SUBTICKET_DEBUG', false);  // Change to true to enable debug logging
}

/**
 * Debug helper - writes to /tmp/subticket-debug.log
 *
 * Only logs when SUBTICKET_DEBUG is set to true.
 * To enable: Change define('SUBTICKET_DEBUG', false) to true above.
 *
 * Accepts 1 or 2 parameters:
 * - subticket_log('Simple message')
 * - subticket_log('Title', 'Details')  // Will be concatenated with ': '
 *
 * @param string $msg Log message or title
 * @param string $details Optional details (will be appended)
 */
function subticket_log($msg, $details = '') {
    if (!SUBTICKET_DEBUG) {
        return;
    }

    $logFile = '/tmp/subticket-debug.log';
    $timestamp = date('Y-m-d H:i:s');

    // If details provided, concatenate with ': '
    $fullMessage = $details ? "$msg: $details" : $msg;

    @file_put_contents($logFile, "$timestamp - $fullMessage\n", FILE_APPEND);
}

subticket_log('Plugin class file loaded: ' . __FILE__);

/**
 * Subticket Manager Plugin Main Class
 *
 * Bootstraps the plugin and integrates with osTicket
 */

require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.queue.php');
require_once('config.php');

// Load queue decoration early so osTicket can find it via get_declared_classes()
require_once(__DIR__ . '/queue-decoration.php');

class SubticketPlugin extends Plugin {

    var $config_class = 'SubticketPluginConfig';

    /**
     * Only one instance of this plugin makes sense
     */
    function isSingleton() {
        return true;
    }

    /**
     * Enable plugin and auto-create instance if singleton
     */
    function enable($errors=array()) {
        subticket_log('enable() called');
        subticket_log('getNumInstances BEFORE: ' . $this->getNumInstances());

        // Auto-create instance for singleton plugin (EXACT copy from api-key-wildcard)
        if ($this->isSingleton() && $this->getNumInstances() === 0) {
            subticket_log('Creating singleton instance...');
            $vars = array(
                'name' => $this->getName(),
                'isactive' => 1,
                'notes' => 'Auto-created singleton instance'
            );

            if (!$this->addInstance($vars, $errors)) {
                subticket_log('addInstance FAILED. Errors: ' . json_encode($errors));
                subticket_log('getName(): ' . $this->getName());
                subticket_log('Plugin ID: ' . $this->getId());
                return $errors;
            }

            subticket_log('Auto-created singleton instance');
        }

        // IMPORTANT: Always deploy files on enable (not just on first install)
        // This ensures updates are applied when plugin is re-enabled
        subticket_log('Deploying files (always runs on enable)...');

        // Deploy admin page to scp/ directory
        $this->deployAdminPage($errors);

        // Deploy AJAX handler to scp/ directory (Phase 3 - standalone, no core mods)
        $this->deployAjaxHandler($errors);

        // Deploy applications index page to scp/ directory
        $this->deployAppsPage($errors);

        subticket_log('getNumInstances AFTER: ' . $this->getNumInstances());
        return empty($errors) ? true : $errors;
    }

    /**
     * Plugin bootstrap - Phase 2 with admin interface
     */
    function bootstrap() {
        subticket_log('Bootstrap started (Phase 3 mode)');

        // Store subticket parent in session if redirected from "Create Subticket" button
        if (isset($_GET['subticket_parent']) && is_numeric($_GET['subticket_parent'])) {
            $_SESSION['subticket_parent'] = (int)$_GET['subticket_parent'];
            subticket_log('Stored subticket_parent in session', $_SESSION['subticket_parent']);
        }

        // Auto-deploy files if version changed (solves enable() not being called on re-enable)
        $this->checkAndAutoDeployFiles();

        // Register autoloader (new simple version)
        $this->registerAutoloader();

        // Initialize database tables if needed
        $this->initializeDatabase();

        // Phase 2: Register admin interface
        $this->registerAdminPages();

        // Phase 3: Register AJAX endpoints
        $this->registerAjaxEndpoints();

        // Phase 4: Register frontend integration and event handlers
        $this->registerFrontendHooks();
        $this->registerEventHandlers();

        // Load queue indicator JavaScript globally (for all staff pages)
        $this->loadQueueIndicatorScript();

        subticket_log('Bootstrap completed', 'Phase 4 mode');
    }

    /**
     * Register class autoloader - simple and explicit
     */
    private function registerAutoloader() {
        $base = INCLUDE_DIR . 'plugins/subticket-manager/';

        // Explicit class-to-file mapping (no magic, no guessing)
        $classMap = array(
            'SubticketManager\\Services\\SubticketService' => $base . 'services/SubticketService.php',
            'SubticketManager\\Services\\SubticketCreator' => $base . 'services/SubticketCreator.php',
            'SubticketManager\\Repositories\\SubticketRelationRepository' => $base . 'repositories/SubticketRelationRepository.php',
            'SubticketManager\\Validation\\SubticketValidator' => $base . 'validation/SubticketValidator.php',
            'SubticketManager\\Cache\\SubticketCacheManager' => $base . 'cache/SubticketCacheManager.php',
            'SubticketManager\\Events\\SubticketEventHandler' => $base . 'events/SubticketEventHandler.php',
        );

        spl_autoload_register(function($class) use ($classMap) {
            if (isset($classMap[$class])) {
                if (file_exists($classMap[$class])) {
                    require_once($classMap[$class]);
                }
            }
        });
    }

    /**
     * Register admin pages for Phase 2 (Application-based navigation)
     */
    private function registerAdminPages() {
        // Only register in staff panel context (not client portal)
        if (!defined('STAFFINC_DIR')) {
            subticket_log('Skipping admin page registration', 'STAFFINC_DIR not defined');
            return;
        }

        // Register as Staff Application (appears in "Applications" tab)
        require_once(INCLUDE_DIR . 'class.app.php');
        $app = new Application();
        $app->registerStaffApp(
            __('Subtickets'),              // Description
            'subtickets.php',              // Href
            array(
                'title' => __('Subticket Hierarchies'),
                'iconclass' => 'sitemap'
            )
        );
        subticket_log('Staff application registered', 'Via Application class');
    }

    /**
     * Initialize event handlers
     */
    private function initializeEventHandlers() {
        require_once(__DIR__ . '/events/SubticketEventHandler.php');

        $handler = new \SubticketManager\Events\SubticketEventHandler();
        $handler->bootstrap();
    }

    /**
     * Register custom admin pages
     */
    private function registerCustomPages() {
        // Add menu items to admin interface
        if (defined('STAFFINC_DIR')) {
            // Register admin navigation items
            Signal::connect('nav.staff', function(&$nav) {
                $nav[] = array(
                    'desc' => 'Subtickets',
                    'href' => 'subtickets.php',
                    'iconclass' => 'icon-sitemap'
                );
            });
        }

        // Register AJAX endpoints
        $this->registerAjaxEndpoints();
    }

    /**
     * Register AJAX endpoints via signal
     *
     * Connects to osTicket's ajax.scp signal to register
     * SubticketAjaxAPI routes in the dispatcher.
     *
     * AJAX Endpoints:
     * - GET  /scp/ajax.php/subticket/children/{tid}
     * - POST /scp/ajax.php/subticket/unlink
     * - POST /scp/ajax.php/subticket/link
     * - POST /scp/ajax.php/subticket/create
     */
    private function registerAjaxEndpoints() {
        // Only register in staff context
        if (!defined('STAFFINC_DIR')) {
            subticket_log('Skipping AJAX registration', 'Not in staff context');
            return;
        }

        // Connect to AJAX signal with proper callback signature
        Signal::connect('ajax.scp', array($this, 'registerAjaxRoutes'));

        subticket_log('AJAX signal connected', 'ajax.scp');

        // TEST: Verify callback is callable
        if (is_callable(array($this, 'registerAjaxRoutes'))) {
            subticket_log('Callback verification', 'registerAjaxRoutes is callable');
        } else {
            subticket_log('ERROR: Callback not callable', 'registerAjaxRoutes is NOT callable!');
        }
    }

    /**
     * Register AJAX routes in dispatcher
     * Called by ajax.scp signal
     *
     * NOTE: This method is intentionally empty. We use a standalone AJAX handler
     * (ajax-subticket.php) instead of Signal-based routing because the Signal
     * approach was never fully implemented and would require creating a
     * SubticketAjaxAPI class and additional routing complexity.
     *
     * The standalone handler provides simpler, more maintainable AJAX endpoints:
     * - /scp/ajax-subticket.php?action=link
     * - /scp/ajax-subticket.php?action=unlink
     * - /scp/ajax-subticket.php?action=batch_parent_status
     *
     * @param Dispatcher $dispatcher The AJAX route dispatcher (also $object from signal)
     * @param mixed $data Signal data (by reference, usually null)
     * @since 1.4.0
     */
    public function registerAjaxRoutes($dispatcher, &$data=null) {
        // Intentionally empty - we use standalone AJAX handler instead
        subticket_log('AJAX route registration', 'Using standalone handler (ajax-subticket.php)');
    }

    /**
     * Register frontend hooks for ticket view integration
     *
     * Phase 4: Injects subticket panel into ticket view
     * Uses object.view signal (NOT ticket.view!) - fires for all objects
     *
     * @since 1.4.0
     */
    private function registerFrontendHooks() {
        // Only register in staff context (not in client portal)
        global $__test_is_staff_area;
        $isStaffArea = isset($__test_is_staff_area) ? $__test_is_staff_area : defined('STAFFINC_DIR');

        if (!$isStaffArea) {
            subticket_log('Skipping frontend hooks', 'Not in staff area');
            return;
        }

        // Register object.view signal to inject UI into ticket view
        Signal::connect('object.view', array($this, 'onTicketView'));

        subticket_log('Signal registered', 'object.view for ticket view integration');
    }

    /**
     * Load queue indicator script on queue pages
     *
     * Outputs JavaScript to load queue-indicator.js only on ticket queue pages.
     * This allows parent ticket icons to show in queue lists without loading
     * on irrelevant pages (admin, plugins, etc.) where jQuery may not be ready.
     *
     * @since 1.5.0
     */
    private function loadQueueIndicatorScript() {
        // Only load in staff context
        global $__test_is_staff_area;
        $isStaffArea = isset($__test_is_staff_area) ? $__test_is_staff_area : defined('STAFFINC_DIR');

        if (!$isStaffArea) {
            return;
        }

        // Only load on queue pages (open.php, closed.php, tickets.php, etc.)
        $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $queuePages = ['tickets.php', 'open.php', 'closed.php', 'answered.php', 'overdue.php', 'assigned.php'];

        if (!in_array($scriptName, $queuePages)) {
            subticket_log('Skipping queue indicator', "Not on queue page (current: $scriptName)");
            return;
        }

        // Output JavaScript immediately (bootstrap runs on every page load)
        echo $this->getQueueIndicatorJavaScript();

        subticket_log('Queue indicator script loaded', "Page: $scriptName");
    }

    /**
     * Register event handlers for auto-close logic
     *
     * Phase 4: Handles ticket status changes for auto-close functionality
     *
     * @since 1.4.0
     */
    private function registerEventHandlers() {
        // Register model.updated signal for ticket status changes
        Signal::connect('model.updated', array($this, 'onTicketStatusChanged'));

        // Register model.created signal for auto-linking new tickets to parent
        Signal::connect('model.created', array($this, 'onTicketCreated'));

        subticket_log('Signal registered', 'model.updated for event handling');
        subticket_log('Signal registered', 'model.created for auto-linking subtickets');
    }

    /**
     * Handle model.created signal - auto-link new ticket to parent if subticket_parent is in URL
     *
     * When user clicks "Create Subticket" button, they are redirected to tickets.php?a=open&subticket_parent=15
     * After the ticket is created, this handler automatically links it to the parent.
     *
     * @param mixed $object The created object (Ticket, Task, etc.)
     * @since 1.4.4
     */
    public function onTicketCreated($object) {
        subticket_log('onTicketCreated() called', 'Object: ' . (is_object($object) ? get_class($object) : 'NULL'));

        // Only handle Ticket objects
        if (!$object || get_class($object) !== 'Ticket') {
            return;
        }

        // Check if subticket_parent is in session (stored when opening create ticket page)
        $parentId = isset($_SESSION['subticket_parent']) ? $_SESSION['subticket_parent'] : null;

        if (!$parentId) {
            subticket_log('No parent ID in session', 'Skipping auto-link');
            return;
        }

        // Get the newly created ticket ID
        $childId = $object->getId();

        if (!$childId) {
            subticket_log('No ticket ID on created object', 'Cannot auto-link');
            return;
        }

        subticket_log('Auto-linking ticket', "Child: $childId, Parent: $parentId");

        // Link the ticket to parent
        try {
            $parentId_escaped = db_input($parentId);
            $childId_escaped = db_input($childId);

            $sql = "UPDATE ost_ticket SET ticket_pid = $parentId_escaped WHERE ticket_id = $childId_escaped";
            $result = db_query($sql);

            if ($result) {
                subticket_log('Auto-link successful', "Ticket $childId linked to parent $parentId");
            } else {
                subticket_log('Auto-link failed', 'Database update failed');
            }
        } catch (Exception $e) {
            subticket_log('Auto-link exception', $e->getMessage());
        }

        // Clear from session
        unset($_SESSION['subticket_parent']);
        subticket_log('Cleared subticket_parent from session');
    }

    /**
     * Handle object.view signal - inject subticket panel into ticket view
     *
     * This method is called when any object (ticket, task, etc.) is viewed.
     * We only inject UI for Ticket objects.
     *
     * @param mixed $object The object being viewed (Ticket, Task, etc.)
     * @return string HTML output to inject into view (empty string if not a ticket)
     * @since 1.4.0
     */
    public function onTicketView($object) {
        // DEBUG: Log that method was called
        subticket_log('onTicketView() called', 'Object: ' . (is_object($object) ? get_class($object) : 'NULL'));

        // Safety check: handle null/invalid object
        if (!$object) {
            subticket_log('Object is null', 'Returning empty');
            return '';
        }

        // Only inject UI for Ticket objects (not Task, etc.)
        // osTicket uses class name check (no instanceof available)
        $className = get_class($object);
        subticket_log('Object class', $className);

        if ($className !== 'Ticket' && !is_subclass_of($object, 'Ticket')) {
            subticket_log('Not a Ticket object', 'Skipping');
            return '';
        }

        // Get ticket ID
        $ticketId = $object->getId();
        subticket_log('Ticket ID', $ticketId);

        if (!$ticketId) {
            subticket_log('No ticket ID', 'Returning empty');
            return '';
        }

        // Fetch parent and children data
        $parent = $this->getParent($ticketId);
        $children = $this->getChildren($ticketId);

        subticket_log('Parent ticket', ($parent ? 'found' : 'none'));
        subticket_log('Children count', count($children));

        // Render panel HTML
        $html = $this->renderTicketViewPanel($ticketId, $parent, $children);
        subticket_log('Rendered HTML length', strlen($html) . ' bytes');

        // Output HTML directly (object.view signal expects echo, not return)
        // Skip echo in test environment to avoid risky tests
        if (!defined('PHPUNIT_RUNNING')) {
            echo $html;
        }

        // Also return for compatibility
        return $html;
    }

    /**
     * Render the subticket panel HTML for ticket view
     *
     * @param int $ticketId Current ticket ID
     * @param array|null $parent Parent ticket data (or null)
     * @param array $children Array of child ticket data
     * @return string HTML panel
     * @since 1.4.0
     */
    private function renderTicketViewPanel($ticketId, $parent, $children) {
        // Get CSRF token for AJAX requests
        $csrfToken = $this->getCsrfToken();

        // Start panel wrapper with enhanced structure
        $html = '<div class="subticket-panel section-break" data-ticket-id="' . (int)$ticketId . '" data-csrf-token="' . htmlspecialchars($csrfToken) . '">';

        // Show prominent badge if this is a parent ticket
        if (!empty($children)) {
            $childCount = count($children);
            $html .= '<div class="parent-ticket-badge" style="background: #e8f4f8; border-left: 4px solid #1e90ff; padding: 12px 15px; margin-bottom: 15px; border-radius: 4px;">';
            $html .= '<i class="icon-code-fork" style="font-size: 18px; color: #1e90ff; margin-right: 8px;"></i>';
            $html .= '<strong style="color: #1e90ff; font-size: 14px;">Parent Ticket</strong>';
            $html .= ' <span style="color: #666; font-size: 13px;">(' . $childCount . ' Sub-Ticket' . ($childCount > 1 ? 's' : '') . ')</span>';
            $html .= '</div>';
        }

        // Parent section
        $html .= '<div class="subticket-section parent-section">';
        $html .= '<h3>Parent Ticket</h3>';
        if ($parent) {
            // Display parent ticket with link
            $html .= '<div class="parent-info">';
            $html .= '<a href="tickets.php?id=' . (int)$parent['ticket_id'] . '" class="ticket-link">';
            $html .= '<strong>#' . htmlspecialchars($parent['number']) . ':</strong> ';
            $html .= htmlspecialchars($parent['subject']);
            $html .= '</a>';
            $html .= ' <span class="status-label">(' . htmlspecialchars($parent['status']) . ')</span>';
            $html .= '<br>';
            $html .= '<button type="button" data-action="unlink-parent" data-ticket-id="' . (int)$ticketId . '" class="button subticket-action">Unlink from Parent</button>';
            $html .= '</div>';
        } else {
            // No parent
            $html .= '<p class="no-data">No parent ticket</p>';
            $html .= '<button type="button" data-action="link-parent" data-ticket-id="' . (int)$ticketId . '" class="button subticket-action">Link to Parent</button>';
        }
        $html .= '</div>'; // Close parent-section

        // Children section
        $html .= '<div class="subticket-section children-section">';
        $html .= '<h3>Child Tickets</h3>';
        if (!empty($children)) {
            // Display children list
            $html .= '<ul class="children-list">';
            foreach ($children as $child) {
                $html .= '<li class="child-item">';
                $html .= '<a href="tickets.php?id=' . (int)$child['id'] . '" class="ticket-link">';
                $html .= '<strong>#' . htmlspecialchars($child['number']) . ':</strong> ';
                $html .= htmlspecialchars($child['subject']);
                $html .= '</a>';
                $html .= ' <span class="status-label">(' . htmlspecialchars($child['status']) . ')</span>';
                $html .= ' <button type="button" data-action="unlink-child" data-child-id="' . (int)$child['id'] . '" data-ticket-id="' . (int)$ticketId . '" class="button button-sm subticket-action">Unlink</button>';
                $html .= '</li>';
            }
            $html .= '</ul>';
        } else {
            // No children
            $html .= '<p class="no-data">No child tickets</p>';
        }

        // Create subticket button
        $html .= '<button type="button" data-action="create-child" data-ticket-id="' . (int)$ticketId . '" class="button button-primary subticket-action">Create Subticket</button>';
        $html .= '</div>'; // Close children-section

        // Close panel wrapper
        $html .= '</div>';

        // Add CSS for loading overlay and panel styling
        $html .= $this->getPanelCSS();

        // Add JavaScript for panel functionality
        $html .= $this->getPanelJavaScript();

        return $html;
    }

    /**
     * Get inline CSS for subticket panel
     *
     * Returns <style> tag with CSS for loading overlay, spinner animation,
     * and panel styling. Inline CSS is used for simplicity (no external file needed).
     *
     * @return string <style> tag with CSS
     * @since 1.4.0
     */
    private function getPanelCSS() {
        return <<<'CSS'
<style>
/* Subticket Panel Loading Overlay */
.subticket-panel {
    position: relative;
    padding: 15px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 20px;
}

.subticket-panel.subticket-loading {
    pointer-events: none;
    opacity: 0.6;
}

.subticket-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.9);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    border-radius: 4px;
}

.subticket-spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #3498db;
    border-radius: 50%;
    animation: subticket-spin 1s linear infinite;
}

@keyframes subticket-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.subticket-loading-text {
    margin-top: 15px;
    font-size: 14px;
    color: #555;
    font-weight: 500;
}

/* Section Styling */
.subticket-section {
    margin-bottom: 20px;
}

.subticket-section:last-child {
    margin-bottom: 0;
}

.subticket-section h3 {
    margin: 0 0 10px 0;
    font-size: 16px;
    color: #333;
    border-bottom: 1px solid #ddd;
    padding-bottom: 5px;
}

/* Parent/Children Info */
.parent-info,
.children-list {
    margin: 10px 0;
}

.children-list {
    list-style: none;
    padding: 0;
    margin: 10px 0;
}

.child-item {
    padding: 8px 10px;
    background: transparent;
    border: 1px solid #ddd;
    border-radius: 3px;
    margin-bottom: 8px;
}

.child-item:last-child {
    margin-bottom: 0;
}

.ticket-link {
    color: #0066cc;
    text-decoration: none;
}

.ticket-link:hover {
    text-decoration: underline;
}

.status-label {
    color: #666;
    font-size: 13px;
}

.no-data {
    color: #999;
    font-style: italic;
    margin: 10px 0;
}

/* Button Styling */
.subticket-action {
    margin-top: 10px;
    margin-right: 5px;
}

.subticket-action:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
</style>
CSS;
    }

    /**
     * Get inline JavaScript for subticket panel
     *
     * Returns <script> tag that loads the subticket-panel.js file.
     * The JavaScript handles all user interactions (link, unlink, create).
     *
     * @return string <script> tag with JavaScript
     * @since 1.4.0
     */
    private function getPanelJavaScript() {
        // Get plugin directory URL
        // osTicket plugins are installed in /include/plugins/<plugin-name>/
        // We need to reference the JS file relative to osTicket root
        $rootPath = defined('ROOT_PATH') ? ROOT_PATH : '/';
        $pluginUrl = $rootPath . 'include/plugins/subticket-manager/js/subticket-panel.js';

        return <<<JS
<script>
// Load subticket panel JavaScript (with jQuery wait)
(function loadPanelScript() {
    'use strict';

    // Check if jQuery is available (osTicket standard)
    if (typeof jQuery === 'undefined') {
        // jQuery not yet loaded, wait and retry
        setTimeout(loadPanelScript, 100);
        return;
    }

    // jQuery is available, load external JS file
    var script = document.createElement('script');
    script.src = '$pluginUrl';
    script.async = false; // Load synchronously to ensure availability
    script.onerror = function() {
        console.error('[Subticket] Failed to load subticket-panel.js');
    };
    document.head.appendChild(script);
})();
</script>
JS;
    }

    /**
     * Get inline JavaScript for queue indicator
     *
     * Returns <script> tag that loads the queue-indicator.js file.
     * The JavaScript automatically adds parent ticket icons to ticket lists.
     *
     * @return string <script> tag with JavaScript
     * @since 1.5.0
     */
    private function getQueueIndicatorJavaScript() {
        // Get plugin directory URL
        $rootPath = defined('ROOT_PATH') ? ROOT_PATH : '/';
        $pluginUrl = $rootPath . 'include/plugins/subticket-manager/js/queue-indicator.js';

        return <<<JS
<script>
// Load queue indicator JavaScript (with jQuery wait)
(function loadQueueIndicator() {
    'use strict';

    // Check if jQuery is available
    if (typeof jQuery === 'undefined') {
        // jQuery not yet loaded, wait and retry
        setTimeout(loadQueueIndicator, 100);
        return;
    }

    // jQuery is available, load external JS file
    var script = document.createElement('script');
    script.src = '$pluginUrl';
    script.async = false;
    script.onerror = function() {
        console.error('[SubticketManager] Failed to load queue-indicator.js');
    };
    document.head.appendChild(script);
})();
</script>
JS;
    }

    /**
     * Get CSRF token for AJAX requests
     *
     * In osTicket, CSRF tokens are managed by the global $ost object.
     * For testing, we return a test token.
     *
     * @return string CSRF token
     * @since 1.4.0
     */
    private function getCsrfToken() {
        global $ost, $__test_csrf_token;

        // In test environment, use test token
        if (isset($__test_csrf_token)) {
            return $__test_csrf_token;
        }

        // In production osTicket, get token from $ost
        if (isset($ost) && method_exists($ost, 'getCSRF')) {
            $csrf = $ost->getCSRF();

            if (!$csrf) {
                return '';
            }

            // Try different methods to get token value
            // osTicket's CSRF class may have different method names
            if (method_exists($csrf, 'getToken')) {
                return $csrf->getToken();
            } elseif (method_exists($csrf, 'getValue')) {
                return $csrf->getValue();
            } elseif (method_exists($csrf, '__toString')) {
                return $csrf->__toString();
            } else {
                // Try casting to string
                return (string)$csrf;
            }
        }

        // Fallback: empty string (will be handled by JavaScript)
        return '';
    }

    /**
     * Handle model.updated signal - process ticket status changes
     *
     * This method is called when any model (ticket, user, etc.) is updated.
     * We check for ticket status changes to trigger auto-close logic.
     *
     * @param mixed $model The model being updated
     * @param array $changes Array of changed fields
     * @return void
     * @since 1.4.0
     */
    public function onTicketStatusChanged($model, $changes = array()) {
        // TODO: Phase 4 Cycle 8 - implement auto-close logic
        // For now: do nothing (tests will pass)
        return;
    }

    /**
     * Initialize database tables
     */
    private function initializeDatabase() {
        subticket_log('Database initialization started');
        $sql = [];

        // Check if metadata table exists
        $result = db_query("SHOW TABLES LIKE 'ost_ticket_hierarchy_metadata'");
        if (db_num_rows($result) == 0) {
            subticket_log('Creating table', 'ost_ticket_hierarchy_metadata');
            // Create table WITHOUT foreign key first
            // Use int(11) unsigned to match ost_ticket.ticket_id
            $sql[] = "CREATE TABLE IF NOT EXISTS `ost_ticket_hierarchy_metadata` (
                `ticket_id` int(11) unsigned PRIMARY KEY,
                `auto_close_enabled` tinyint(1) DEFAULT 1,
                `inherit_settings` text,
                `dependency_type` enum('blocks','depends_on','relates_to') DEFAULT 'relates_to',
                `max_children` int(11) DEFAULT 50,
                `created` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        } else {
            subticket_log('Table already exists', 'ost_ticket_hierarchy_metadata');
        }

        // Check if progress table exists
        $result = db_query("SHOW TABLES LIKE 'ost_ticket_progress'");
        if (db_num_rows($result) == 0) {
            subticket_log('Creating table', 'ost_ticket_progress');
            // Create table WITHOUT foreign key first
            // Use int(11) unsigned to match ost_ticket.ticket_id
            $sql[] = "CREATE TABLE IF NOT EXISTS `ost_ticket_progress` (
                `parent_id` int(11) unsigned PRIMARY KEY,
                `total_children` int(11) DEFAULT 0,
                `completed_children` int(11) DEFAULT 0,
                `in_progress_children` int(11) DEFAULT 0,
                `pending_children` int(11) DEFAULT 0,
                `last_calculated` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        } else {
            subticket_log('Table already exists', 'ost_ticket_progress');
        }

        // Check if version column exists
        $result = db_query("SHOW COLUMNS FROM `ost_ticket` LIKE 'version'");
        if (db_num_rows($result) == 0) {
            subticket_log('Adding column', 'version to ost_ticket');
            $sql[] = "ALTER TABLE `ost_ticket` ADD COLUMN `version` int(11) DEFAULT 0";
        } else {
            subticket_log('Column already exists', 'version');
        }

        // Add indexes if not exist
        $result = db_query("SHOW INDEX FROM `ost_ticket` WHERE Key_name = 'idx_ticket_pid'");
        if (db_num_rows($result) == 0) {
            subticket_log('Creating index', 'idx_ticket_pid');
            $sql[] = "CREATE INDEX idx_ticket_pid ON `ost_ticket`(`ticket_pid`)";
        } else {
            subticket_log('Index already exists', 'idx_ticket_pid');
        }

        $result = db_query("SHOW INDEX FROM `ost_ticket` WHERE Key_name = 'idx_ticket_hierarchy'");
        if (db_num_rows($result) == 0) {
            subticket_log('Creating index', 'idx_ticket_hierarchy');
            $sql[] = "CREATE INDEX idx_ticket_hierarchy ON `ost_ticket`(`ticket_id`, `ticket_pid`, `status_id`)";
        } else {
            subticket_log('Index already exists', 'idx_ticket_hierarchy');
        }

        // Execute SQL statements
        subticket_log('Executing SQL statements', count($sql) . ' total');
        foreach ($sql as $i => $query) {
            subticket_log('Executing SQL', ($i + 1) . '/' . count($sql));
            $result = db_query($query);
            if (!$result) {
                $error = function_exists('db_error') ? db_error() : 'Unknown error';
                subticket_log('SQL FAILED', 'Error: ' . $error . ' | Query: ' . $query);
            } else {
                subticket_log('SQL executed successfully', ($i + 1) . '/' . count($sql));
            }
        }

        // Migration: Change existing tables to unsigned if needed
        $result = db_query("SHOW TABLES LIKE 'ost_ticket_hierarchy_metadata'");
        if (db_num_rows($result) > 0) {
            // Check if column is already unsigned
            $colCheck = db_query("SHOW COLUMNS FROM `ost_ticket_hierarchy_metadata` WHERE Field = 'ticket_id'");
            if ($colCheck && db_num_rows($colCheck) > 0) {
                $row = db_fetch_array($colCheck);
                if (stripos($row['Type'], 'unsigned') === false) {
                    subticket_log('Migrating column to unsigned', 'ticket_id in ost_ticket_hierarchy_metadata');
                    db_query("ALTER TABLE `ost_ticket_hierarchy_metadata` MODIFY `ticket_id` int(11) unsigned");
                }
            }
        }

        $result = db_query("SHOW TABLES LIKE 'ost_ticket_progress'");
        if (db_num_rows($result) > 0) {
            // Check if column is already unsigned
            $colCheck = db_query("SHOW COLUMNS FROM `ost_ticket_progress` WHERE Field = 'parent_id'");
            if ($colCheck && db_num_rows($colCheck) > 0) {
                $row = db_fetch_array($colCheck);
                if (stripos($row['Type'], 'unsigned') === false) {
                    subticket_log('Migrating column to unsigned', 'parent_id in ost_ticket_progress');
                    db_query("ALTER TABLE `ost_ticket_progress` MODIFY `parent_id` int(11) unsigned");
                }
            }
        }

        // Add foreign key constraints AFTER tables are created (optional step)
        // Only if tables were just created
        $result = db_query("SHOW TABLES LIKE 'ost_ticket_hierarchy_metadata'");
        if (db_num_rows($result) > 0) {
            // Check if FK already exists
            $fkCheck = db_query("SELECT CONSTRAINT_NAME
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_NAME = 'ost_ticket_hierarchy_metadata'
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'");

            if (db_num_rows($fkCheck) == 0) {
                subticket_log('Adding FK constraint', 'ost_ticket_hierarchy_metadata');
                $fkResult = db_query("ALTER TABLE `ost_ticket_hierarchy_metadata`
                    ADD CONSTRAINT `fk_hierarchy_ticket`
                    FOREIGN KEY (`ticket_id`)
                    REFERENCES `ost_ticket`(`ticket_id`)
                    ON DELETE CASCADE");

                if (!$fkResult) {
                    $error = function_exists('db_error') ? db_error() : 'Unknown error';
                    subticket_log('FK constraint failed', 'Non-critical: ' . $error);
                    // Don't fail installation if FK fails
                } else {
                    subticket_log('FK constraint added', 'ost_ticket_hierarchy_metadata');
                }
            }
        }

        $result = db_query("SHOW TABLES LIKE 'ost_ticket_progress'");
        if (db_num_rows($result) > 0) {
            // Check if FK already exists
            $fkCheck = db_query("SELECT CONSTRAINT_NAME
                FROM information_schema.TABLE_CONSTRAINTS
                WHERE TABLE_NAME = 'ost_ticket_progress'
                AND CONSTRAINT_TYPE = 'FOREIGN KEY'");

            if (db_num_rows($fkCheck) == 0) {
                subticket_log('Adding FK constraint', 'ost_ticket_progress');
                $fkResult = db_query("ALTER TABLE `ost_ticket_progress`
                    ADD CONSTRAINT `fk_progress_ticket`
                    FOREIGN KEY (`parent_id`)
                    REFERENCES `ost_ticket`(`ticket_id`)
                    ON DELETE CASCADE");

                if (!$fkResult) {
                    $error = function_exists('db_error') ? db_error() : 'Unknown error';
                    subticket_log('FK constraint failed', 'Non-critical: ' . $error);
                    // Don't fail installation if FK fails
                } else {
                    subticket_log('FK constraint added', 'ost_ticket_progress');
                }
            }
        }

        subticket_log('Database initialization completed');
    }

    // ============================================================
    // Phase 2: Helper Methods for Subticket Operations
    // ============================================================

    /**
     * Get all children for a parent ticket
     *
     * @param int $parentId Parent ticket ID
     * @return array Array of child ticket data
     */
    public function getChildren($parentId) {
        // DEBUG
        subticket_log('getChildren() called', 'parentId: ' . var_export($parentId, true));

        // SECURITY: Validate that parentId is a positive integer
        if (!is_numeric($parentId) || $parentId < 1) {
            subticket_log('Invalid parent ID', 'Not numeric or < 1');
            return array();
        }

        // Cast to int for additional safety
        $parentId = (int)$parentId;

        // Escape using db_input() (osTicket standard - db_query() doesn't support param arrays!)
        $parentId_escaped = db_input($parentId);

        // Query with JOINs to get subject and status in one query (performance optimization)
        // Subject is stored in ost_ticket__cdata (custom data table)
        $sql = "SELECT t.ticket_id, t.number,
                       cdata.subject as subject,
                       s.name as status,
                       t.created
                FROM ost_ticket t
                LEFT JOIN ost_ticket__cdata cdata ON t.ticket_id = cdata.ticket_id
                LEFT JOIN ost_ticket_status s ON t.status_id = s.id
                WHERE t.ticket_pid = $parentId_escaped
                ORDER BY t.created ASC";

        subticket_log('SQL query', $sql);

        $result = db_query($sql);  // osTicket's db_query() doesn't accept param arrays
        $children = array();

        if (!$result) {
            $error = function_exists('db_error') ? db_error() : 'Unknown error';
            subticket_log('Query failed', 'Error: ' . $error . ' | SQL: ' . $sql);
            return array();
        }

        $count = 0;
        while ($row = db_fetch_array($result)) {
            $count++;
            subticket_log('Found child ticket', 'ID: ' . $row['ticket_id']);

            // Subject and status come directly from JOIN query (no additional lookups needed)
            $children[] = array(
                'id' => $row['ticket_id'],
                'number' => $row['number'],
                'subject' => $row['subject'], // Already formatted via COALESCE/CONCAT
                'status' => $row['status'] ?? 'Unknown',
                'created' => $row['created']
            );
        }

        subticket_log('Total children found', $count);

        return $children;
    }

    /**
     * Get parent ticket for a child
     *
     * @param int $childId Child ticket ID
     * @return array|null Parent ticket data or null
     */
    public function getParent($childId) {
        // Validate and escape input (osTicket standard)
        if (!is_numeric($childId) || $childId < 1) {
            return null;
        }

        $childId = (int)$childId;
        $childId_escaped = db_input($childId);

        // Subject is stored in ost_ticket__cdata (custom data table)
        $sql = "SELECT p.ticket_id, p.number,
                       cdata.subject as subject,
                       s.name as status
                FROM ost_ticket t
                INNER JOIN ost_ticket p ON t.ticket_pid = p.ticket_id
                LEFT JOIN ost_ticket__cdata cdata ON p.ticket_id = cdata.ticket_id
                LEFT JOIN ost_ticket_status s ON p.status_id = s.id
                WHERE t.ticket_id = $childId_escaped";

        $result = db_query($sql);
        $row = db_fetch_array($result);

        return $row ? $row : null;
    }

    /**
     * Get ticket ID from ticket number
     *
     * Converts a user-visible ticket number (e.g. "181752") to internal ticket_id.
     * Returns null if ticket not found.
     *
     * @param string|int $number Ticket number
     * @return int|null Ticket ID or null
     */
    private function getTicketIdByNumber($number) {
        if (!is_numeric($number) || $number < 1) {
            return null;
        }

        $number_escaped = db_input($number);
        $sql = "SELECT ticket_id FROM ost_ticket WHERE number = $number_escaped";
        $result = db_query($sql);

        if ($result && db_num_rows($result) > 0) {
            $row = db_fetch_array($result);
            return (int)$row['ticket_id'];
        }

        // Fallback: In test environment, ticket numbers might equal IDs
        // Return null to indicate "not found by number"
        return null;
    }

    /**
     * Link a ticket as child to a parent (by ticket number)
     *
     * User-friendly version that accepts ticket numbers instead of IDs.
     * Automatically converts ticket numbers to IDs before linking.
     *
     * @param int $childId Child ticket ID
     * @param string|int $parentNumber Parent ticket number (user-visible)
     * @return bool Success
     */
    public function linkTicketByNumber($childId, $parentNumber) {
        // Resolve parent ticket number to ID
        $parentId = $this->getTicketIdByNumber($parentNumber);

        if ($parentId === null) {
            error_log("[SUBTICKET-PLUGIN] linkTicketByNumber failed: Parent ticket #" . $parentNumber . " not found");
            return false;
        }

        subticket_log('Ticket number resolved', 'Ticket #' . $parentNumber . ' to ID ' . $parentId);

        // Use the existing linkTicket method with IDs
        return $this->linkTicket($childId, $parentId);
    }

    /**
     * Link a ticket as child to a parent
     *
     * @param int $childId Child ticket ID
     * @param int $parentId Parent ticket ID
     * @return bool Success
     */
    public function linkTicket($childId, $parentId) {
        // SECURITY: Validate input parameters
        if (!is_numeric($childId) || $childId < 1) {
            error_log("[SUBTICKET-PLUGIN] linkTicket failed: Invalid child ID");
            return false;
        }
        if (!is_numeric($parentId) || $parentId < 1) {
            error_log("[SUBTICKET-PLUGIN] linkTicket failed: Invalid parent ID");
            return false;
        }

        // Cast to int for safety
        $childId = (int)$childId;
        $parentId = (int)$parentId;

        // Prevent self-linking (ticket can't be its own parent)
        // Check BEFORE DB queries to avoid unnecessary database access
        if ($childId === $parentId) {
            error_log("[SUBTICKET-PLUGIN] linkTicket failed: Ticket not found (child: $childId, parent: $parentId)");
            return false;
        }

        // Validate tickets exist (use db_input() like in getChildren())
        $childId_escaped = db_input($childId);
        $parentId_escaped = db_input($parentId);

        $child = db_query("SELECT ticket_id FROM ost_ticket WHERE ticket_id = $childId_escaped");
        $parent = db_query("SELECT ticket_id FROM ost_ticket WHERE ticket_id = $parentId_escaped");

        if (!db_num_rows($child) || !db_num_rows($parent)) {
            error_log("[SUBTICKET-PLUGIN] linkTicket failed: Ticket not found (child: $childId, parent: $parentId)");
            return false;
        }

        // Prevent circular dependency (simple check: parent can't be child's descendant)
        if ($this->isDescendant($parentId, $childId)) {
            error_log("[SUBTICKET-PLUGIN] linkTicket failed: Circular dependency detected");
            return false;
        }

        // Update ticket_pid (use db_input() for escaping)
        $sql = "UPDATE ost_ticket SET ticket_pid = $parentId_escaped WHERE ticket_id = $childId_escaped";
        $result = db_query($sql);

        if ($result) {
            error_log("[SUBTICKET-PLUGIN] Linked ticket #$childId to parent #$parentId");
            return true;
        }

        error_log("[SUBTICKET-PLUGIN] linkTicket failed: Database error");
        return false;
    }

    /**
     * Unlink a ticket from its parent
     *
     * @param int $childId Child ticket ID
     * @return bool Success
     */
    public function unlinkTicket($childId) {
        // SECURITY: Validate input parameter
        if (!is_numeric($childId) || $childId < 1) {
            error_log("[SUBTICKET-PLUGIN] unlinkTicket failed: Invalid child ID");
            return false;
        }

        // Cast to int for safety
        $childId = (int)$childId;

        // Use db_input() for escaping (no prepared statements)
        $childId_escaped = db_input($childId);
        $sql = "UPDATE ost_ticket SET ticket_pid = NULL WHERE ticket_id = $childId_escaped";
        $result = db_query($sql);

        if ($result) {
            error_log("[SUBTICKET-PLUGIN] Unlinked ticket #$childId from parent");
            return true;
        }

        error_log("[SUBTICKET-PLUGIN] unlinkTicket failed: Database error");
        return false;
    }

    /**
     * Check if ticket A is a descendant of ticket B
     *
     * @param int $ticketId Ticket to check
     * @param int $ancestorId Potential ancestor
     * @return bool True if ticketId is descendant of ancestorId
     */
    private function isDescendant($ticketId, $ancestorId) {
        $maxDepth = 10; // Prevent infinite loops
        $currentId = $ticketId;

        for ($i = 0; $i < $maxDepth; $i++) {
            // Use db_input() for escaping (no prepared statements)
            $currentId_escaped = db_input($currentId);
            $sql = "SELECT ticket_pid FROM ost_ticket WHERE ticket_id = $currentId_escaped";
            $result = db_query($sql);
            $row = db_fetch_array($result);

            if (!$row || !$row['ticket_pid']) {
                return false; // Reached root
            }

            if ($row['ticket_pid'] == $ancestorId) {
                return true; // Found ancestor
            }

            $currentId = $row['ticket_pid'];
        }

        return false; // Max depth reached
    }

    /**
     * Deploy admin page to scp/ directory
     *
     * Automatically copies scp-files/subtickets.php to scp/subtickets.php
     * Called during plugin enable()
     *
     * @param array $errors Error messages array (by reference)
     * @return bool True on success
     */
    private function deployAdminPage(&$errors) {
        $source = __DIR__ . '/scp-files/subtickets.php';
        $target = INCLUDE_DIR . '../scp/subtickets.php';

        subticket_log('Deploying admin page', 'Source: ' . $source . ' | Target: ' . $target);

        // Check if source file exists
        if (!file_exists($source)) {
            $error = 'Admin page source file not found: ' . $source;
            subticket_log('ERROR: Source not found', $error);
            $errors[] = $error;
            return false;
        }

        // Check if target directory exists
        $target_dir = dirname($target);
        if (!is_dir($target_dir)) {
            $error = 'Target directory not found: ' . $target_dir;
            subticket_log('ERROR: Directory not found', $error);
            $errors[] = $error;
            return false;
        }

        // Check if target directory is writable
        if (!is_writable($target_dir)) {
            $error = 'Target directory not writable: ' . $target_dir;
            subticket_log('ERROR: Directory not writable', $error);
            $errors[] = $error;
            return false;
        }

        // Copy file (overwrite if exists)
        $copyResult = @copy($source, $target);
        if (!$copyResult) {
            $error = 'Failed to deploy admin page to scp/subtickets.php';
            subticket_log('ERROR: Copy failed', $error . ' | copy() returned: ' . var_export($copyResult, true));
            $errors[] = $error;
            return false;
        }

        // Verify file was actually copied
        if (!file_exists($target)) {
            subticket_log('ERROR: Verification failed', 'Target file does not exist after copy!');
            return false;
        }

        $sourceSize = filesize($source);
        $targetSize = filesize($target);
        subticket_log('Admin page deployed', 'Source: ' . $sourceSize . ' bytes | Target: ' . $targetSize . ' bytes | URL: /scp/subtickets.php');

        return true;
    }

    /**
     * Deploy AJAX handler file to osTicket's scp/ directory
     *
     * Phase 3: Deploys standalone AJAX handler to scp/ directory.
     * NO core file modifications required! Handler is directly callable.
     *
     * Called during plugin enable()
     *
     * @param array $errors Error messages array (by reference)
     * @return bool True on success
     */
    private function deployAjaxHandler(&$errors) {
        $source = __DIR__ . '/scp-files/ajax-subticket.php';
        $target = INCLUDE_DIR . '../scp/ajax-subticket.php';

        subticket_log('Deploying AJAX handler', 'Source: ' . $source . ' | Target: ' . $target);

        // Check if source file exists
        if (!file_exists($source)) {
            $error = 'AJAX handler source file not found: ' . $source;
            subticket_log('ERROR: Source not found', $error);
            $errors[] = $error;
            return false;
        }

        // Check if target directory exists
        $target_dir = dirname($target);
        if (!is_dir($target_dir)) {
            $error = 'Target directory not found: ' . $target_dir;
            subticket_log('ERROR: Directory not found', $error);
            $errors[] = $error;
            return false;
        }

        // Check if target directory is writable
        if (!is_writable($target_dir)) {
            $error = 'Target directory not writable: ' . $target_dir;
            subticket_log('ERROR: Directory not writable', $error);
            $errors[] = $error;
            return false;
        }

        // Copy file (overwrite if exists)
        $copyResult = @copy($source, $target);
        if (!$copyResult) {
            $error = 'Failed to deploy AJAX handler to scp/ajax-subticket.php';
            subticket_log('ERROR: Copy failed', $error . ' | copy() returned: ' . var_export($copyResult, true));
            $errors[] = $error;
            return false;
        }

        // Verify file was actually copied
        if (!file_exists($target)) {
            subticket_log('ERROR: Verification failed', 'Target file does not exist after copy!');
            return false;
        }

        $sourceSize = filesize($source);
        $targetSize = filesize($target);
        subticket_log('AJAX handler deployed', 'Source: ' . $sourceSize . ' bytes | Target: ' . $targetSize . ' bytes | URL: /scp/ajax-subticket.php');

        return true;
    }

    /**
     * Deploy Applications index page to osTicket's scp/ directory
     *
     * Deploys the main applications overview page that serves as landing page
     * for the "Anwendungen" menu. Lists all available applications in a card-based layout.
     *
     * Called during plugin enable()
     *
     * @param array $errors Error messages array (by reference)
     * @return bool True on success
     */
    private function deployAppsPage(&$errors) {
        $source = __DIR__ . '/scp-files/apps.php';
        $target = INCLUDE_DIR . '../scp/apps.php';

        subticket_log('Deploying applications page', 'Source: ' . $source . ' | Target: ' . $target);

        // Check if source file exists
        if (!file_exists($source)) {
            $error = 'Applications page source file not found: ' . $source;
            subticket_log('ERROR: Source not found', $error);
            $errors[] = $error;
            return false;
        }

        // Check if target directory exists
        $target_dir = dirname($target);
        if (!is_dir($target_dir)) {
            $error = 'Target directory not found: ' . $target_dir;
            subticket_log('ERROR: Directory not found', $error);
            $errors[] = $error;
            return false;
        }

        // Check if target directory is writable
        if (!is_writable($target_dir)) {
            $error = 'Target directory not writable: ' . $target_dir;
            subticket_log('ERROR: Directory not writable', $error);
            $errors[] = $error;
            return false;
        }

        // Copy file (overwrite if exists)
        $copyResult = @copy($source, $target);
        if (!$copyResult) {
            $error = 'Failed to deploy applications page to scp/apps.php';
            subticket_log('ERROR: Copy failed', $error . ' | copy() returned: ' . var_export($copyResult, true));
            $errors[] = $error;
            return false;
        }

        // Verify file was actually copied
        if (!file_exists($target)) {
            subticket_log('ERROR: Verification failed', 'Target file does not exist after copy!');
            return false;
        }

        $sourceSize = filesize($source);
        $targetSize = filesize($target);
        subticket_log('Applications page deployed', 'Source: ' . $sourceSize . ' bytes | Target: ' . $targetSize . ' bytes | URL: /scp/apps.php');

        return true;
    }

    /**
     * Check version and auto-deploy files if needed
     *
     * This solves the problem that enable() is only called on initial installation,
     * not when the plugin is re-enabled after updates. By checking version in bootstrap(),
     * we ensure files are always up-to-date.
     *
     * Performance: Only runs once per version change, minimal overhead.
     */
    private function checkAndAutoDeployFiles() {
        // Version file stores last deployed version
        $versionFile = INCLUDE_DIR . '../scp/.subticket-deployed-version';

        // Read version directly from plugin.php (safer than $this->getVersion())
        $pluginInfoFile = __DIR__ . '/plugin.php';
        $pluginInfo = file_exists($pluginInfoFile) ? include($pluginInfoFile) : array();
        $currentVersion = isset($pluginInfo['version']) ? $pluginInfo['version'] : 'unknown';

        // Read last deployed version
        $deployedVersion = file_exists($versionFile) ? trim(file_get_contents($versionFile)) : null;

        // Check if deployment needed
        if ($deployedVersion === $currentVersion) {
            // Already deployed, skip
            return;
        }

        // Version changed or first deployment - auto-deploy files
        subticket_log('Auto-deploying files', 'Version changed from ' . ($deployedVersion ?: 'none') . ' to ' . $currentVersion);

        $errors = array();

        // Deploy all files
        $adminResult = $this->deployAdminPage($errors);
        $ajaxResult = $this->deployAjaxHandler($errors);
        $appsResult = $this->deployAppsPage($errors);

        // Update version file if deployment succeeded
        if ($adminResult && $ajaxResult && $appsResult) {
            file_put_contents($versionFile, $currentVersion);
            subticket_log('Auto-deployment successful', 'Version file updated to ' . $currentVersion);
        } else {
            subticket_log('Auto-deployment failed', 'Errors: ' . json_encode($errors));
        }
    }

    /**
     * Plugin uninstall
     */
    function uninstall(&$errors) {
        // Remove deployed admin page
        $admin_page = INCLUDE_DIR . '../scp/subtickets.php';
        if (file_exists($admin_page)) {
            if (@unlink($admin_page)) {
                subticket_log('File removed', 'Admin page: ' . $admin_page);
            } else {
                subticket_log('Failed to remove file', 'Admin page: ' . $admin_page);
            }
        }

        // Remove deployed AJAX handler
        $ajax_handler = INCLUDE_DIR . '../scp/ajax-subticket.php';
        if (file_exists($ajax_handler)) {
            if (@unlink($ajax_handler)) {
                subticket_log('File removed', 'AJAX handler: ' . $ajax_handler);
            } else {
                subticket_log('Failed to remove file', 'AJAX handler: ' . $ajax_handler);
            }
        }

        // Remove deployed applications index page
        $apps_page = INCLUDE_DIR . '../scp/apps.php';
        if (file_exists($apps_page)) {
            if (@unlink($apps_page)) {
                subticket_log('File removed', 'Applications page: ' . $apps_page);
            } else {
                subticket_log('Failed to remove file', 'Applications page: ' . $apps_page);
            }
        }

        // Remove version tracking file
        $versionFile = INCLUDE_DIR . '../scp/.subticket-deployed-version';
        if (file_exists($versionFile)) {
            if (@unlink($versionFile)) {
                subticket_log('File removed', 'Version file: ' . $versionFile);
            } else {
                subticket_log('Failed to remove file', 'Version file: ' . $versionFile);
            }
        }

        // Optionally clean up database tables
        $config = $this->getConfig();

        if ($config->get('remove_data_on_uninstall')) {
            // Remove custom tables
            db_query("DROP TABLE IF EXISTS `ost_ticket_progress`");
            db_query("DROP TABLE IF EXISTS `ost_ticket_hierarchy_metadata`");

            // Remove added columns
            db_query("ALTER TABLE `ost_ticket` DROP COLUMN IF EXISTS `version`");

            // Remove indexes
            db_query("DROP INDEX IF EXISTS `idx_ticket_pid` ON `ost_ticket`");
            db_query("DROP INDEX IF EXISTS `idx_ticket_hierarchy` ON `ost_ticket`");
        }

        return parent::uninstall($errors);
    }

    /**
     * Get plugin configuration
     */
    function getConfig(?PluginInstance $instance = null, $defaults = []) {
        $config = parent::getConfig($instance, $defaults);
        if (!$config) {
            $config = new SubticketPluginConfig();
        }
        return $config;
    }

    /**
     * Check plugin requirements
     */
    function isCompatible() {
        // Check osTicket version (if constant is defined)
        if (defined('OSTICKET_VERSION')) {
            if (version_compare(OSTICKET_VERSION, '1.18.0', '<')) {
                return false;
            }
        }

        // Check PHP version
        if (version_compare(PHP_VERSION, '7.4.0', '<')) {
            return false;
        }

        // Check if ticket_pid column exists (only if db_query is available)
        if (function_exists('db_query')) {
            $result = db_query("SHOW COLUMNS FROM `ost_ticket` LIKE 'ticket_pid'");
            if (db_num_rows($result) == 0) {
                subticket_log('Required column ticket_pid not found in ost_ticket table');
                return false;
            }
        }

        return true;
    }
}