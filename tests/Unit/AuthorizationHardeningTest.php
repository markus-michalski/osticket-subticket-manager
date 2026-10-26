<?php
/**
 * Regression tests for the authorization hardening
 *
 * Covers:
 * - the subticket_parent hand-off (scope, lifetime, consumption)
 * - access checks on the AJAX read and write paths
 * - cycle detection limits and the configured hierarchy limits
 * - the staff-only ticket-view panel with redaction
 */

namespace SubticketManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SubticketManager\Config\ConfigCache;
use SubticketManager\Hierarchy\HierarchyService;
use SubticketManager\Security\TicketAccessGuard;
use SubticketManager\Signal\TicketEventHandler;
use SubticketManager\UI\PanelRenderer;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/ajax/SubticketController.php';

class AuthorizationHardeningTest extends TestCase
{
    private const TOKEN = 'valid-csrf-token-12345';

    protected function setUp(): void
    {
        parent::setUp();

        reset_test_db_queries();
        ConfigCache::resetInstance();

        $_SESSION = [];
        $_GET = [];
        $_SERVER['SCRIPT_NAME'] = '/scp/tickets.php';

        $GLOBALS['__test_is_staff_area'] = true;
        $GLOBALS['__test_staff_permission'] = true;
        $GLOBALS['__test_csrf_token'] = self::TOKEN;
    }

    protected function tearDown(): void
    {
        reset_test_db_queries();
        ConfigCache::resetInstance();

        $_SESSION = [];
        $_GET = [];

        $GLOBALS['__test_is_staff_area'] = true;
        $GLOBALS['__test_staff_permission'] = false;
        $GLOBALS['__test_ticket_access'] = true;

        parent::tearDown();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Guard that allows exactly the given ticket ids (view and link alike)
     */
    private function guardAllowing(array $ticketIds): TicketAccessGuard
    {
        return new TicketAccessGuard(function (int $ticketId, string $perm) use ($ticketIds): bool {
            return in_array($ticketId, $ticketIds, true);
        });
    }

    private function handler(TicketAccessGuard $guard): TicketEventHandler
    {
        return new TicketEventHandler(new HierarchyService(), new PanelRenderer('/include/plugins/subticket-manager'), $guard);
    }

    private function controller(TicketAccessGuard $guard): \SubticketController
    {
        return new \SubticketController(new \TestableSubticketPlugin(), $guard);
    }

    private function mockQueries(array $results): void
    {
        $GLOBALS['__test_mock_results'] = $results;
        $GLOBALS['__test_mock_index'] = 0;
    }

    private function updateQueries(): array
    {
        return array_values(array_filter(get_test_db_queries(), function (array $q): bool {
            return stripos($q['query'], 'UPDATE') !== false;
        }));
    }

    // ------------------------------------------------------------------
    // storeParentFromUrl(): only the staff new-ticket form may set it
    // ------------------------------------------------------------------

    public function testParentCandidateIsIgnoredOutsideTheStaffNewTicketForm(): void
    {
        $handler = $this->handler($this->guardAllowing([5]));
        $_GET['subticket_parent'] = '5';

        // client portal page
        $_SERVER['SCRIPT_NAME'] = '/tickets.php';
        $_GET['a'] = 'open';
        $handler->storeParentFromUrl();
        $this->assertArrayNotHasKey(TicketEventHandler::SESSION_KEY, $_SESSION, 'client portal must not set a candidate');

        // staff ticket view, not the form
        $_SERVER['SCRIPT_NAME'] = '/scp/tickets.php';
        unset($_GET['a']);
        $handler->storeParentFromUrl();
        $this->assertArrayNotHasKey(TicketEventHandler::SESSION_KEY, $_SESSION, 'ticket view must not set a candidate');

        // another staff page
        $_SERVER['SCRIPT_NAME'] = '/scp/open.php';
        $_GET['a'] = 'open';
        $handler->storeParentFromUrl();
        $this->assertArrayNotHasKey(TicketEventHandler::SESSION_KEY, $_SESSION, 'other staff pages must not set a candidate');
    }

    public function testParentCandidateRejectsNonNumericValues(): void
    {
        $handler = $this->handler($this->guardAllowing([5]));
        $_GET['a'] = 'open';

        foreach (['5; DROP TABLE ost_ticket', '0', '-3', '1e3', ' 7', ['5']] as $bad) {
            $_GET['subticket_parent'] = $bad;
            $handler->storeParentFromUrl();
            $this->assertArrayNotHasKey(TicketEventHandler::SESSION_KEY, $_SESSION, 'rejected: ' . var_export($bad, true));
        }
    }

    public function testParentCandidateIsStoredWithExpiry(): void
    {
        $handler = $this->handler($this->guardAllowing([42]));
        $_GET['a'] = 'open';
        $_GET['subticket_parent'] = '42';

        $before = time();
        $handler->storeParentFromUrl();

        $this->assertArrayHasKey(TicketEventHandler::SESSION_KEY, $_SESSION);
        $candidate = $_SESSION[TicketEventHandler::SESSION_KEY];
        $this->assertSame(42, $candidate['id']);
        $this->assertGreaterThan($before, $candidate['expires']);
        $this->assertLessThanOrEqual($before + TicketEventHandler::SESSION_TTL + 1, $candidate['expires']);
    }

    // ------------------------------------------------------------------
    // onTicketCreated(): consumption is authenticated and authorized
    // ------------------------------------------------------------------

    public function testExpiredCandidateIsNotApplied(): void
    {
        $handler = $this->handler($this->guardAllowing([7]));
        $_SESSION[TicketEventHandler::SESSION_KEY] = ['id' => 7, 'expires' => time() - 1];

        $handler->onTicketCreated(new \Ticket(['ticket_id' => 99]));

        $this->assertEmpty($this->updateQueries(), 'expired candidate must not link');
        $this->assertArrayNotHasKey(TicketEventHandler::SESSION_KEY, $_SESSION, 'candidate is single-use');
    }

    public function testLegacyScalarCandidateIsNotApplied(): void
    {
        $handler = $this->handler($this->guardAllowing([7]));
        $_SESSION[TicketEventHandler::SESSION_KEY] = 7;

        $handler->onTicketCreated(new \Ticket(['ticket_id' => 99]));

        $this->assertEmpty($this->updateQueries());
        $this->assertArrayNotHasKey(TicketEventHandler::SESSION_KEY, $_SESSION);
    }

    public function testCandidateIsNotAppliedOutsideTheStaffArea(): void
    {
        $GLOBALS['__test_is_staff_area'] = false;
        $handler = $this->handler($this->guardAllowing([7, 99]));
        $_SESSION[TicketEventHandler::SESSION_KEY] = ['id' => 7, 'expires' => time() + 100];

        $handler->onTicketCreated(new \Ticket(['ticket_id' => 99]));

        $this->assertEmpty($this->updateQueries(), 'a client-portal ticket must never be linked');
        $this->assertArrayNotHasKey(TicketEventHandler::SESSION_KEY, $_SESSION);
    }

    public function testCandidateRequiresLinkPermissionOnTheParent(): void
    {
        $handler = $this->handler($this->guardAllowing([]));
        $_SESSION[TicketEventHandler::SESSION_KEY] = ['id' => 7, 'expires' => time() + 100];

        $handler->onTicketCreated(new \Ticket(['ticket_id' => 99]));

        $this->assertEmpty($this->updateQueries(), 'no permission on parent, no link');
        $this->assertArrayNotHasKey(TicketEventHandler::SESSION_KEY, $_SESSION);
    }

    public function testCandidateIsAppliedThroughLinkTicketWithAllChecks(): void
    {
        $handler = $this->handler($this->guardAllowing([7]));
        $_SESSION[TicketEventHandler::SESSION_KEY] = ['id' => 7, 'expires' => time() + 100];

        $this->mockQueries([
            [['ticket_id' => 99]],      // child exists
            [['ticket_id' => 7]],       // parent exists
            [['ticket_pid' => null]],   // isDescendant: parent 7 is a root
            [['child_count' => 0]],     // countChildren(parent)
            [['ticket_pid' => null]],   // getDepth(parent)
            [['state' => 'open']],      // parent status
            true,                       // UPDATE
        ]);

        $handler->onTicketCreated(new \Ticket(['ticket_id' => 99]));

        $updates = $this->updateQueries();
        $this->assertCount(1, $updates);
        $this->assertStringContainsString('SET ticket_pid = 7 WHERE ticket_id = 99', $updates[0]['query']);
        $this->assertArrayNotHasKey(TicketEventHandler::SESSION_KEY, $_SESSION);
    }

    // ------------------------------------------------------------------
    // AJAX read path
    // ------------------------------------------------------------------

    public function testChildrenAreDeniedWithoutAccessToTheParent(): void
    {
        $controller = $this->controller($this->guardAllowing([]));

        $response = $controller->getChildren(1);

        $this->assertFalse($response['success']);
        $this->assertSame(\SubticketController::ACCESS_DENIED_MESSAGE, $response['message']);
        $this->assertEmpty(get_test_db_queries(), 'no query before the access check passes');
    }

    public function testChildrenTheAgentMayNotViewAreLeftOut(): void
    {
        $controller = $this->controller($this->guardAllowing([1, 2]));
        $this->mockQueries([[
            ['ticket_id' => 2, 'number' => '100002', 'subject' => 'Mine', 'status' => 'Open', 'created' => '2025-01-01 11:00:00'],
            ['ticket_id' => 3, 'number' => '100003', 'subject' => 'Other department', 'status' => 'Open', 'created' => '2025-01-01 12:00:00'],
        ]]);

        $response = $controller->getChildren(1);

        $this->assertTrue($response['success']);
        $this->assertCount(1, $response['data']);
        $this->assertSame(2, $response['data'][0]['id']);
        $this->assertSame('Found 1 children', $response['message']);
    }

    public function testBatchParentStatusIgnoresNonPositiveIds(): void
    {
        $controller = $this->controller($this->guardAllowing([]));

        $response = $controller->getBatchParentStatus('0,-1,abc');

        $this->assertFalse($response['success']);
        $this->assertSame('No valid ticket IDs provided', $response['message']);
    }

    // ------------------------------------------------------------------
    // AJAX write path
    // ------------------------------------------------------------------

    public function testLinkRequiresAccessToTheParent(): void
    {
        $controller = $this->controller($this->guardAllowing([2]));
        $this->mockQueries([
            [['ticket_id' => 1]], // parent number resolves to ticket 1
        ]);

        $response = $controller->linkExistingTicket(2, '100001', self::TOKEN);

        $this->assertFalse($response['success']);
        $this->assertSame(\SubticketController::ACCESS_DENIED_MESSAGE, $response['message']);
        $this->assertEmpty($this->updateQueries());
    }

    public function testUnlinkRequiresAccessToTheCurrentParent(): void
    {
        $controller = $this->controller($this->guardAllowing([2]));
        $this->mockQueries([
            [['ticket_id' => 1, 'number' => '100001', 'subject' => 'Parent', 'status' => 'Open']], // getParent(2)
        ]);

        $response = $controller->unlinkTicket(2, self::TOKEN);

        $this->assertFalse($response['success']);
        $this->assertSame(\SubticketController::ACCESS_DENIED_MESSAGE, $response['message']);
        $this->assertEmpty($this->updateQueries());
    }

    public function testCreateActionNoLongerExists(): void
    {
        $this->assertFalse(method_exists(\SubticketController::class, 'createSubticket'));
        $this->assertStringNotContainsString(
            "case 'create'",
            (string)file_get_contents(dirname(__DIR__, 2) . '/scp-files/ajax-subticket.php')
        );
    }

    public function testPositiveIntegerValidationRejectsNumericLookalikes(): void
    {
        $controller = $this->controller($this->guardAllowing([1]));

        foreach (['1e3', '5.0', ' 7', '0x1A', '007'] as $bad) {
            $response = $controller->getChildren($bad);
            $this->assertFalse($response['success'], "rejected: $bad");
            $this->assertSame('Invalid ticket ID', $response['message']);
        }
    }

    // ------------------------------------------------------------------
    // HierarchyService: cycle detection and limits
    // ------------------------------------------------------------------

    public function testParentChainBeyondMaxDepthIsTreatedAsUnsafe(): void
    {
        $service = new HierarchyService();

        // 1 -> 2 -> 3 -> ... -> 12, ancestor 999 never shows up
        $rows = [];
        for ($pid = 2; $pid <= 12; $pid++) {
            $rows[] = [['ticket_pid' => $pid]];
        }
        $this->mockQueries($rows);

        $this->assertTrue($service->isDescendant(1, 999), 'unresolved chain must be refused, not waved through');
    }

    public function testExistingCycleInParentChainIsDetected(): void
    {
        $service = new HierarchyService();
        $this->mockQueries([
            [['ticket_pid' => 2]], // 1 -> 2
            [['ticket_pid' => 1]], // 2 -> 1 (cycle)
        ]);

        $this->assertTrue($service->isDescendant(1, 999));
    }

    public function testLinkIsRefusedWhenParentHasMaxChildren(): void
    {
        ConfigCache::getInstance()->populate(['max_children' => 2]);
        $service = new HierarchyService();
        $this->mockQueries([
            [['ticket_id' => 5]],      // child exists
            [['ticket_id' => 1]],      // parent exists
            [['ticket_pid' => null]],  // isDescendant: parent is a root
            [['child_count' => 2]],    // parent already has 2 children
        ]);

        $this->assertFalse($service->linkTicket(5, 1));
        $this->assertEmpty($this->updateQueries());
    }

    public function testLinkIsRefusedWhenParentIsNotOpen(): void
    {
        $service = new HierarchyService();
        $this->mockQueries([
            [['ticket_id' => 5]],
            [['ticket_id' => 1]],
            [['ticket_pid' => null]],
            [['child_count' => 0]],
            [['ticket_pid' => null]],  // getDepth(parent) = 0
            [['state' => 'closed']],
        ]);

        $this->assertFalse($service->linkTicket(5, 1));
        $this->assertEmpty($this->updateQueries());
    }

    public function testLinkIsRefusedWhenNestingIsDisabledAndParentIsASubticket(): void
    {
        ConfigCache::getInstance()->populate(['allow_nested_subtickets' => false]);
        $service = new HierarchyService();
        $this->mockQueries([
            [['ticket_id' => 5]],
            [['ticket_id' => 1]],
            [['ticket_pid' => 9]],     // isDescendant: 1 -> 9
            [['ticket_pid' => null]],  // 9 is a root
            [['child_count' => 0]],
            [['ticket_pid' => 9]],     // getDepth(1): 1 -> 9
            [['ticket_pid' => null]],  // depth 1
        ]);

        $this->assertFalse($service->linkTicket(5, 1));
        $this->assertEmpty($this->updateQueries());
    }

    public function testLinkIsRefusedBeyondMaxDepth(): void
    {
        ConfigCache::getInstance()->populate(['max_depth' => 1]);
        $service = new HierarchyService();
        $this->mockQueries([
            [['ticket_id' => 5]],
            [['ticket_id' => 1]],
            [['ticket_pid' => 9]],
            [['ticket_pid' => null]],
            [['child_count' => 0]],
            [['ticket_pid' => 9]],     // parent depth 1, child would be depth 2
            [['ticket_pid' => null]],
        ]);

        $this->assertFalse($service->linkTicket(5, 1));
        $this->assertEmpty($this->updateQueries());
    }

    public function testLinkSucceedsWithinAllLimits(): void
    {
        ConfigCache::getInstance()->populate(['max_depth' => 3, 'max_children' => 50]);
        $service = new HierarchyService();
        $this->mockQueries([
            [['ticket_id' => 5]],
            [['ticket_id' => 1]],
            [['ticket_pid' => null]],
            [['child_count' => 3]],
            [['ticket_pid' => null]],
            [['state' => 'open']],
            true,
        ]);

        $this->assertTrue($service->linkTicket(5, 1));
        $this->assertCount(1, $this->updateQueries());
    }

    // ------------------------------------------------------------------
    // Ticket-view panel
    // ------------------------------------------------------------------

    public function testPanelIsNotRenderedOutsideTheStaffArea(): void
    {
        $GLOBALS['__test_is_staff_area'] = false;
        $handler = $this->handler($this->guardAllowing([1, 5]));

        $html = $handler->onTicketView(new \Ticket(['ticket_id' => 5]), 'tok');

        $this->assertSame('', $html, 'client portal must not get the staff panel');
        $this->assertEmpty(get_test_db_queries(), 'no hierarchy lookup for a client request');
    }

    public function testPanelRedactsParentTheAgentMayNotView(): void
    {
        $handler = $this->handler($this->guardAllowing([5]));
        $this->mockQueries([
            [['ticket_id' => 1, 'number' => '100001', 'subject' => 'Confidential parent', 'status' => 'Open']], // getParent
            [], // getChildren
        ]);

        $html = $handler->onTicketView(new \Ticket(['ticket_id' => 5]), 'tok');

        $this->assertStringNotContainsString('Confidential parent', $html);
        $this->assertStringNotContainsString('100001', $html);
        $this->assertStringNotContainsString('unlink-parent', $html);
        $this->assertStringContainsString('cannot access', $html);
    }

    public function testPanelHidesChildrenTheAgentMayNotView(): void
    {
        $handler = $this->handler($this->guardAllowing([5, 6]));
        $this->mockQueries([
            [], // getParent
            [
                ['ticket_id' => 6, 'number' => '100006', 'subject' => 'Visible child', 'status' => 'Open', 'created' => '2025-01-01'],
                ['ticket_id' => 7, 'number' => '100007', 'subject' => 'Hidden child', 'status' => 'Open', 'created' => '2025-01-01'],
            ],
        ]);

        $html = $handler->onTicketView(new \Ticket(['ticket_id' => 5]), 'tok');

        $this->assertStringContainsString('Visible child', $html);
        $this->assertStringNotContainsString('Hidden child', $html);
        $this->assertStringNotContainsString('100007', $html);
        $this->assertStringContainsString('1 child ticket not shown (no access)', $html);
        $this->assertStringContainsString('(2 Sub-Tickets)', $html, 'badge keeps the true count');
    }
}
