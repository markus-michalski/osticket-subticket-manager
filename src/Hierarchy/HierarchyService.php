<?php

declare(strict_types=1);

namespace SubticketManager\Hierarchy;

/**
 * HierarchyService - Manages ticket parent-child relationships
 *
 * Handles:
 * - Linking/unlinking tickets
 * - Getting parent/children
 * - Circular dependency detection
 * - Ticket number to ID resolution
 *
 * @package SubticketManager
 */
final class HierarchyService
{
    /**
     * Maximum hierarchy depth for circular dependency checks
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
                FROM ost_ticket t
                LEFT JOIN ost_ticket__cdata cdata ON t.ticket_id = cdata.ticket_id
                LEFT JOIN ost_ticket_status s ON t.status_id = s.id
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
                FROM ost_ticket t
                INNER JOIN ost_ticket p ON t.ticket_pid = p.ticket_id
                LEFT JOIN ost_ticket__cdata cdata ON p.ticket_id = cdata.ticket_id
                LEFT JOIN ost_ticket_status s ON p.status_id = s.id
                WHERE t.ticket_id = $childId_escaped";

        $result = db_query($sql);
        $row = db_fetch_array($result);

        return $row ?: null;
    }

    /**
     * Get ticket ID from ticket number
     *
     * @param string|int $number Ticket number
     * @return int|null Ticket ID or null
     */
    public function getTicketIdByNumber($number): ?int
    {
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

        $child = db_query("SELECT ticket_id FROM ost_ticket WHERE ticket_id = $childId_escaped");
        $parent = db_query("SELECT ticket_id FROM ost_ticket WHERE ticket_id = $parentId_escaped");

        if (!db_num_rows($child) || !db_num_rows($parent)) {
            error_log("[SUBTICKET-PLUGIN] linkTicket failed: Ticket not found (child: $childId, parent: $parentId)");
            return false;
        }

        // Prevent circular dependency
        if ($this->isDescendant($parentId, $childId)) {
            error_log('[SUBTICKET-PLUGIN] linkTicket failed: Circular dependency detected');
            return false;
        }

        // Update ticket_pid
        $sql = "UPDATE ost_ticket SET ticket_pid = $parentId_escaped WHERE ticket_id = $childId_escaped";
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
        $sql = "UPDATE ost_ticket SET ticket_pid = NULL WHERE ticket_id = $childId_escaped";
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
     * @param int $ticketId Ticket to check
     * @param int $ancestorId Potential ancestor
     * @return bool True if ticketId is descendant of ancestorId
     */
    public function isDescendant(int $ticketId, int $ancestorId): bool
    {
        $currentId = $ticketId;

        for ($i = 0; $i < self::MAX_HIERARCHY_DEPTH; $i++) {
            $currentId_escaped = db_input($currentId);
            $sql = "SELECT ticket_pid FROM ost_ticket WHERE ticket_id = $currentId_escaped";
            $result = db_query($sql);
            $row = db_fetch_array($result);

            if (!$row || !$row['ticket_pid']) {
                return false;
            }

            if ((int)$row['ticket_pid'] === $ancestorId) {
                return true;
            }

            $currentId = (int)$row['ticket_pid'];
        }

        return false;
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
