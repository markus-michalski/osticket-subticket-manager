<?php

declare(strict_types=1);

namespace SubticketManager\Signal;

use SubticketManager\Hierarchy\HierarchyService;
use SubticketManager\UI\PanelRenderer;

/**
 * TicketEventHandler - Handles osTicket signal events
 *
 * Manages:
 * - object.view signal (UI injection)
 * - model.created signal (auto-linking)
 * - model.updated signal (status changes)
 *
 * @package SubticketManager
 */
final class TicketEventHandler
{
    private HierarchyService $hierarchyService;
    private PanelRenderer $panelRenderer;

    public function __construct(HierarchyService $hierarchyService, PanelRenderer $panelRenderer)
    {
        $this->hierarchyService = $hierarchyService;
        $this->panelRenderer = $panelRenderer;
    }

    /**
     * Handle object.view signal - inject subticket panel into ticket view
     *
     * @param mixed $object The object being viewed
     * @param string $csrfToken CSRF token for AJAX
     * @return string HTML output
     */
    public function onTicketView($object, string $csrfToken): string
    {
        $this->log('onTicketView() called', 'Object: ' . (is_object($object) ? get_class($object) : 'NULL'));

        if (!$object) {
            $this->log('Object is null', 'Returning empty');
            return '';
        }

        $className = get_class($object);
        $this->log('Object class', $className);

        if ($className !== 'Ticket' && !is_subclass_of($object, 'Ticket')) {
            $this->log('Not a Ticket object', 'Skipping');
            return '';
        }

        $ticketId = $object->getId();
        $this->log('Ticket ID', (string)$ticketId);

        if (!$ticketId) {
            $this->log('No ticket ID', 'Returning empty');
            return '';
        }

        $parent = $this->hierarchyService->getParent($ticketId);
        $children = $this->hierarchyService->getChildren($ticketId);

        $this->log('Parent ticket', $parent ? 'found' : 'none');
        $this->log('Children count', (string)count($children));

        $html = $this->panelRenderer->render($ticketId, $parent, $children, $csrfToken);
        $this->log('Rendered HTML length', strlen($html) . ' bytes');

        return $html;
    }

    /**
     * Handle model.created signal - auto-link new ticket to parent
     *
     * @param mixed $object The created object
     */
    public function onTicketCreated($object): void
    {
        $this->log('onTicketCreated() called', 'Object: ' . (is_object($object) ? get_class($object) : 'NULL'));

        if (!$object || get_class($object) !== 'Ticket') {
            return;
        }

        // Check session for parent ID (stored when opening create ticket page)
        $parentId = $_SESSION['subticket_parent'] ?? null;

        if (!$parentId) {
            $this->log('No parent ID in session', 'Skipping auto-link');
            return;
        }

        // SECURITY: Validate parentId is numeric and positive
        if (!is_numeric($parentId) || (int)$parentId < 1) {
            $this->log('Invalid parent ID in session', 'Security: rejecting non-numeric value');
            unset($_SESSION['subticket_parent']);
            return;
        }

        $parentId = (int)$parentId;
        $childId = $object->getId();

        if (!$childId) {
            $this->log('No ticket ID on created object', 'Cannot auto-link');
            return;
        }

        $this->log('Auto-linking ticket', "Child: $childId, Parent: $parentId");

        try {
            $parentId_escaped = db_input($parentId);
            $childId_escaped = db_input($childId);

            $sql = "UPDATE ost_ticket SET ticket_pid = $parentId_escaped WHERE ticket_id = $childId_escaped";
            $result = db_query($sql);

            if ($result) {
                $this->log('Auto-link successful', "Ticket $childId linked to parent $parentId");
            } else {
                $this->log('Auto-link failed', 'Database update failed');
            }
        } catch (\Exception $e) {
            $this->log('Auto-link exception', $e->getMessage());
        }

        // Clear from session
        unset($_SESSION['subticket_parent']);
        $this->log('Cleared subticket_parent from session');
    }

    /**
     * Handle model.updated signal - process ticket status changes
     *
     * @param mixed $model The updated model
     * @param array<string, mixed> $changes Changed fields
     */
    public function onTicketStatusChanged($model, ?array $changes = null): void
    {
        // TODO: Phase 4 Cycle 8 - implement auto-close logic
    }

    /**
     * Store subticket parent in session from URL parameter
     *
     * SECURITY: Validates that the parameter is numeric
     */
    public function storeParentFromUrl(): void
    {
        if (!isset($_GET['subticket_parent'])) {
            return;
        }

        $parentId = $_GET['subticket_parent'];

        // SECURITY: Only accept numeric values
        if (!is_numeric($parentId) || (int)$parentId < 1) {
            $this->log('SECURITY: Invalid subticket_parent parameter', 'Rejecting: ' . var_export($parentId, true));
            return;
        }

        $_SESSION['subticket_parent'] = (int)$parentId;
        $this->log('Stored subticket_parent in session', (string)$parentId);
    }

    /**
     * Check if we should load queue indicator script
     */
    public function shouldLoadQueueIndicator(): bool
    {
        $scriptName = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $queuePages = ['tickets.php', 'open.php', 'closed.php', 'answered.php', 'overdue.php', 'assigned.php'];

        if (!in_array($scriptName, $queuePages, true)) {
            $this->log('Skipping queue indicator', "Not on queue page (current: $scriptName)");
            return false;
        }

        return true;
    }

    /**
     * Log message
     */
    private function log(string $title, string $message = ''): void
    {
        if (function_exists('subticket_log')) {
            subticket_log($title, $message);
        }
    }
}
