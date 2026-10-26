<?php

declare(strict_types=1);

namespace SubticketManager\Hierarchy;

use SubticketManager\Config\ConfigCache;
use SubticketManager\Database\Table;

/**
 * HierarchyService - Manages ticket parent-child relationships
 *
 * Handles:
 * - Linking/unlinking tickets
 * - Getting parent/children
 * - Circular dependency detection
 * - Ticket number to ID resolution
 * - Enforcing the configured hierarchy limits
 *
 * @package SubticketManager
 */
final class HierarchyService
{
    /**
     * Hard ceiling for walking up a parent chain.
     *
     * A chain longer than this is treated as unresolved and the link is
     * refused: the plugin can no longer prove that it does not close a cycle.
     */
    private const MAX_HIERARCHY_DEPTH = 10;

    /**
     * Get all children for a parent ticket
     *
     * @param int $parentId Parent ticket ID
     * @return array<int, array{id: int, number: string, subject: string, status: string, created: string}>
     */
    public function getChildren(int $parentId): array
    {
        $this->log('getChildren() called', "parentId: $parentId");

        if ($parentId < 1) {
            $this->log('Invalid parent ID', 'Not numeric or < 1');
            return [];
        }

        $parentId_escaped = db_input($parentId);

        $sql = "SELECT t.ticket_id, t.number,
                       cdata.subject as subject,
                       s.name as status,
                       t.created
                FROM " . Table::name('ticket') . " t
                LEFT JOIN " . Table::name('ticket__cdata') . " cdata ON t.ticket_id = cdata.ticket_id
                LEFT JOIN " . Table::name('ticket_status') . " s ON t.status_id = s.id
                WHERE t.ticket_pid = $parentId_escaped
                ORDER BY t.created ASC";

        $this->log('SQL query', $sql);

        $result = db_query($sql);
        $children = [];

        if (!$result) {
            $error = function_exists('db_error') ? db_error() : 'Unknown error';
            $this->log('Query failed', "Error: $error | SQL: $sql");
            return [];
        }

        while ($row = db_fetch_array($result)) {
            $this->log('Found child ticket', 'ID: ' . $row['ticket_id']);

            $children[] = [
                'id' => (int)$row['ticket_id'],
                'number' => $row['number'],
                'subject' => $row['subject'] ?? '',
                'status' => $row['status'] ?? 'Unknown',
                'created' => $row['created'],
            ];
        }

        $this->log('Total children found', (string)count($children));

        return $children;
    }

    /**
     * Get parent ticket for a child
     *
     * @param int $childId Child ticket ID
     * @return array{ticket_id: int, number: string, subject: string, status: string}|null
     */
    public function getParent(int $childId): ?array
    {
        if ($childId < 1) {
            return null;
        }

        $childId_escaped = db_input($childId);

        $sql = "SELECT p.ticket_id, p.number,
                       cdata.subject as subject,
                       s.name as status
                FROM " . Table::name('ticket') . " t
                INNER JOIN " . Table::name('ticket') . " p ON t.ticket_pid = p.ticket_id
                LEFT JOIN " . Table::name('ticket__cdata') . " cdata ON p.ticket_id = cdata.ticket_id
                LEFT JOIN " . Table::name('ticket_status') . " s ON p.status_id = s.id
                WHERE t.ticket_id = $childId_escaped";

        $result = db_query($sql);
        $row = db_fetch_array($result);

        return $row ?: null;
    }

    /**
     * Get ticket ID from ticket number
     *
     * osTicket ticket numbers can be alphanumeric (e.g., "ABC-123-456")
     * or purely numeric depending on the system configuration.
     *
     * @param string|int $number Ticket number
     * @return int|null Ticket ID or null
     */
    public function getTicketIdByNumber($number): ?int
    {
        // Validate: must be non-empty string or positive number
        if (empty($number)) {
            $this->log('getTicketIdByNumber: empty number provided');
            return null;
        }

        // Convert to string and trim
        $number = trim((string)$number);

        if ($number === '') {
            $this->log('getTicketIdByNumber: number is empty after trim');
            return null;
        }

        // Escape as string (quoted) for SQL - ticket numbers can be alphanumeric
        $number_escaped = db_input($number);
        $sql = "SELECT ticket_id FROM " . Table::name('ticket') . " WHERE number = '$number_escaped'";

        $this->log('getTicketIdByNumber SQL', $sql);

        $result = db_query($sql);

        if ($result && db_num_rows($result) > 0) {
            $row = db_fetch_array($result);
            $this->log('getTicketIdByNumber: found ticket', "number=$number, id=" . $row['ticket_id']);
            return (int)$row['ticket_id'];
        }

        $this->log('getTicketIdByNumber: ticket not found', "number=$number");
        return null;
    }

    /**
     * Link a ticket as child to a parent (by ticket number)
     *
     * @param int $childId Child ticket ID
     * @param string|int $parentNumber Parent ticket number
     * @return bool Success
     */
    public function linkTicketByNumber(int $childId, $parentNumber): bool
    {
        $parentId = $this->getTicketIdByNumber($parentNumber);

        if ($parentId === null) {
            error_log("[SUBTICKET-PLUGIN] linkTicketByNumber failed: Parent ticket #$parentNumber not found");
            return false;
        }

        $this->log('Ticket number resolved', "Ticket #$parentNumber to ID $parentId");

        return $this->linkTicket($childId, $parentId);
    }

    /**
     * Link a ticket as child to a parent
     *
     * Refuses the link when either ticket is missing, the link would close a
     * cycle, the parent chain cannot be resolved, or one of the configured
     * limits (max_children, max_depth, allow_nested_subtickets,
     * require_parent_open) is violated.
     *
     * @param int $childId Child ticket ID
     * @param int $parentId Parent ticket ID
     * @return bool Success
     */
    public function linkTicket(int $childId, int $parentId): bool
    {
        // Validate inputs
        if ($childId < 1) {
            error_log('[SUBTICKET-PLUGIN] linkTicket failed: Invalid child ID');
            return false;
        }
        if ($parentId < 1) {
            error_log('[SUBTICKET-PLUGIN] linkTicket failed: Invalid parent ID');
            return false;
        }

        // Prevent self-linking
        if ($childId === $parentId) {
            error_log("[SUBTICKET-PLUGIN] linkTicket failed: Cannot link ticket to itself (child: $childId, parent: $parentId)");
            return false;
        }

        // Validate tickets exist
        $childId_escaped = db_input($childId);
        $parentId_escaped = db_input($parentId);

        $child = db_query("SELECT ticket_id FROM " . Table::name('ticket') . " WHERE ticket_id = $childId_escaped");
        $parent = db_query("SELECT ticket_id FROM " . Table::name('ticket') . " WHERE ticket_id = $parentId_escaped");

        if (!db_num_rows($child) || !db_num_rows($parent)) {
            error_log("[SUBTICKET-PLUGIN] linkTicket failed: Ticket not found (child: $childId, parent: $parentId)");
            return false;
        }

        // Prevent circular dependency (and refuse chains we cannot resolve)
        if ($this->isDescendant($parentId, $childId)) {
            error_log('[SUBTICKET-PLUGIN] linkTicket failed: Circular dependency detected');
            return false;
        }

        $violation = $this->checkLimits($childId, $parentId);
        if ($violation !== null) {
            error_log("[SUBTICKET-PLUGIN] linkTicket refused: $violation (child: $childId, parent: $parentId)");
            return false;
        }

        // Update ticket_pid
        $sql = "UPDATE " . Table::name('ticket') . " SET ticket_pid = $parentId_escaped WHERE ticket_id = $childId_escaped";
        $result = db_query($sql);

        if ($result) {
            return true;
        }

        error_log('[SUBTICKET-PLUGIN] linkTicket failed: Database error');
        return false;
    }

    /**
     * Unlink a ticket from its parent
     *
     * @param int $childId Child ticket ID
     * @return bool Success
     */
    public function unlinkTicket(int $childId): bool
    {
        if ($childId < 1) {
            error_log('[SUBTICKET-PLUGIN] unlinkTicket failed: Invalid child ID');
            return false;
        }

        $childId_escaped = db_input($childId);
        $sql = "UPDATE " . Table::name('ticket') . " SET ticket_pid = NULL WHERE ticket_id = $childId_escaped";
        $result = db_query($sql);

        if ($result) {
            return true;
        }

        error_log('[SUBTICKET-PLUGIN] unlinkTicket failed: Database error');
        return false;
    }

    /**
     * Check if ticket A is a descendant of ticket B
     *
     * Returns true as well when the parent chain revisits a ticket or is
     * longer than MAX_HIERARCHY_DEPTH: in both cases the plugin cannot prove
     * that linking is safe, and the caller must refuse.
     *
     * @param int $ticketId Ticket to check
     * @param int $ancestorId Potential ancestor
     * @return bool True if ticketId is (or may be) a descendant of ancestorId
     */
    public function isDescendant(int $ticketId, int $ancestorId): bool
    {
        $currentId = $ticketId;
        $visited = [$ticketId => true];

        for ($i = 0; $i < self::MAX_HIERARCHY_DEPTH; $i++) {
            $parentId = $this->fetchParentId($currentId);

            if ($parentId === null) {
                return false;
            }

            if ($parentId === $ancestorId) {
                return true;
            }

            if (isset($visited[$parentId])) {
                error_log("[SUBTICKET-PLUGIN] isDescendant: existing cycle detected at ticket $parentId");
                return true;
            }

            $visited[$parentId] = true;
            $currentId = $parentId;
        }

        error_log("[SUBTICKET-PLUGIN] isDescendant: parent chain of ticket $ticketId exceeds " . self::MAX_HIERARCHY_DEPTH . ' levels, refusing');
        return true;
    }

    /**
     * Number of ancestors above a ticket (0 for a root ticket)
     *
     * Returns null when the chain cannot be resolved within
     * MAX_HIERARCHY_DEPTH or revisits a ticket.
     */
    public function getDepth(int $ticketId): ?int
    {
        $currentId = $ticketId;
        $visited = [$ticketId => true];

        for ($depth = 0; $depth <= self::MAX_HIERARCHY_DEPTH; $depth++) {
            $parentId = $this->fetchParentId($currentId);

            if ($parentId === null) {
                return $depth;
            }

            if (isset($visited[$parentId])) {
                return null;
            }

            $visited[$parentId] = true;
            $currentId = $parentId;
        }

        return null;
    }

    /**
     * Number of direct children of a ticket
     */
    public function countChildren(int $ticketId): int
    {
        $ticketId_escaped = db_input($ticketId);
        $result = db_query("SELECT COUNT(*) AS child_count FROM " . Table::name('ticket') . " WHERE ticket_pid = $ticketId_escaped");
        $row = $result ? db_fetch_array($result) : null;

        return $row && isset($row['child_count']) ? (int)$row['child_count'] : 0;
    }

    /**
     * Apply the configured hierarchy limits to a prospective link
     *
     * @return string|null Reason for refusal, or null when the link is allowed
     */
    private function checkLimits(int $childId, int $parentId): ?string
    {
        $config = ConfigCache::getInstance();

        $maxChildren = max(1, (int)$config->get('max_children', 50));
        if ($this->countChildren($parentId) >= $maxChildren) {
            return "Parent already has $maxChildren children (max_children)";
        }

        $parentDepth = $this->getDepth($parentId);
        if ($parentDepth === null) {
            return 'Parent chain cannot be resolved';
        }

        $allowNested = (bool)$config->get('allow_nested_subtickets', true);
        if (!$allowNested) {
            if ($parentDepth > 0) {
                return 'Parent is itself a subticket (allow_nested_subtickets is off)';
            }
            if ($this->countChildren($childId) > 0) {
                return 'Child has subtickets of its own (allow_nested_subtickets is off)';
            }
        }

        $maxDepth = max(1, (int)$config->get('max_depth', 3));
        if ($parentDepth + 1 > $maxDepth) {
            return "Link would exceed max_depth of $maxDepth";
        }

        if ((bool)$config->get('require_parent_open', true)) {
            $state = $this->fetchStatusState($parentId);
            if ($state !== null && $state !== 'open') {
                return "Parent ticket is not open (state: $state)";
            }
        }

        return null;
    }

    /**
     * Parent id of a ticket, or null for a root ticket / unknown ticket
     */
    private function fetchParentId(int $ticketId): ?int
    {
        $ticketId_escaped = db_input($ticketId);
        $result = db_query("SELECT ticket_pid FROM " . Table::name('ticket') . " WHERE ticket_id = $ticketId_escaped");
        $row = $result ? db_fetch_array($result) : null;

        if (!$row || empty($row['ticket_pid'])) {
            return null;
        }

        return (int)$row['ticket_pid'];
    }

    /**
     * Status state ('open', 'closed', ...) of a ticket, or null when unknown
     */
    private function fetchStatusState(int $ticketId): ?string
    {
        $ticketId_escaped = db_input($ticketId);
        $result = db_query("SELECT s.state
                FROM " . Table::name('ticket') . " t
                LEFT JOIN " . Table::name('ticket_status') . " s ON t.status_id = s.id
                WHERE t.ticket_id = $ticketId_escaped");
        $row = $result ? db_fetch_array($result) : null;

        if (!$row || !isset($row['state']) || $row['state'] === null) {
            return null;
        }

        return strtolower((string)$row['state']);
    }

    /**
     * Log message (uses global subticket_log if available)
     */
    private function log(string $title, string $message = ''): void
    {
        if (function_exists('subticket_log')) {
            subticket_log($title, $message);
        }
    }
}
