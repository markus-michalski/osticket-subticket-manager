<?php

declare(strict_types=1);

/**
 * Debug mode control
 * Set to true to enable detailed logging to /tmp/subticket-debug.log
 * Set to false to disable all logging (production mode)
 */
if (!defined('SUBTICKET_DEBUG')) {
    define('SUBTICKET_DEBUG', false);
}

/**
 * Debug helper - writes to /tmp/subticket-debug.log
 *
 * @param string $msg Log message or title
 * @param string $details Optional details
 */
function subticket_log(string $msg, string $details = ''): void
{
    if (!SUBTICKET_DEBUG) {
        return;
    }

    $logFile = '/tmp/subticket-debug.log';
    $timestamp = date('Y-m-d H:i:s');
    $fullMessage = $details ? "$msg: $details" : $msg;

    @file_put_contents($logFile, "$timestamp - $fullMessage\n", FILE_APPEND);
}

subticket_log('Plugin class file loaded: ' . __FILE__);

/**
 * Subticket Manager Plugin Main Class
 *
 * Coordinates all plugin components and handles osTicket lifecycle events.
 * Uses dependency injection for better testability.
 *
 * Architecture:
 * - Asset\AssetDeployer: File deployment to scp/
 * - Config\ConfigCache: Singleton for config caching
 * - Database\DatabaseService: Schema management
 * - Hierarchy\HierarchyService: Parent-child relationships
 * - Signal\TicketEventHandler: Signal handlers
 * - UI\PanelRenderer: HTML rendering
 */

require_once(INCLUDE_DIR . 'class.plugin.php');
require_once(INCLUDE_DIR . 'class.queue.php');
require_once('config.php');

// Load queue decoration early
require_once(__DIR__ . '/queue-decoration.php');

// Load service classes via Composer autoload
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

use SubticketManager\Asset\AssetDeployer;
use SubticketManager\Config\ConfigCache;
use SubticketManager\Database\DatabaseService;
use SubticketManager\Hierarchy\HierarchyService;
use SubticketManager\Signal\TicketEventHandler;
use SubticketManager\UI\PanelRenderer;

class SubticketPlugin extends Plugin
{
    /**
     * Configuration class name
     * Note: Cannot add type - parent class defines without type
     * @var string
     */
    var $config_class = 'SubticketPluginConfig';

    private ?AssetDeployer $assetDeployer = null;
    private ?DatabaseService $databaseService = null;
    private ?HierarchyService $hierarchyService = null;
    private ?TicketEventHandler $eventHandler = null;
    private ?PanelRenderer $panelRenderer = null;

    /**
     * Only one instance of this plugin makes sense
     */
    public function isSingleton(): bool
    {
        return true;
    }

    /**
     * Enable plugin and auto-create instance if singleton
     *
     * @param array<string> $errors
     * @return bool|array<string>
     */
    public function enable($errors = [])
    {
        subticket_log('enable() called');
        subticket_log('getNumInstances BEFORE: ' . $this->getNumInstances());

        // Auto-create instance for singleton plugin
        if ($this->isSingleton() && $this->getNumInstances() === 0) {
            subticket_log('Creating singleton instance...');
            $vars = [
                'name' => $this->getName(),
                'isactive' => 1,
                'notes' => 'Auto-created singleton instance',
            ];

            if (!$this->addInstance($vars, $errors)) {
                subticket_log('addInstance FAILED. Errors: ' . json_encode($errors));
                return $errors;
            }

            subticket_log('Auto-created singleton instance');
        }

        // Initialize services for enable operations
        $this->initializeServices();

        // Deploy files
        subticket_log('Deploying files (always runs on enable)...');
        $this->assetDeployer->deployAll($errors);

        subticket_log('getNumInstances AFTER: ' . $this->getNumInstances());
        return empty($errors) ? true : $errors;
    }

    /**
     * Plugin bootstrap - called on every page load
     */
    public function bootstrap(): void
    {
        subticket_log('Bootstrap started');

        $this->initializeServices();
        $this->populateConfigCache();

        // Store subticket parent from URL (with validation)
        $this->eventHandler->storeParentFromUrl();

        // Auto-deploy files if version changed
        $this->checkAndAutoDeployFiles();

        // Initialize database tables
        $this->databaseService->initialize();

        // Register admin interface
        $this->registerAdminPages();

        // Register frontend hooks
        $this->registerFrontendHooks();

        // Register event handlers
        $this->registerEventHandlers();

        // Load queue indicator script if on queue page
        $this->loadQueueIndicatorScript();

        subticket_log('Bootstrap completed');
    }

    /**
     * Initialize all service instances
     */
    private function initializeServices(): void
    {
        $scpDir = defined('INCLUDE_DIR') ? INCLUDE_DIR . '../scp' : __DIR__ . '/tests/mocks/scp';
        $pluginUrl = $this->getPluginUrl();

        $this->assetDeployer = new AssetDeployer(__DIR__, $scpDir);
        $this->databaseService = new DatabaseService();
        $this->hierarchyService = new HierarchyService();
        $this->panelRenderer = new PanelRenderer($pluginUrl);
        $this->eventHandler = new TicketEventHandler(
            $this->hierarchyService,
            $this->panelRenderer
        );
    }

    /**
     * Populate config cache for signal callbacks
     */
    private function populateConfigCache(): void
    {
        $config = $this->getConfig();

        ConfigCache::getInstance()->populate([
            'auto_close_parent' => $config->get('auto_close_parent') ?? true,
            'cascade_hold' => $config->get('cascade_hold') ?? true,
            'cascade_assignment' => $config->get('cascade_assignment') ?? false,
            'show_children_in_queue' => $config->get('show_children_in_queue') ?? false,
            'require_parent_open' => $config->get('require_parent_open') ?? true,
            'allow_nested_subtickets' => $config->get('allow_nested_subtickets') ?? true,
            'notify_on_auto_close' => $config->get('notify_on_auto_close') ?? true,
            'max_depth' => (int)($config->get('max_depth') ?? 3),
            'max_children' => (int)($config->get('max_children') ?? 50),
        ]);
    }

    /**
     * Register admin pages (Application-based navigation)
     */
    private function registerAdminPages(): void
    {
        if (!defined('STAFFINC_DIR')) {
            subticket_log('Skipping admin page registration', 'STAFFINC_DIR not defined');
            return;
        }

        require_once(INCLUDE_DIR . 'class.app.php');
        $app = new Application();
        $app->registerStaffApp(
            __('Subtickets'),
            'subtickets.php',
            [
                'title' => __('Subticket Hierarchies'),
                'iconclass' => 'sitemap',
            ]
        );
        subticket_log('Staff application registered');
    }

    /**
     * Register frontend hooks for ticket view integration
     */
    private function registerFrontendHooks(): void
    {
        global $__test_is_staff_area;
        $isStaffArea = isset($__test_is_staff_area) ? $__test_is_staff_area : defined('STAFFINC_DIR');

        if (!$isStaffArea) {
            subticket_log('Skipping frontend hooks', 'Not in staff area');
            return;
        }

        Signal::connect('object.view', [$this, 'onTicketView']);
        subticket_log('Signal registered', 'object.view');
    }

    /**
     * Register event handlers
     */
    private function registerEventHandlers(): void
    {
        Signal::connect('model.updated', [$this, 'onTicketStatusChanged']);
        Signal::connect('model.created', [$this, 'onTicketCreated']);
        subticket_log('Signals registered', 'model.updated, model.created');
    }

    /**
     * Load queue indicator script on queue pages
     */
    private function loadQueueIndicatorScript(): void
    {
        global $__test_is_staff_area;
        $isStaffArea = isset($__test_is_staff_area) ? $__test_is_staff_area : defined('STAFFINC_DIR');

        if (!$isStaffArea) {
            return;
        }

        if (!$this->eventHandler->shouldLoadQueueIndicator()) {
            return;
        }

        echo $this->panelRenderer->getQueueIndicatorJavaScript();
        subticket_log('Queue indicator script loaded');
    }

    /**
     * Check version and auto-deploy files if needed
     */
    private function checkAndAutoDeployFiles(): void
    {
        $pluginInfo = $this->getPluginInfo();
        $currentVersion = $pluginInfo['version'] ?? 'unknown';

        if (!$this->assetDeployer->isDeploymentNeeded($currentVersion)) {
            return;
        }

        subticket_log('Auto-deploying files', "Version changed to $currentVersion");

        $errors = [];
        if ($this->assetDeployer->deployAll($errors)) {
            $this->assetDeployer->updateVersionMarker($currentVersion);
            subticket_log('Auto-deployment successful');
        } else {
            subticket_log('Auto-deployment failed', json_encode($errors));
        }
    }

    // =========================================================================
    // Signal Handlers (delegate to TicketEventHandler)
    // =========================================================================

    /**
     * Handle object.view signal
     *
     * @param mixed $object
     * @return string
     */
    public function onTicketView($object): string
    {
        $this->ensureServicesInitialized();
        $html = $this->eventHandler->onTicketView($object, $this->getCsrfToken());

        // Output HTML directly (object.view signal expects echo)
        if (!defined('PHPUNIT_RUNNING')) {
            echo $html;
        }

        return $html;
    }

    /**
     * Handle model.created signal
     *
     * @param mixed $object
     */
    public function onTicketCreated($object): void
    {
        $this->ensureServicesInitialized();
        $this->eventHandler->onTicketCreated($object);
    }

    /**
     * Handle model.updated signal
     *
     * @param mixed $model
     * @param array<string, mixed> $changes
     */
    public function onTicketStatusChanged($model, array $changes = []): void
    {
        $this->ensureServicesInitialized();
        $this->eventHandler->onTicketStatusChanged($model, $changes);
    }

    // =========================================================================
    // Public API (delegate to HierarchyService)
    // =========================================================================

    /**
     * Get all children for a parent ticket
     *
     * @param int $parentId
     * @return array<int, array>
     */
    public function getChildren($parentId): array
    {
        $this->ensureServicesInitialized();
        return $this->hierarchyService->getChildren((int)$parentId);
    }

    /**
     * Get parent ticket for a child
     *
     * @param int $childId
     * @return array|null
     */
    public function getParent($childId): ?array
    {
        $this->ensureServicesInitialized();
        return $this->hierarchyService->getParent((int)$childId);
    }

    /**
     * Link a ticket as child to a parent (by number)
     *
     * @param int $childId
     * @param string|int $parentNumber
     * @return bool
     */
    public function linkTicketByNumber($childId, $parentNumber): bool
    {
        $this->ensureServicesInitialized();
        return $this->hierarchyService->linkTicketByNumber((int)$childId, $parentNumber);
    }

    /**
     * Link a ticket as child to a parent
     *
     * @param int $childId
     * @param int $parentId
     * @return bool
     */
    public function linkTicket($childId, $parentId): bool
    {
        $this->ensureServicesInitialized();
        return $this->hierarchyService->linkTicket((int)$childId, (int)$parentId);
    }

    /**
     * Unlink a ticket from its parent
     *
     * @param int $childId
     * @return bool
     */
    public function unlinkTicket($childId): bool
    {
        $this->ensureServicesInitialized();
        return $this->hierarchyService->unlinkTicket((int)$childId);
    }

    // =========================================================================
    // Helper Methods
    // =========================================================================

    /**
     * Ensure services are initialized (for public API and signal handlers)
     */
    private function ensureServicesInitialized(): void
    {
        if ($this->hierarchyService === null || $this->eventHandler === null) {
            $this->initializeServices();
        }
    }

    /**
     * Get CSRF token for AJAX requests
     */
    private function getCsrfToken(): string
    {
        global $ost, $__test_csrf_token;

        if (isset($__test_csrf_token)) {
            return $__test_csrf_token;
        }

        if (isset($ost) && method_exists($ost, 'getCSRF')) {
            $csrf = $ost->getCSRF();
            if (!$csrf) {
                return '';
            }

            if (method_exists($csrf, 'getToken')) {
                return $csrf->getToken();
            }
            if (method_exists($csrf, 'getValue')) {
                return $csrf->getValue();
            }
            if (method_exists($csrf, '__toString')) {
                return $csrf->__toString();
            }

            return (string)$csrf;
        }

        return '';
    }

    /**
     * Get plugin base URL
     */
    public function getPluginUrl(): string
    {
        $pluginDir = basename(__DIR__);
        $rootPath = defined('ROOT_PATH') ? ROOT_PATH : '/';

        return $rootPath . 'include/plugins/' . $pluginDir;
    }

    /**
     * Get plugin info from plugin.php
     *
     * @return array<string, mixed>
     */
    private function getPluginInfo(): array
    {
        $pluginFile = __DIR__ . '/plugin.php';

        if (!file_exists($pluginFile)) {
            return [];
        }

        return include($pluginFile);
    }

    // =========================================================================
    // Plugin Lifecycle
    // =========================================================================

    /**
     * Plugin uninstall
     *
     * @param array<string> $errors
     * @return bool
     */
    public function uninstall(&$errors): bool
    {
        $this->ensureServicesInitialized();

        // Remove deployed files
        $this->assetDeployer->removeAll();

        // Optionally clean up database
        $config = $this->getConfig();
        if ($config->get('remove_data_on_uninstall')) {
            $this->databaseService->removeAll();
        }

        return parent::uninstall($errors);
    }

    /**
     * Get plugin configuration
     *
     * @param PluginInstance|null $instance
     * @param array<string, mixed> $defaults
     * @return SubticketPluginConfig
     */
    public function getConfig(?PluginInstance $instance = null, $defaults = [])
    {
        $config = parent::getConfig($instance, $defaults);
        if (!$config) {
            $config = new SubticketPluginConfig();
        }
        return $config;
    }

    /**
     * Check plugin requirements
     */
    public function isCompatible(): bool
    {
        if (defined('OSTICKET_VERSION') && version_compare(OSTICKET_VERSION, '1.18.0', '<')) {
            return false;
        }

        if (version_compare(PHP_VERSION, '7.4.0', '<')) {
            return false;
        }

        if (function_exists('db_query')) {
            $result = db_query("SHOW COLUMNS FROM `ost_ticket` LIKE 'ticket_pid'");
            if (db_num_rows($result) == 0) {
                subticket_log('Required column ticket_pid not found');
                return false;
            }
        }

        return true;
    }
}
