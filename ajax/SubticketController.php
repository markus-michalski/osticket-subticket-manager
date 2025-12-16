<?php

declare(strict_types=1);

/**
 * Subticket AJAX Controller
 *
 * Handles AJAX requests for subticket operations from the frontend.
 *
 * Phase 3: AJAX Controller Implementation
 * Test-Driven Development: GREEN Phase (minimal implementation)
 */

class SubticketController {
    /**
     * Plugin instance for ticket operations
     */
    private SubticketPlugin $plugin;

    /**
     * Rate limiting configuration
     * Max requests per minute per user (DoS protection)
     */
    const RATE_LIMIT_REQUESTS = 100;
    const RATE_LIMIT_WINDOW = 60;  // seconds

    /**
     * Constructor
     *
     * @param SubticketPlugin $plugin Plugin instance
     */
    public function __construct($plugin) {
        $this->plugin = $plugin;
    }

    /**
     * Check rate limit for current user
     *
     * Implements session-based rate limiting to prevent DoS attacks.
     * Allows up to RATE_LIMIT_REQUESTS requests per RATE_LIMIT_WINDOW seconds.
     *
     * Security: Protects against abuse and automated attacks
     * Performance: Minimal overhead (session check only)
     *
     * @return array|null Error response if rate limit exceeded, null if OK
     */
    private function checkRateLimit() {
        // Start session if not already started (osTicket might do this already)
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }

        $now = time();
        $key = 'subticket_rate_limit';

        // Initialize rate limit data if not set
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = [
                'requests' => [],
                'blocked_until' => 0
            ];
        }

        // Check if user is currently blocked
        if ($now < $_SESSION[$key]['blocked_until']) {
            $remainingTime = $_SESSION[$key]['blocked_until'] - $now;
            subticket_log('Rate limit exceeded', "Blocked for {$remainingTime}s more");

            return $this->errorResponse(
                "Too many requests. Please try again in {$remainingTime} seconds.",
                429  // HTTP 429 Too Many Requests
            );
        }

        // Remove requests outside the time window
        $_SESSION[$key]['requests'] = array_filter(
            $_SESSION[$key]['requests'],
            function($timestamp) use ($now) {
                return ($now - $timestamp) < self::RATE_LIMIT_WINDOW;
            }
        );

        // Check if limit exceeded
        if (count($_SESSION[$key]['requests']) >= self::RATE_LIMIT_REQUESTS) {
            // Block user for 60 seconds
            $_SESSION[$key]['blocked_until'] = $now + 60;

            subticket_log('Rate limit exceeded', 'Blocking user for 60s');

            return $this->errorResponse(
                'Too many requests. Please wait 60 seconds before trying again.',
                429
            );
        }

        // Add current request to tracker
        $_SESSION[$key]['requests'][] = $now;

        subticket_log('Rate limit check passed', count($_SESSION[$key]['requests']) . "/" . self::RATE_LIMIT_REQUESTS);

        return null;  // OK, proceed
    }

    /**
     * Get all children for a parent ticket
     *
     * AJAX endpoint: GET /ajax.php/subticket/children?ticket_id={id}
     *
     * @param int $ticketId Parent ticket ID
     * @return array JSON response with structure:
     *   {
     *     "success": true/false,
     *     "message": "Human-readable message",
     *     "data": [array of child tickets]
     *   }
     */
    public function getChildren($ticketId) {
        // Check rate limit (DoS protection)
        if ($rateLimitError = $this->checkRateLimit()) {
            return $rateLimitError;
        }

        // Validate ticket ID
        if (!$this->isValidPositiveInteger($ticketId)) {
            return $this->errorResponse('Invalid ticket ID');
        }

        // Get children from plugin
        $children = $this->plugin->getChildren($ticketId);

        // Build response
        if (empty($children)) {
            return $this->successResponse('No children found', []);
        }

        return $this->successResponse(
            'Found ' . count($children) . ' children',
            $children
        );
    }

    /**
     * Unlink a child ticket from its parent
     *
     * AJAX endpoint: POST /ajax.php/subticket/unlink
     *
     * @param int $childId Child ticket ID to unlink
     * @param string|null $csrfToken CSRF token for security validation
     * @return array JSON response with structure:
     *   {
     *     "success": true/false,
     *     "message": "Human-readable message",
     *     "data": []
     *   }
     */
    public function unlinkTicket($childId, $csrfToken = null) {
        // Check rate limit (DoS protection)
        if ($rateLimitError = $this->checkRateLimit()) {
            return $rateLimitError;
        }

        // 1. Validate child ticket ID
        if (!$this->isValidPositiveInteger($childId)) {
            return $this->errorResponse('Invalid child ticket ID');
        }

        // 2. Validate CSRF token and permissions
        $securityCheck = $this->validateSecurityRequirements($csrfToken);
        if ($securityCheck !== true) {
            return $securityCheck; // Return error response
        }

        // 3. SECURITY: Validate staff has access to the ticket
        $ticketAccess = $this->validateTicketAccess((int)$childId);
        if ($ticketAccess !== true) {
            return $ticketAccess;
        }

        // 4. Execute unlinkTicket operation
        $result = $this->plugin->unlinkTicket($childId);

        // 5. Return response based on result
        if ($result) {
            return $this->successResponse('Ticket successfully unlinked');
        } else {
            return $this->errorResponse('Failed to unlink ticket');
        }
    }

    /**
     * Link an existing ticket as child to a parent ticket
     *
     * AJAX endpoint: POST /ajax.php/subticket/link
     *
     * @param int $childId Child ticket ID to link
     * @param int $parentId Parent ticket ID
     * @param string|null $csrfToken CSRF token for security validation
     * @return array JSON response with structure:
     *   {
     *     "success": true/false,
     *     "message": "Human-readable message",
     *     "data": []
     *   }
     */
    public function linkExistingTicket($childId, $parentId, $csrfToken = null) {
        // Check rate limit (DoS protection)
        if ($rateLimitError = $this->checkRateLimit()) {
            return $rateLimitError;
        }

        // 1. Validate child ticket ID
        if (!$this->isValidPositiveInteger($childId)) {
            return $this->errorResponse('Invalid child ticket ID');
        }

        // 2. Validate parent ticket ID or number
        if (!$this->isValidPositiveInteger($parentId)) {
            return $this->errorResponse('Invalid parent ticket ID/number');
        }

        // 3. Validate CSRF token and permissions
        $securityCheck = $this->validateSecurityRequirements($csrfToken);
        if ($securityCheck !== true) {
            return $securityCheck; // Return error response
        }

        // 4. SECURITY: Validate staff has access to child ticket
        $childAccess = $this->validateTicketAccess((int)$childId);
        if ($childAccess !== true) {
            return $childAccess;
        }

        // 5. Execute linkTicket operation (by number - user-friendly)
        // User provides ticket NUMBER (visible), not internal ID
        // Plugin handles: number-to-ID conversion, self-linking check,
        // ticket existence, circular dependency
        $result = $this->plugin->linkTicketByNumber($childId, $parentId);

        // 6. Return response based on result
        if ($result) {
            return $this->successResponse('Tickets successfully linked');
        } else {
            return $this->errorResponse('Failed to link tickets');
        }
    }

    /**
     * Create a new ticket as child of an existing parent ticket
     *
     * AJAX endpoint: POST /ajax.php/subticket/create
     *
     * @param int $parentId Parent ticket ID
     * @param string $subject Ticket subject
     * @param int $deptId Department ID
     * @param string $message Ticket message/description
     * @param string|null $csrfToken CSRF token for security validation
     * @return array JSON response with structure:
     *   {
     *     "success": true/false,
     *     "message": "Human-readable message",
     *     "data": {
     *       "ticket_number": "ABC-123-456" (on success)
     *     }
     *   }
     */
    public function createSubticket($parentId, $subject, $deptId, $message, $csrfToken = null) {
        // Check rate limit (DoS protection)
        if ($rateLimitError = $this->checkRateLimit()) {
            return $rateLimitError;
        }

        // 1. Validate parent ticket ID
        if (!$this->isValidPositiveInteger($parentId)) {
            return $this->errorResponse('Invalid parent ticket ID');
        }

        // 2. Validate subject
        if (!$subject || trim($subject) === '') {
            return $this->errorResponse('Invalid subject');
        }

        if (strlen($subject) > 50) {
            return $this->errorResponse('Subject too long (max 50 characters)');
        }

        // 3. Validate department ID
        if (!$this->isValidPositiveInteger($deptId)) {
            return $this->errorResponse('Invalid department ID');
        }

        // 4. Validate message
        if (!$message || trim($message) === '') {
            return $this->errorResponse('Invalid message');
        }

        // 5. Validate CSRF token and permissions
        $securityCheck = $this->validateSecurityRequirements($csrfToken);
        if ($securityCheck !== true) {
            return $securityCheck; // Return error response
        }

        // 6. SECURITY: Validate staff has access to parent ticket
        $parentAccess = $this->validateTicketAccess((int)$parentId);
        if ($parentAccess !== true) {
            return $parentAccess;
        }

        // 7. Create ticket via TicketAPI
        $ticketResult = $this->createTicketViaApi($parentId, $subject, $deptId, $message);

        if (!$ticketResult || !isset($ticketResult['success']) || !$ticketResult['success']) {
            return $this->errorResponse('Failed to create ticket');
        }

        // 8. Link new ticket to parent
        $newTicketId = $ticketResult['ticket_id'];
        $linkResult = $this->plugin->linkTicket($newTicketId, $parentId);

        if (!$linkResult) {
            return $this->errorResponse('Ticket created but linking failed');
        }

        // 9. Return success response with ticket number
        return $this->successResponse(
            'Subticket created successfully',
            ['ticket_number' => $ticketResult['ticket_number']]
        );
    }

    // ============================================================
    // Private Helper Methods for Validation, Security, and Response Building
    // ============================================================

    /**
     * Validate if value is a positive integer
     *
     * Checks if the value is numeric, greater than 0, and a valid integer.
     * Used for ticket ID and department ID validation.
     *
     * @param mixed $value Value to validate
     * @return bool True if valid positive integer, false otherwise
     */
    private function isValidPositiveInteger($value) {
        return $value && is_numeric($value) && $value > 0;
    }

    /**
     * Validate security requirements (CSRF token + staff permissions)
     *
     * Validates both CSRF token and staff permissions in a single check.
     * Reduces code duplication across AJAX endpoints.
     *
     * @param string|null $csrfToken CSRF token from request
     * @return true|array Returns true if valid, error response array if invalid
     */
    private function validateSecurityRequirements($csrfToken) {
        // Validate CSRF token presence
        if (!$csrfToken) {
            return $this->errorResponse('Missing CSRF token');
        }

        // Validate CSRF token value using osTicket's built-in validation
        // Note: We use global $ost object which is available in staff context
        global $ost;
        if (!$ost || !$ost->validateCSRFToken($csrfToken)) {
            return $this->errorResponse('Invalid CSRF token');
        }

        // Check staff permissions (global $thisstaff is set in staff.inc.php)
        global $thisstaff;
        if (!$thisstaff || !$thisstaff->isStaff()) {
            return $this->errorResponse('Permission denied');
        }

        // All security checks passed
        return true;
    }

    /**
     * Validate staff has access to a specific ticket
     *
     * SECURITY: Prevents horizontal privilege escalation by ensuring
     * staff can only operate on tickets in their accessible departments.
     *
     * @param int $ticketId Ticket ID to check
     * @return true|array Returns true if access granted, error response otherwise
     * @since 1.0.3
     */
    private function validateTicketAccess(int $ticketId) {
        global $thisstaff, $__test_ticket_access;

        // In test environment, use mocked result
        if (isset($__test_ticket_access)) {
            return $__test_ticket_access === true ? true : $this->errorResponse('Access denied to this ticket');
        }

        // Get ticket
        if (!class_exists('Ticket')) {
            // In test environment without Ticket class
            return true;
        }

        $ticket = \Ticket::lookup($ticketId);
        if (!$ticket) {
            return $this->errorResponse('Ticket not found');
        }

        // Check department access
        if (!$thisstaff || !method_exists($thisstaff, 'canAccessDept')) {
            // Fallback for test environment
            return true;
        }

        if (!$thisstaff->canAccessDept($ticket->getDeptId())) {
            subticket_log('SECURITY: Staff ' . $thisstaff->getId() . ' denied access to ticket ' . $ticketId);
            return $this->errorResponse('You do not have access to this ticket');
        }

        return true;
    }

    /**
     * Build success response
     *
     * @param string $message Success message
     * @param array $data Optional response data
     * @return array JSON response structure
     */
    private function successResponse($message, $data = []) {
        return [
            'success' => true,
            'message' => $message,
            'data' => $data
        ];
    }

    /**
     * Build error response
     *
     * @param string $message Error message
     * @param array $data Optional error data
     * @return array JSON response structure
     */
    private function errorResponse($message, $data = []) {
        return [
            'success' => false,
            'message' => $message,
            'data' => $data
        ];
    }

    /**
     * Create ticket via osTicket Ticket::create() API
     *
     * Creates a new ticket using osTicket's internal Ticket::create() method.
     * The subticket inherits user information and topic from the parent ticket.
     *
     * This method wraps the osTicket TicketAPI for testability:
     * - In production: Calls osTicket's Ticket::create()
     * - In tests: Uses mocked result via global __test_ticket_api_result
     *
     * @param int $parentTicketId Parent ticket ID (to inherit user/topic from)
     * @param string $subject Ticket subject
     * @param int $deptId Department ID for the new ticket
     * @param string $message Ticket message/description
     * @return array Array with structure:
     *   - success (bool): Whether ticket creation succeeded
     *   - ticket_id (int): New ticket's ID (on success)
     *   - ticket_number (string): New ticket's number (on success)
     *   - error (string): Error message (on failure)
     */
    private function createTicketViaApi($parentTicketId, $subject, $deptId, $message) {
        subticket_log('createTicketViaApi() called', 'parentId=' . $parentTicketId . ', subject=' . $subject . ', deptId=' . $deptId);

        // SECURITY: Additional validation layer (Defense-in-Depth)
        $parentTicketId = (int)$parentTicketId;
        $deptId = (int)$deptId;
        $subject = strip_tags(trim($subject));  // Remove HTML tags
        $message = trim($message);  // Keep formatting but trim whitespace

        // For testing: Check if we have a mock result
        if (isset($GLOBALS['__test_ticket_api_result'])) {
            subticket_log('Using mock test result');
            return $GLOBALS['__test_ticket_api_result'];
        }

        // Production implementation using osTicket's Ticket::create()
        try {
            subticket_log('Getting parent ticket');
            // Get parent ticket to inherit user and topic information
            $parentTicket = $this->getTicketById($parentTicketId);
            if (!$parentTicket) {
                subticket_log('Parent ticket not found', 'ID: ' . $parentTicketId);
                return [
                    'success' => false,
                    'error' => 'Parent ticket not found'
                ];
            }
            subticket_log('Parent ticket found', 'Number: ' . $parentTicket->getNumber());

            // Get current staff member creating the subticket
            subticket_log('Getting current staff');
            $thisstaff = $this->getCurrentStaff();
            if (!$thisstaff) {
                subticket_log('Staff not authenticated');
                return [
                    'success' => false,
                    'error' => 'Staff member not authenticated'
                ];
            }
            subticket_log('Staff found', 'Name: ' . $thisstaff->getName());

            // Build ticket creation variables array
            subticket_log('Building ticket vars');

            // Get topic ID - if parent has no topic (0), get default topic for department
            $topicId = $parentTicket->getTopicId();
            if (!$topicId || $topicId == 0) {
                // Get default topic for this department
                $topicId = $this->getDefaultTopicForDepartment($deptId);
                subticket_log('Using default topic', 'Parent has no topic, default: ' . $topicId);
            }

            $vars = [
                // User information (inherit from parent ticket)
                'name'     => $parentTicket->getName(),
                'email'    => $parentTicket->getEmail(),

                // Ticket content
                'subject'  => $subject,
                'message'  => $message,

                // Department and topic
                'deptId'   => $deptId,
                'topicId'  => $topicId,

                // Additional metadata
                'source'   => 'Staff',  // Ticket created by staff
                'ip'       => $this->getClientIp(),

                // Staff who created the subticket
                'staffId'  => $thisstaff->getId(),

                // Priority and SLA (inherit from parent)
                'priorityId' => $parentTicket->getPriorityId(),
                'sla_id'   => $parentTicket->getSLAId(),

                // Due date (optional - inherit from parent if exists)
                'duedate'  => $parentTicket->getDueDate() ?
                             $parentTicket->getDueDate()->format('m/d/Y') : null,

                // Disable auto-responses (subticket is internal operation)
                'cannedResponseId' => 0,

                // Note about parent relationship
                'note'     => sprintf('Created as subticket of #%s', $parentTicket->getNumber())
            ];

            // Initialize errors array (passed by reference to Ticket::create)
            $errors = [];

            // Create the ticket using osTicket's API
            // Parameters: $vars, &$errors, $origin, $autorespond, $alertstaff
            subticket_log('Calling Ticket::create()');
            $ticket = $this->createTicket(
                $vars,
                $errors,
                'staff',    // Origin: created by staff
                false,      // No autorespond (subticket doesn't need confirmation email)
                false       // No staff alert (internal subticket operation)
            );

            // Check if ticket was created successfully
            if ($ticket && is_object($ticket)) {
                subticket_log('Ticket created successfully', 'Number: ' . $ticket->getNumber());
                return [
                    'success' => true,
                    'ticket_id' => $ticket->getId(),
                    'ticket_number' => $ticket->getNumber()
                ];
            }

            // Ticket creation failed - return error details
            $errorMsg = !empty($errors) ? implode(', ', $errors) : 'Unknown error creating ticket';
            subticket_log('Ticket creation FAILED', $errorMsg);
            return [
                'success' => false,
                'error' => $errorMsg
            ];

        } catch (Exception $e) {
            // Handle any exceptions during ticket creation
            subticket_log('EXCEPTION', $e->getMessage());
            return [
                'success' => false,
                'error' => 'Exception: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Wrapper method to get ticket by ID (for testability)
     *
     * @param int $ticketId Ticket ID
     * @return Ticket|null Ticket object or null if not found
     */
    private function getTicketById($ticketId) {
        // Check if we're in test mode
        if (defined('UNIT_TEST_MODE') && UNIT_TEST_MODE) {
            // Return mock ticket for tests
            return $this->getMockTicket($ticketId);
        }

        // Production: Use osTicket's Ticket class
        if (class_exists('Ticket')) {
            return Ticket::lookup($ticketId);
        }

        return null;
    }

    /**
     * Wrapper method to get current staff member (for testability)
     *
     * @return Staff|null Current staff member or null if not authenticated
     */
    private function getCurrentStaff() {
        // Check if we're in test mode
        if (defined('UNIT_TEST_MODE') && UNIT_TEST_MODE) {
            // Return mock staff for tests
            return $this->getMockStaff();
        }

        // Production: Use global $thisstaff
        global $thisstaff;
        return $thisstaff;
    }

    /**
     * Wrapper method to create ticket via osTicket API (for testability)
     *
     * @param array $vars Ticket data
     * @param array &$errors Errors array (passed by reference)
     * @param string $origin Ticket origin ('web', 'staff', 'api', 'email')
     * @param bool $autorespond Send autoresponse email
     * @param bool $alertstaff Alert staff members
     * @return Ticket|null Created ticket or null on failure
     */
    private function createTicket($vars, &$errors, $origin, $autorespond, $alertstaff) {
        // Check if we're in test mode
        if (defined('UNIT_TEST_MODE') && UNIT_TEST_MODE) {
            // Don't actually create ticket in tests
            return null;
        }

        // Production: Use osTicket's Ticket::create()
        if (class_exists('Ticket')) {
            return Ticket::create($vars, $errors, $origin, $autorespond, $alertstaff);
        }

        return null;
    }

    /**
     * Get default topic ID for a department
     *
     * @param int $deptId Department ID
     * @return int Topic ID (or 1 as fallback)
     */
    private function getDefaultTopicForDepartment($deptId) {
        // Try to get first active topic for this department
        $sql = "SELECT topic_id FROM ost_help_topic
                WHERE dept_id = " . db_input($deptId) . "
                AND isactive = 1
                ORDER BY topic_id ASC
                LIMIT 1";

        $result = db_query($sql);
        if ($result && db_num_rows($result) > 0) {
            $row = db_fetch_array($result);
            return $row['topic_id'];
        }

        // Fallback: Get any active topic
        $sql = "SELECT topic_id FROM ost_help_topic
                WHERE isactive = 1
                ORDER BY topic_id ASC
                LIMIT 1";

        $result = db_query($sql);
        if ($result && db_num_rows($result) > 0) {
            $row = db_fetch_array($result);
            return $row['topic_id'];
        }

        // Last resort: return 1 (hopefully exists)
        return 1;
    }

    /**
     * Get client IP address
     *
     * @return string Client IP address
     */
    private function getClientIp() {
        // Check various headers for IP (considering proxies)
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            return $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            return $_SERVER['REMOTE_ADDR'];
        }
        return '0.0.0.0';
    }

    /**
     * Get batch parent status for multiple tickets
     *
     * Returns parent status (is_parent, child_count) for multiple ticket IDs.
     * Used by queue-indicator.js to show parent icons in ticket lists.
     *
     * @param string $ticketIds Comma-separated list of ticket IDs
     * @return array Success response with parent status data
     */
    public function getBatchParentStatus($ticketIds) {
        // Parse ticket IDs
        $ids = array_filter(array_map('intval', explode(',', $ticketIds)));

        if (empty($ids)) {
            return $this->errorResponse('No valid ticket IDs provided');
        }

        // Limit to max 100 tickets per request (prevent abuse)
        if (count($ids) > 100) {
            return $this->errorResponse('Too many ticket IDs (max 100)');
        }

        // Build query to get child counts for all tickets in one query
        $idsList = implode(',', $ids);

        $sql = "SELECT
                    parent.ticket_id,
                    COUNT(child.ticket_id) as child_count
                FROM ost_ticket parent
                LEFT JOIN ost_ticket child ON parent.ticket_id = child.ticket_pid
                WHERE parent.ticket_id IN ($idsList)
                GROUP BY parent.ticket_id";

        $result = db_query($sql);

        if (!$result) {
            return $this->errorResponse('Database query failed');
        }

        // Build result array
        $data = [];
        while ($row = db_fetch_array($result)) {
            $ticketId = (int)$row['ticket_id'];
            $childCount = (int)$row['child_count'];

            $data[$ticketId] = [
                'is_parent' => $childCount > 0,
                'child_count' => $childCount
            ];
        }

        // Fill in missing tickets (tickets that weren't found in DB)
        foreach ($ids as $id) {
            if (!isset($data[$id])) {
                $data[$id] = [
                    'is_parent' => false,
                    'child_count' => 0
                ];
            }
        }

        return $this->successResponse('Parent status retrieved', $data);
    }

    /**
     * Get mock ticket for testing (simplified ticket object)
     *
     * @param int $ticketId Ticket ID
     * @return object Mock ticket object
     */
    private function getMockTicket($ticketId) {
        $ticket = new \stdClass();
        $ticket->getId = function() use ($ticketId) { return $ticketId; };
        $ticket->getNumber = function() { return 'TEST-' . rand(100, 999); };
        $ticket->getName = function() { return 'Test User'; };
        $ticket->getEmail = function() { return 'test@example.com'; };
        $ticket->getTopicId = function() { return 1; };
        $ticket->getPriorityId = function() { return 2; };
        $ticket->getSLAId = function() { return 1; };
        $ticket->getDueDate = function() { return null; };
        return $ticket;
    }

    /**
     * Get mock staff for testing
     *
     * @return object Mock staff object
     */
    private function getMockStaff() {
        $staff = new \stdClass();
        $staff->getId = function() { return 1; };
        $staff->getName = function() { return 'Test Staff'; };
        $staff->getEmail = function() { return 'staff@example.com'; };
        return $staff;
    }
}
