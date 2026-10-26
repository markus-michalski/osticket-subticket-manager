<?php

declare(strict_types=1);

namespace SubticketManager\Signal;

use SubticketManager\Hierarchy\HierarchyService;
use SubticketManager\Security\TicketAccessGuard;
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
    /**
     * Session key for the "create subticket" hand-off between the ticket
     * view and the new-ticket form.
     */
    public const SESSION_KEY = 'subticket_parent';

    /**
     * How long a stored parent candidate stays valid (seconds).
     *
     * Plugins are bootstrapped before osTicket authenticates the agent, so
     * the candidate is stored unauthenticated and checked when the ticket
     * is created. The short lifetime keeps a stale value from attaching a
     * ticket opened an hour later through the regular form.
     */
    public const SESSION_TTL = 600;

    private HierarchyService $hierarchyService;
    private PanelRenderer $panelRenderer;
    private TicketAccessGuard $accessGuard;

    public function __construct(
        HierarchyService $hierarchyService,
        PanelRenderer $panelRenderer,
        ?TicketAccessGuard $accessGuard = null
    ) {
        $this->hierarchyService = $hierarchyService;
        $this->panelRenderer = $panelRenderer;
        $this->accessGuard = $accessGuard ?? new TicketAccessGuard();
    }

    /**
     * Handle object.view signal - inject subticket panel into ticket view
     *
     * osTicket sends object.view for the staff ticket view and for the
     * client portal's ticket view. The panel is staff UI and is rendered in
     * the staff control panel only. Related tickets the agent may not view
     * are redacted (parent) or left out (children).
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

        if (!$this->isStaffRequest()) {
            $this->log('Not a staff request', 'Panel suppressed');
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

        if ($parent && !$this->accessGuard->canView((int)$parent['ticket_id'])) {
            $this->log('Parent redacted', 'Agent may not view ticket ' . $parent['ticket_id']);
            $parent = ['ticket_id' => (int)$parent['ticket_id'], 'restricted' => true];
        }

        $visibleChildren = [];
        foreach ($children as $child) {
            if ($this->accessGuard->canView((int)$child['id'])) {
                $visibleChildren[] = $child;
            }
        }
        $hiddenChildren = count($children) - count($visibleChildren);

        $this->log('Parent ticket', $parent ? 'found' : 'none');
        $this->log('Children count', count($visibleChildren) . ' visible, ' . $hiddenChildren . ' hidden');

        $html = $this->panelRenderer->render($ticketId, $parent, $visibleChildren, $csrfToken, $hiddenChildren);
        $this->log('Rendered HTML length', strlen($html) . ' bytes');

        return $html;
    }

    /**
     * Handle model.created signal - auto-link new ticket to parent
     *
     * The parent candidate comes from the session (see storeParentFromUrl()).
     * It is only consumed when
     *  - the request runs in the staff area with an authenticated agent,
     *  - the candidate has not expired,
     *  - the agent may link the parent ticket (Ticket::checkStaffPerm), and
     *  - HierarchyService::linkTicket() accepts the pair (existence, cycle,
     *    configured limits).
     * A client opening a ticket through the portal never reaches the link.
     *
     * @param mixed $object The created object
     */
    public function onTicketCreated($object): void
    {
        $this->log('onTicketCreated() called', 'Object: ' . (is_object($object) ? get_class($object) : 'NULL'));

        if (!$object || get_class($object) !== 'Ticket') {
            return;
        }

        $candidate = $_SESSION[self::SESSION_KEY] ?? null;
        if ($candidate === null) {
            $this->log('No parent candidate in session', 'Skipping auto-link');
            return;
        }

        // Whatever happens next, the candidate is single-use.
        unset($_SESSION[self::SESSION_KEY]);

        $parentId = $this->readCandidate($candidate);
        if ($parentId === null) {
            $this->log('Parent candidate rejected', 'Malformed or expired');
            return;
        }

        if (!$this->isStaffRequest() || !$this->accessGuard->hasStaff()) {
            $this->log('SECURITY: parent candidate ignored', 'No authenticated agent in this request');
            return;
        }

        if (!$this->accessGuard->canLink($parentId)) {
            $this->log('SECURITY: parent candidate ignored', "Agent may not link ticket $parentId");
            return;
        }

        $childId = (int)$object->getId();
        if ($childId < 1) {
            $this->log('No ticket ID on created object', 'Cannot auto-link');
            return;
        }

        $this->log('Auto-linking ticket', "Child: $childId, Parent: $parentId");

        if ($this->hierarchyService->linkTicket($childId, $parentId)) {
            $this->log('Auto-link successful', "Ticket $childId linked to parent $parentId");
        } else {
            $this->log('Auto-link refused', 'linkTicket() returned false');
        }
    }

    /**
     * Handle model.updated signal - process ticket status changes
     *
     * @param mixed $model The updated model
     * @param array<string, mixed> $changes Changed fields
     */
    public function onTicketStatusChanged($model, ?array $changes = null): void
    {
        // Reserved for a future auto-close implementation.
    }

    /**
     * Remember the parent ticket while the agent fills in the new-ticket form
     *
     * Only the staff "open new ticket" form (scp/tickets.php?a=open) may set
     * the candidate. Authorization happens in onTicketCreated(), because at
     * bootstrap time osTicket has not authenticated the agent yet.
     */
    public function storeParentFromUrl(): void
    {
        if (!isset($_GET['subticket_parent'])) {
            return;
        }

        if (!$this->isNewTicketForm()) {
            $this->log('SECURITY: subticket_parent ignored', 'Not the staff new-ticket form');
            return;
        }

        $parentId = $_GET['subticket_parent'];

        if (!is_string($parentId) || !ctype_digit($parentId) || (int)$parentId < 1) {
            $this->log('SECURITY: Invalid subticket_parent parameter', 'Rejecting: ' . var_export($parentId, true));
            return;
        }

        $_SESSION[self::SESSION_KEY] = [
            'id' => (int)$parentId,
            'expires' => time() + self::SESSION_TTL,
        ];
        $this->log('Stored subticket_parent candidate', $parentId);
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
     * Validate a session candidate and return the parent id, or null.
     *
     * @param mixed $candidate Raw session value
     */
    private function readCandidate($candidate): ?int
    {
        if (!is_array($candidate) || !isset($candidate['id'], $candidate['expires'])) {
            return null;
        }

        if (!is_int($candidate['id']) || $candidate['id'] < 1) {
            return null;
        }

        if (!is_int($candidate['expires']) || $candidate['expires'] < time()) {
            return null;
        }

        return $candidate['id'];
    }

    /**
     * Is this request served by the staff control panel?
     *
     * STAFFINC_DIR is defined globally in osTicket's bootstrap.php and says
     * nothing about the current request. OSTSCPINC is defined by
     * scp/staff.inc.php only, after the agent has been authenticated.
     */
    private function isStaffRequest(): bool
    {
        global $__test_is_staff_area;

        if (isset($__test_is_staff_area)) {
            return (bool)$__test_is_staff_area;
        }

        return defined('OSTSCPINC');
    }

    /**
     * Is this the staff "open new ticket" form?
     *
     * Decided from the script path because plugins are bootstrapped before
     * staff.inc.php defines OSTSCPINC.
     */
    private function isNewTicketForm(): bool
    {
        $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));

        if (basename($script) !== 'tickets.php' || basename(dirname($script)) !== 'scp') {
            return false;
        }

        return ($_GET['a'] ?? null) === 'open';
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
