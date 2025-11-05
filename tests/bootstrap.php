<?php
/**
 * PHPUnit Bootstrap for Subticket Manager Plugin
 *
 * This bootstrap file sets up the osTicket test environment for integration tests.
 * It loads necessary osTicket core files and initializes database connections.
 */

// Ensure error reporting is enabled for tests
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define PHPUnit running flag to prevent echo in tests
define('PHPUNIT_RUNNING', true);

// Define osTicket paths (mock for testing)
define('OSTSCPINC', dirname(__DIR__) . '/tests/mocks/');
define('INCLUDE_DIR', OSTSCPINC);
define('OSTADMININC', true); // Required for some osTicket functionality

// Load Composer autoloader
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// Mock osTicket Plugin base class
if (!class_exists('Plugin')) {
    /**
     * Mock Plugin base class for testing
     */
    class Plugin {
        protected $config_class;

        public function isSingleton() {
            return true;
        }

        public function getNumInstances() {
            return 0;
        }

        public function getName() {
            return 'Test Plugin';
        }

        public function getId() {
            return 1;
        }

        public function addInstance($vars, &$errors) {
            return true;
        }

        public function bootstrap() {
        }

        public function enable($errors = array()) {
            return true;
        }

        public function uninstall(&$errors) {
            return true;
        }

        public function getConfig(?PluginInstance $instance = null, $defaults = array()) {
            // Return mock config object
            return new class {
                public function get($key) {
                    return false;
                }
            };
        }

        public function isCompatible() {
            return true;
        }
    }
}

// Mock Signal class for osTicket events with tracking
if (!class_exists('Signal')) {
    class Signal {
        public static function connect($event, $callback) {
            global $__test_signals;

            // Track signal registration for tests
            if (!isset($__test_signals)) {
                $__test_signals = array();
            }

            // Store signal and its callback
            $__test_signals[$event] = $callback;

            return true;
        }
    }
}

// Mock PluginInstance class
if (!class_exists('PluginInstance')) {
    class PluginInstance {
    }
}

// Mock PluginConfig base class
if (!class_exists('PluginConfig')) {
    class PluginConfig {
        public function get($key) {
            return null;
        }

        public function getOptions() {
            return array();
        }

        public static function translate() {
            return array();
        }
    }
}

// Mock Ticket class for osTicket
if (!class_exists('Ticket')) {
    class Ticket {
        private $data = array();

        public function __construct($data = array()) {
            $this->data = $data;
        }

        public static function lookup($id) {
            // Return null (ticket not found) by default
            // Tests can override by mocking database queries
            return null;
        }

        public function getId() {
            return $this->data['ticket_id'] ?? null;
        }

        public function getNumber() {
            return $this->data['number'] ?? null;
        }

        public function getSubject() {
            return $this->data['subject'] ?? null;
        }

        public function getStatus() {
            return $this->data['status'] ?? null;
        }

        public function getParentId() {
            return $this->data['ticket_pid'] ?? null;
        }
    }
}

// Mock TicketStatus class for osTicket
if (!class_exists('TicketStatus')) {
    class TicketStatus {
        private $name;

        public function __construct($name = 'Unknown') {
            $this->name = $name;
        }

        public static function lookup($id) {
            // Return mock status with default name
            return new self('Open');
        }

        public function getName() {
            return $this->name;
        }
    }
}

// Mock osTicket database functions if running in isolation
if (!function_exists('db_query')) {
    /**
     * Mock db_query for unit tests with sequential result support
     *
     * This function checks for test-specific mocked results first,
     * then falls back to empty result.
     */
    function db_query($query, $params = array()) {
        global $__test_db_queries, $__test_mock_results, $__test_mock_index;

        // Track query for assertions
        $__test_db_queries[] = array('query' => $query, 'params' => $params);

        // Return mocked result if available
        if (isset($__test_mock_results) && isset($__test_mock_results[$__test_mock_index])) {
            $result = $__test_mock_results[$__test_mock_index];
            $__test_mock_index++;

            // Boolean result (for UPDATE/INSERT)
            if (is_bool($result)) {
                return $result;
            }

            // Array result (for SELECT)
            if (is_array($result)) {
                $mockResult = new MockDbResult();
                return $mockResult->setData($result);
            }
        }

        // Default: return empty result
        $mockResult = new MockDbResult();
        return $mockResult->setData(array());
    }
}

if (!function_exists('db_num_rows')) {
    function db_num_rows($result) {
        if ($result === false || $result === null) {
            return 0;
        }
        if (is_bool($result)) {
            return $result ? 1 : 0;
        }
        return $result->num_rows;
    }
}

if (!function_exists('db_fetch_array')) {
    function db_fetch_array($result) {
        if ($result === false || $result === null || is_bool($result)) {
            return null;
        }
        return $result->fetch();
    }
}

if (!function_exists('db_error')) {
    function db_error() {
        return 'Mock DB error';
    }
}

if (!function_exists('db_input')) {
    /**
     * Mock db_input for escaping SQL values (osTicket function)
     *
     * In real osTicket, this escapes values for SQL queries.
     * In tests, we just cast to string since we're mocking the DB anyway.
     *
     * @param mixed $value Value to escape
     * @return string Escaped value
     */
    function db_input($value) {
        // Simple mock: just convert to string and escape single quotes
        return addslashes((string)$value);
    }
}

/**
 * Mock database result class for unit tests
 */
class MockDbResult {
    public $num_rows = 0;
    private $data = array();
    private $position = 0;

    public function setData($data) {
        $this->data = $data;
        $this->num_rows = count($data);
        return $this;
    }

    public function fetch() {
        if ($this->position < count($this->data)) {
            return $this->data[$this->position++];
        }
        return null;
    }
}

// Global test database query tracker and mock results
$GLOBALS['__test_db_queries'] = array();
$GLOBALS['__test_mock_results'] = array();
$GLOBALS['__test_mock_index'] = 0;
$GLOBALS['__test_csrf_token'] = null;
$GLOBALS['__test_staff_permission'] = false;
$GLOBALS['__test_signals'] = array();
$GLOBALS['__test_is_staff_area'] = true; // Default: staff area

// Mock global osTicket objects for AJAX tests
// These are used by SubticketController for CSRF and permission validation
$GLOBALS['ost'] = new class {
    public function validateCSRFToken($token) {
        global $__test_csrf_token;
        if (!isset($__test_csrf_token)) {
            return false;
        }
        return $token === $__test_csrf_token;
    }
};

$GLOBALS['thisstaff'] = new class {
    public function isStaff() {
        global $__test_staff_permission;
        return $__test_staff_permission;
    }
};

/**
 * Reset test database query tracker and mock results
 */
function reset_test_db_queries() {
    $GLOBALS['__test_db_queries'] = array();
    $GLOBALS['__test_mock_results'] = array();
    $GLOBALS['__test_mock_index'] = 0;
}

/**
 * Get all queries executed during test
 */
function get_test_db_queries() {
    return $GLOBALS['__test_db_queries'];
}

/**
 * Mock CSRF token validation for tests
 *
 * @param string|null $token Token to validate
 * @return bool True if token is valid
 */
function validate_csrf_token($token) {
    global $__test_csrf_token;

    if (!isset($__test_csrf_token)) {
        return false;
    }

    return $token === $__test_csrf_token;
}

/**
 * Mock staff permission check for tests
 *
 * @return bool True if user has staff permissions
 */
function check_staff_permission() {
    global $__test_staff_permission;
    return $__test_staff_permission;
}

/**
 * Reset test signal registry
 */
function reset_test_signals() {
    $GLOBALS['__test_signals'] = array();
}

/**
 * Get all registered signals during test
 *
 * @return array Array of signal_name => callback
 */
function get_test_signals() {
    return $GLOBALS['__test_signals'] ?? array();
}

// Load SubticketPlugin class for testing (after mocks are loaded)
require_once dirname(__DIR__) . '/class.SubticketPlugin.php';

/**
 * Testable wrapper for SubticketPlugin
 *
 * This class wraps the plugin to allow database function mocking
 * by overriding the actual db_* function calls at runtime.
 * Defined in bootstrap.php to avoid redeclaration in multiple test files.
 */
class TestableSubticketPlugin extends SubticketPlugin {
    // Inherits all methods from SubticketPlugin
    // Database mocking happens via global function overrides
}
