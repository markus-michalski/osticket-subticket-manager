<?php
/**
 * Phase 4 Tests: Frontend Hooks Registration
 *
 * TDD Cycle 1: RED Phase
 * Tests that frontend hooks are properly registered with osTicket's signal system
 *
 * Expected to FAIL initially - implementation comes in GREEN phase!
 */

// Load bootstrap
require_once dirname(__DIR__) . '/bootstrap.php';

use PHPUnit\Framework\TestCase;

class FrontendHooksRegistrationTest extends TestCase
{
    /** @var TestableSubticketPlugin */
    private $plugin;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset global signal registry
        reset_test_signals();

        // Create plugin instance
        $this->plugin = new TestableSubticketPlugin();

        // Bootstrap plugin (triggers signal registration)
        $this->plugin->bootstrap();
    }

    /**
     * Test that plugin registers object.view signal handler
     *
     * Expected behavior (RED → GREEN):
     * - Plugin calls Signal::connect('object.view', ...)
     * - Handler method: $plugin->onTicketView($ticket)
     * - Handler injects subticket panel HTML into ticket view
     */
    public function testPluginRegistersObjectViewSignal()
    {
        // Get registered signals from global mock registry
        $signals = get_test_signals();

        // Assert that 'object.view' signal was registered (NOT 'ticket.view'!)
        $this->assertArrayHasKey('object.view', $signals,
            'Plugin must register object.view signal handler');

        // Assert handler is callable
        $handler = $signals['object.view'];
        $this->assertTrue(is_callable($handler),
            'object.view handler must be callable');

        // Assert handler points to plugin method
        $this->assertIsArray($handler);
        $this->assertInstanceOf(TestableSubticketPlugin::class, $handler[0]);
        $this->assertEquals('onTicketView', $handler[1]);
    }

    /**
     * Test that ticket view handler is only registered for staff area
     *
     * Expected behavior:
     * - Signal should NOT be registered in client portal
     * - Signal should ONLY be registered in staff control panel (scp/)
     */
    public function testObjectViewSignalOnlyInStaffArea()
    {
        // Simulate client portal context
        $GLOBALS['__test_is_staff_area'] = false;

        reset_test_signals();
        $plugin = new TestableSubticketPlugin();
        $plugin->bootstrap();

        $signals = get_test_signals();

        // Assert object.view is NOT registered in client portal
        $this->assertArrayNotHasKey('object.view', $signals,
            'object.view signal should NOT be registered in client portal');

        // Reset to staff area
        $GLOBALS['__test_is_staff_area'] = true;
    }

    /**
     * Test that plugin registers model.updated signal for event handling
     *
     * Expected behavior:
     * - Plugin registers handler for ticket status changes
     * - Handler method: $plugin->onTicketStatusChanged($ticket)
     * - Used for auto-close logic
     */
    public function testPluginRegistersModelUpdatedSignal()
    {
        $signals = get_test_signals();

        // Assert that 'model.updated' signal was registered
        $this->assertArrayHasKey('model.updated', $signals,
            'Plugin must register model.updated signal for event handling');

        // Assert handler is callable
        $handler = $signals['model.updated'];
        $this->assertTrue(is_callable($handler),
            'model.updated handler must be callable');
    }

    /**
     * Test that handler gracefully handles missing ticket object
     *
     * Expected behavior:
     * - If $ticket is null/invalid, handler returns empty string
     * - No PHP errors/warnings
     */
    public function testHandlerGracefullyHandlesMissingTicket()
    {
        // Call handler with null ticket
        $output = $this->plugin->onTicketView(null);

        // Assert empty output (no crash)
        $this->assertIsString($output);
        $this->assertEmpty($output);
    }

    /**
     * Test that handler only injects UI for Ticket objects
     *
     * Expected behavior:
     * - object.view fires for ALL objects (tickets, tasks, etc.)
     * - Handler should ONLY inject UI for Ticket objects
     * - For non-Ticket objects: return empty string
     */
    public function testHandlerOnlyInjectsForTicketObjects()
    {
        // Create mock non-Ticket object (e.g. Task)
        $mockTask = new stdClass();
        $mockTask->getId = function() { return 1; };

        // Call handler with non-Ticket object
        $output = $this->plugin->onTicketView($mockTask);

        // Assert empty output (no UI injection for non-Ticket)
        $this->assertIsString($output);
        $this->assertEmpty($output);
    }
}
