<?php
/**
 * Phase 4 Cycle 2 Tests: Ticket View Panel Rendering
 *
 * TDD Cycle 2: RED Phase
 * Tests that the plugin correctly renders the subticket panel HTML
 * for injection into osTicket's ticket view page.
 *
 * Expected to FAIL initially - implementation comes in GREEN phase!
 */

// Load bootstrap
require_once dirname(__DIR__) . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

class TicketViewPanelRenderingTest extends TestCase
{
    /** @var TestableSubticketPlugin */
    private $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset global state
        reset_test_db_queries();
        reset_test_signals();

        // Create plugin instance
        $this->plugin = new TestableSubticketPlugin();
    }

    /**
     * Test that onTicketView returns HTML string for valid Ticket object
     *
     * Expected behavior (RED → GREEN):
     * - Handler receives Ticket object
     * - Returns non-empty HTML string
     * - HTML contains panel wrapper div
     */
    public function testOnTicketViewReturnsHtmlStringForValidTicket()
    {
        // Create mock Ticket object
        $ticket = new Ticket(array(
            'ticket_id' => 1,
            'number' => '100001',
            'subject' => 'Test Ticket',
            'status' => 'Open',
            'ticket_pid' => null
        ));

        // Mock database queries (no parent, no children)
        $this->mockMultipleDbQueries(array(
            array(), // getParent() returns empty
            array()  // getChildren() returns empty
        ));

        $html = $this->plugin->onTicketView($ticket);

        // Assert returns HTML string
        $this->assertIsString($html);
        $this->assertNotEmpty($html, 'Panel should return HTML content');

        // Assert contains panel wrapper
        $this->assertStringContainsString('subticket-panel', $html,
            'HTML should contain panel wrapper div');
    }

    /**
     * Test that panel displays parent ticket when ticket has a parent
     *
     * Expected behavior:
     * - getParent() returns parent data
     * - Panel shows "Parent Ticket" section
     * - Displays parent ticket number and subject
     * - Shows "Unlink from Parent" button
     */
    public function testPanelDisplaysParentTicketWhenPresent()
    {
        $ticket = new Ticket(array(
            'ticket_id' => 2,
            'number' => '100002',
            'ticket_pid' => 1 // Has parent
        ));

        // Mock database: parent exists
        $this->mockMultipleDbQueries(array(
            // getParent() returns parent data
            array(array(
                'ticket_id' => 1,
                'number' => '100001',
                'subject' => 'Parent Ticket Subject',
                'status' => 'Open'
            )),
            // getChildren() returns empty
            array()
        ));

        $html = $this->plugin->onTicketView($ticket);

        // Assert parent section exists
        $this->assertStringContainsString('Parent Ticket', $html);
        $this->assertStringContainsString('100001', $html,
            'Should display parent ticket number');
        $this->assertStringContainsString('Parent Ticket Subject', $html,
            'Should display parent ticket subject');

        // Assert unlink button exists
        $this->assertStringContainsString('Unlink from Parent', $html);
    }

    /**
     * Test that panel shows "No parent" message when ticket has no parent
     *
     * Expected behavior:
     * - getParent() returns null
     * - Panel shows "No parent ticket" message
     * - Shows "Link to Parent" button
     */
    public function testPanelShowsNoParentMessageWhenNoParent()
    {
        $ticket = new Ticket(array(
            'ticket_id' => 1,
            'number' => '100001',
            'ticket_pid' => null // No parent
        ));

        // Mock database: no parent, no children
        $this->mockMultipleDbQueries(array(
            array(), // getParent() returns empty
            array()  // getChildren() returns empty
        ));

        $html = $this->plugin->onTicketView($ticket);

        // Assert "no parent" message
        $this->assertStringContainsString('No parent ticket', $html);

        // Assert link button exists
        $this->assertStringContainsString('Link to Parent', $html);
    }

    /**
     * Test that panel displays children list when ticket has children
     *
     * Expected behavior:
     * - getChildren() returns array of children
     * - Panel shows "Child Tickets" section
     * - Lists all children with numbers and subjects
     * - Shows unlink button for each child
     */
    public function testPanelDisplaysChildrenListWhenPresent()
    {
        $ticket = new Ticket(array(
            'ticket_id' => 1,
            'number' => '100001',
            'ticket_pid' => null
        ));

        // Mock database: no parent, 2 children
        $this->mockMultipleDbQueries(array(
            array(), // getParent() returns empty
            // getChildren() returns 2 children
            array(
                array(
                    'ticket_id' => 2,
                    'number' => '100002',
                    'subject' => 'Child Ticket 1',
                    'status' => 'Open',
                    'status_id' => 1,
                    'created' => '2025-01-01 10:00:00'
                ),
                array(
                    'ticket_id' => 3,
                    'number' => '100003',
                    'subject' => 'Child Ticket 2',
                    'status' => 'Closed',
                    'status_id' => 3,
                    'created' => '2025-01-01 11:00:00'
                )
            )
        ));

        $html = $this->plugin->onTicketView($ticket);

        // Assert children section exists
        $this->assertStringContainsString('Child Tickets', $html);

        // Assert both children are displayed
        $this->assertStringContainsString('100002', $html,
            'Should display first child ticket number');
        $this->assertStringContainsString('Child Ticket 1', $html,
            'Should display child subject from JOIN query');
        $this->assertStringContainsString('100003', $html,
            'Should display second child ticket number');
        $this->assertStringContainsString('Child Ticket 2', $html,
            'Should display second child subject from JOIN query');

        // Assert unlink buttons exist
        $this->assertGreaterThanOrEqual(2,
            substr_count($html, 'Unlink'),
            'Should have unlink buttons for children');
    }

    /**
     * Test that panel shows "No children" message when ticket has no children
     *
     * Expected behavior:
     * - getChildren() returns empty array
     * - Panel shows "No child tickets" message
     * - Shows "Create Subticket" button
     */
    public function testPanelShowsNoChildrenMessageWhenNoChildren()
    {
        $ticket = new Ticket(array(
            'ticket_id' => 1,
            'number' => '100001',
            'ticket_pid' => null
        ));

        // Mock database: no parent, no children
        $this->mockMultipleDbQueries(array(
            array(), // getParent() returns empty
            array()  // getChildren() returns empty
        ));

        $html = $this->plugin->onTicketView($ticket);

        // Assert "no children" message
        $this->assertStringContainsString('No child tickets', $html);

        // Assert create button exists
        $this->assertStringContainsString('Create Subticket', $html);
    }

    /**
     * Test that panel contains proper osTicket styling classes
     *
     * Expected behavior:
     * - Panel uses osTicket's CSS classes
     * - Contains .section-break for styling
     * - Uses .pull-left, .pull-right for layout
     * - Has proper heading tags
     */
    public function testPanelContainsProperOsTicketStyling()
    {
        $ticket = new Ticket(array(
            'ticket_id' => 1,
            'number' => '100001',
            'ticket_pid' => null
        ));

        // Mock database: no parent, no children
        $this->mockMultipleDbQueries(array(
            array(), // getParent() returns empty
            array()  // getChildren() returns empty
        ));

        $html = $this->plugin->onTicketView($ticket);

        // Assert osTicket styling classes exist
        $this->assertStringContainsString('section-break', $html,
            'Should use osTicket section-break class');
        $this->assertMatchesRegularExpression('/<h3[^>]*>/', $html,
            'Should have h3 heading tags');
    }

    /**
     * Test that panel includes ticket ID in data attributes
     *
     * Expected behavior:
     * - Panel contains data-ticket-id attribute
     * - Used by JavaScript for AJAX calls
     * - Matches the current ticket's ID
     */
    public function testPanelIncludesTicketIdInDataAttributes()
    {
        $ticket = new Ticket(array(
            'ticket_id' => 42,
            'number' => '100042',
            'ticket_pid' => null
        ));

        // Mock database: no parent, no children
        $this->mockMultipleDbQueries(array(
            array(), // getParent() returns empty
            array()  // getChildren() returns empty
        ));

        $html = $this->plugin->onTicketView($ticket);

        // Assert data-ticket-id attribute exists with correct value
        $this->assertStringContainsString('data-ticket-id="42"', $html,
            'Panel should include data-ticket-id attribute');
    }

    /**
     * Test that panel includes action buttons with correct data attributes
     *
     * Expected behavior:
     * - Buttons have data-action attributes
     * - Link button has data-action="link"
     * - Unlink buttons have data-action="unlink"
     * - Create button has data-action="create"
     */
    public function testPanelIncludesActionButtonsWithDataAttributes()
    {
        $ticket = new Ticket(array(
            'ticket_id' => 1,
            'number' => '100001',
            'ticket_pid' => null
        ));

        // Mock database: no parent, 1 child
        $this->mockMultipleDbQueries(array(
            array(), // getParent() returns empty
            // getChildren() returns 1 child
            array(
                array(
                    'ticket_id' => 2,
                    'number' => '100002',
                    'subject' => 'Child Ticket',
                    'status' => 'Open',
                    'status_id' => 1,
                    'created' => '2025-01-01 10:00:00'
                )
            )
        ));

        $html = $this->plugin->onTicketView($ticket);

        // Assert action buttons with data attributes (updated for more specific actions)
        $this->assertStringContainsString('data-action="link-parent"', $html,
            'Should have link-parent button with data-action');
        $this->assertStringContainsString('data-action="create-child"', $html,
            'Should have create-child button with data-action');
        $this->assertStringContainsString('data-action="unlink-child"', $html,
            'Should have unlink-child button with data-action');
    }

    // ============================================================
    // Helper Methods for Mocking Database
    // ============================================================

    /**
     * Mock multiple sequential database queries
     *
     * @param array $results Array of result data for each query
     */
    private function mockMultipleDbQueries($results)
    {
        $GLOBALS['__test_mock_results'] = $results;
        $GLOBALS['__test_mock_index'] = 0;
    }
}
