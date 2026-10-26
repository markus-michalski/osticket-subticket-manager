<?php

declare(strict_types=1);

use SubticketManager\Database\Table;
use SubticketManager\Security\TicketAccessGuard;

/**
 * Subticket AJAX Controller
 *
 * Handles AJAX requests for subticket operations from the frontend.
 *
 * Every operation checks, in this order: rate limit, input shape, CSRF token
 * and staff session (for writes), then access to each ticket involved via
 * TicketAccessGuard (osTicket's Ticket::checkStaffPerm). Related tickets the
 * agent may not view are filtered out of read responses.
 */

class SubticketController {
    /**
     * Plugin instance for ticket operations
     */
    private SubticketPlugin $plugin;

    /**
     * Access decisions (department, assignment, role permission)
     */
    private TicketAccessGuard $accessGuard;

    /**
     * Rate limiting configuration
     * Max requests per minute per user (DoS protection)
     */
    const RATE_LIMIT_REQUESTS = 100;
    const RATE_LIMIT_WINDOW = 60;  // seconds

    /**
     * Error message for every denied ticket access
     *
     * One message for "not found" and "no access" so the endpoint does not
     * reveal which ticket ids exist.
     */
    const ACCESS_DENIED_MESSAGE = 'You do not have access to this ticket';

    /**
     * Constructor
     *
     * @param SubticketPlugin $plugin Plugin instance
     * @param TicketAccessGuard|null $accessGuard Access guard (tests inject a stub)
     */
    public function __construct($plugin, ?TicketAccessGuard $accessGuard = null) {
        $this->plugin = $plugin;
        $this->accessGuard = $accessGuard ?? new TicketAccessGuard();
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
     * AJAX endpoint: GET /scp/ajax-subticket.php?action=children&tid={id}
     *
     * The agent must be allowed to view the parent; children the agent may
     * not view are left out of the response.
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

        // SECURITY: the agent must be able to view the parent ticket
        if (!$this->accessGuard->canView((int)$ticketId)) {
            subticket_log('SECURITY: children denied', "ticketId=$ticketId");
            return $this->errorResponse(self::ACCESS_DENIED_MESSAGE);
        }

        // Get children from plugin, keep only those the agent may view
        $children = [];
        foreach ($this->plugin->getChildren($ticketId) as $child) {
            if ($this->accessGuard->canView((int)$child['id'])) {
                $children[] = $child;
            }
        }

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
     * AJAX endpoint: POST /scp/ajax-subticket.php?action=unlink
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

        // 3. SECURITY: the agent must be allowed to link/unlink the child ...
        $childAccess = $this->validateTicketAccess((int)$childId, true);
        if ($childAccess !== true) {
            return $childAccess;
        }

        // 4. ... and the parent it is currently attached to
        $parent = $this->plugin->getParent((int)$childId);
        if ($parent && isset($parent['ticket_id'])) {
            $parentAccess = $this->validateTicketAccess((int)$parent['ticket_id'], true);
            if ($parentAccess !== true) {
                return $parentAccess;
            }
        }

        // 5. Execute unlinkTicket operation
        $result = $this->plugin->unlinkTicket($childId);

        // 6. Return response based on result
        if ($result) {
            return $this->successResponse('Ticket successfully unlinked');
        } else {
            return $this->errorResponse('Failed to unlink ticket');
        }
    }

    /**
     * Link an existing ticket as child to a parent ticket
     *
     * AJAX endpoint: POST /scp/ajax-subticket.php?action=link
     *
     * @param int $childId Child ticket ID to link
     * @param int|string $parentId Parent ticket number (as shown to agents)
     * @param string|null $csrfToken CSRF token for security validation
     * @return array JSON response with structure:
     *   {
     *     "success": true/false,
     *     "message": "Human-readable message",
     *     "data": []
     *   }
     */
    public function linkExistingTicket($childId, $parentId, $csrfToken = null) {
        subticket_log('linkExistingTicket called', "childId=$childId, parentId=$parentId");

        // Check rate limit (DoS protection)
        if ($rateLimitError = $this->checkRateLimit()) {
            return $rateLimitError;
        }

        // 1. Validate child ticket ID
        if (!$this->isValidPositiveInteger($childId)) {
            subticket_log('Invalid child ticket ID', "childId=$childId");
            return $this->errorResponse('Invalid child ticket ID');
        }

        // 2. Validate parent ticket ID or number
        if (!$this->isValidPositiveInteger($parentId)) {
            subticket_log('Invalid parent ticket ID/number', "parentId=$parentId");
            return $this->errorResponse('Invalid parent ticket ID/number');
        }

        // 3. Validate CSRF token and permissions
        $securityCheck = $this->validateSecurityRequirements($csrfToken);
        if ($securityCheck !== true) {
            subticket_log('Security check failed');
            return $securityCheck; // Return error response
        }

        // 4. SECURITY: the agent must be allowed to link the child (current ticket)
        subticket_log('Checking access to child ticket', "childId=$childId");
        $childAccess = $this->validateTicketAccess((int)$childId, true);
        if ($childAccess !== true) {
            subticket_log('Access denied to child ticket', "childId=$childId");
            return $childAccess;
        }

        // 5. Resolve the parent NUMBER (what the agent sees) to the internal ID
        $resolvedParentId = $this->plugin->getTicketIdByNumber($parentId);
        if ($resolvedParentId === null) {
            subticket_log('Parent number not found', "parentNumber=$parentId");
            return $this->errorResponse('Failed to link tickets');
        }

        // 6. SECURITY: ... and the parent as well
        $parentAccess = $this->validateTicketAccess($resolvedParentId, true);
        if ($parentAccess !== true) {
            subticket_log('Access denied to parent ticket', "parentId=$resolvedParentId");
            return $parentAccess;
        }

        // 7. Execute linkTicket (existence, self-link, cycle and limit checks live there)
        subticket_log('Calling linkTicket', "childId=$childId, parentId=$resolvedParentId");
        $result = $this->plugin->linkTicket((int)$childId, $resolvedParentId);

        // 8. Return response based on result
        if ($result) {
            subticket_log('Link successful');
            return $this->successResponse('Tickets successfully linked');
        } else {
            subticket_log('Link failed');
            return $this->errorResponse('Failed to link tickets');
        }
    }

    /**
     * Get batch parent status for multiple tickets
     *
     * Returns parent status (is_parent, child_count) for multiple ticket IDs.
     * Used by queue-indicator.js to show parent icons in ticket lists.
     *
     * The ids come from the queue the agent is looking at. The response
     * carries no ticket content, only whether a ticket has children and how
     * many, so it is rate limited rather than access filtered.
     *
     * @param string $ticketIds Comma-separated list of ticket IDs
     * @return array Success response with parent status data
     */
    public function getBatchParentStatus($ticketIds) {
        // Check rate limit (DoS protection)
        if ($rateLimitError = $this->checkRateLimit()) {
            return $rateLimitError;
        }

        // Parse ticket IDs
        $ids = array_filter(array_map('intval', explode(',', (string)$ticketIds)), function($id) {
            return $id > 0;
        });

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
                FROM " . Table::name('ticket') . " parent
                LEFT JOIN " . Table::name('ticket') . " child ON parent.ticket_id = child.ticket_pid
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

    // ============================================================
    // Private Helper Methods for Validation, Security, and Response Building
    // ============================================================

    /**
     * Validate if value is a positive integer
     *
     * Accepts integers and strings of digits without sign, decimal point or
     * exponent. Used for ticket ID and ticket number validation.
     *
     * @param mixed $value Value to validate
     * @return bool True if valid positive integer, false otherwise
     */
    private function isValidPositiveInteger($value) {
        if (is_int($value)) {
            return $value > 0;
        }

        if (!is_string($value)) {
            return false;
        }

        return preg_match('/^[1-9][0-9]*$/', $value) === 1;
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
     * SECURITY: Prevents horizontal privilege escalation. Decisions are made
     * by osTicket's Ticket::checkStaffPerm() through TicketAccessGuard, so
     * department membership, assignment, referral and the role permission
     * all count the same way they do in the core UI.
     *
     * @param int $ticketId Ticket ID to check
     * @param bool $forLinking Require the link permission instead of view access
     * @return true|array Returns true if access granted, error response otherwise
     */
    private function validateTicketAccess(int $ticketId, bool $forLinking = false) {
        subticket_log('validateTicketAccess called', "ticketId=$ticketId, forLinking=" . ($forLinking ? 'yes' : 'no'));

        $granted = $forLinking
            ? $this->accessGuard->canLink($ticketId)
            : $this->accessGuard->canView($ticketId);

        if (!$granted) {
            subticket_log('SECURITY: access denied', "ticketId=$ticketId");
            return $this->errorResponse(self::ACCESS_DENIED_MESSAGE);
        }

        subticket_log('Access granted', "ticketId=$ticketId");
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
}
