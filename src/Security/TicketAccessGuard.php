<?php

declare(strict_types=1);

namespace SubticketManager\Security;

/**
 * TicketAccessGuard - answers "may the current agent see / link this ticket?"
 *
 * All decisions go through osTicket's own Ticket::checkStaffPerm(), which
 * covers department access, assignment, referral and the role permission.
 * Nothing in the plugin re-implements that logic.
 *
 * Tests pass a closure that decides per ticket id; the legacy global
 * $__test_ticket_access is honoured as well so the existing suite keeps
 * working.
 *
 * @package SubticketManager
 */
final class TicketAccessGuard
{
    /** @var callable|null fn(int $ticketId, string $perm): bool */
    private $override;

    public function __construct(?callable $override = null)
    {
        $this->override = $override;
    }

    /**
     * May the current agent view the ticket?
     */
    public function canView(int $ticketId): bool
    {
        return $this->check($ticketId, 'view');
    }

    /**
     * May the current agent link or unlink the ticket?
     *
     * Requires the role permission osTicket uses for its own link feature
     * (Ticket::PERM_LINK, "ticket.link"); falls back to edit on cores that
     * predate it.
     */
    public function canLink(int $ticketId): bool
    {
        return $this->check($ticketId, 'link');
    }

    /**
     * Is a staff member authenticated for this request?
     */
    public function hasStaff(): bool
    {
        if ($this->override !== null) {
            return true;
        }

        global $thisstaff;

        return is_object($thisstaff)
            && method_exists($thisstaff, 'isStaff')
            && $thisstaff->isStaff();
    }

    private function check(int $ticketId, string $perm): bool
    {
        if ($ticketId < 1) {
            return false;
        }

        if ($this->override !== null) {
            return (bool)($this->override)($ticketId, $perm);
        }

        if (isset($GLOBALS['__test_ticket_access'])) {
            return $GLOBALS['__test_ticket_access'] === true;
        }

        if (!$this->hasStaff() || !class_exists('Ticket')) {
            return false;
        }

        global $thisstaff;

        $ticket = \Ticket::lookup($ticketId);
        if (!$ticket || !method_exists($ticket, 'checkStaffPerm')) {
            return false;
        }

        if ($perm === 'view') {
            return (bool)$ticket->checkStaffPerm($thisstaff);
        }

        $permission = defined('Ticket::PERM_LINK') ? \Ticket::PERM_LINK : \Ticket::PERM_EDIT;

        return (bool)$ticket->checkStaffPerm($thisstaff, $permission);
    }
}
