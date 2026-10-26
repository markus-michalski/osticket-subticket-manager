<?php
/**
 * Tests for Phase 3 AJAX Controller
 *
 * Tests the SubticketController AJAX endpoints:
 * - getChildren($ticketId)
 * - unlinkTicket($childId)
 * - linkExistingTicket($childId, $parentId)
 *
 * Test Strategy: TDD (RED-GREEN-REFACTOR)
 * Phase: RED (tests written first, expect failures)
 */

namespace SubticketManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

// Load bootstrap FIRST (defines MockDbResult and TestableSubticketPlugin)
require_once dirname(__DIR__) . '/bootstrap.php';

// Load controller class (GREEN phase - now implemented!)
require_once dirname(__DIR__, 2) . '/ajax/SubticketController.php';

class SubticketControllerTest extends TestCase
{
    /** @var \SubticketController */
    private $controller;

    /** @var \TestableSubticketPlugin */
    private $plugin;

    /** @var array */
    private $testTickets = array();

    protected function setUp(): void
    {
        parent::setUp();

        // Reset global test query tracker
        reset_test_db_queries();

        // Create plugin instance
        $this->plugin = new \TestableSubticketPlugin();

        // Create controller instance (GREEN phase - now works!)
        $this->controller = new \SubticketController($this->plugin);

        // Setup test ticket data
        $this->testTickets = array(
            'parent' => array(
                'ticket_id' => 1,
                'number' => '100001',
                'subject' => 'Parent Ticket',
                'status' => 'Open',
                'status_id' => 1,
                'ticket_pid' => null,
                'created' => '2025-01-01 10:00:00'
            ),
            'child1' => array(
                'ticket_id' => 2,
                'number' => '100002',
                'subject' => 'John Doe Child Ticket 1',
                'status' => 'Open',
                'status_id' => 1,
                'ticket_pid' => 1,
                'created' => '2025-01-01 11:00:00'
            ),
            'child2' => array(
                'ticket_id' => 3,
                'number' => '100003',
                'subject' => 'Jane Smith Child Ticket 2',
                'status' => 'Closed',
                'status_id' => 3,
                'ticket_pid' => 1,
                'created' => '2025-01-01 12:00:00'
            )
        );
    }

    protected function tearDown(): void
    {
        // Cleanup: Reset global state
        reset_test_db_queries();
        parent::tearDown();
    }

    // ============================================================
    // RED Phase Tests for getChildren() AJAX Endpoint
    // ============================================================

    /**
     * Test that getChildren() returns JSON response with children data
     *
     * Expected behavior:
     * - Accepts ticket ID as parameter
     * - Calls plugin->getChildren() internally
     * - Returns JSON response with structure:
     *   {
     *     "success": true,
     *     "message": "Found 2 children",
     *     "data": [
     *       {ticket_id: 2, number: "100002", ...},
     *       {ticket_id: 3, number: "100003", ...}
     *     ]
     *   }
     */
    public function testGetChildrenReturnsJsonResponseWithChildrenData()
    {
        // Mock database to return two children
        $mockChildren = array(
            array(
                'ticket_id' => $this->testTickets['child1']['ticket_id'],
                'number' => $this->testTickets['child1']['number'],
                'ticket_pid' => $this->testTickets['child1']['ticket_pid'],
                'subject' => $this->testTickets['child1']['subject'],
                'status' => $this->testTickets['child1']['status'],
                'status_id' => $this->testTickets['child1']['status_id'],
                'created' => $this->testTickets['child1']['created']
            ),
            array(
                'ticket_id' => $this->testTickets['child2']['ticket_id'],
                'number' => $this->testTickets['child2']['number'],
                'ticket_pid' => $this->testTickets['child2']['ticket_pid'],
                'subject' => $this->testTickets['child2']['subject'],
                'status' => $this->testTickets['child2']['status'],
                'status_id' => $this->testTickets['child2']['status_id'],
                'created' => $this->testTickets['child2']['created']
            )
        );

        $this->mockDbQuery($mockChildren);

        // Call controller method (GREEN phase - now implemented!)
        $response = $this->controller->getChildren($this->testTickets['parent']['ticket_id']);

        // Expected response structure
        $this->assertIsArray($response, 'Response should be an array');
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('message', $response);
        $this->assertArrayHasKey('data', $response);

        $this->assertTrue($response['success']);
        $this->assertEquals('Found 2 children', $response['message']);
        $this->assertCount(2, $response['data']);

        // Assert children data (API returns 'id' not 'ticket_id')
        $this->assertEquals($this->testTickets['child1']['ticket_id'], $response['data'][0]['id']);
        $this->assertEquals($this->testTickets['child2']['ticket_id'], $response['data'][1]['id']);
    }

    /**
     * Test that getChildren() returns empty data array for ticket without children
     *
     * Expected behavior:
     * - Returns success with empty data array
     * - Message indicates no children found
     */
    public function testGetChildrenReturnsEmptyArrayForTicketWithoutChildren()
    {
        // Mock database to return no children
        $this->mockDbQuery(array());

        // Call controller method (GREEN phase - now implemented!)
        $response = $this->controller->getChildren($this->testTickets['parent']['ticket_id']);

        $this->assertTrue($response['success']);
        $this->assertEmpty($response['data']);
        $this->assertEquals('No children found', $response['message']);
    }

    /**
     * Test that getChildren() validates ticket ID
     *
     * Expected behavior:
     * - Returns error response for invalid ticket ID (null, 0, negative)
     * - success = false
     * - message = "Invalid ticket ID"
     */
    public function testGetChildrenValidatesTicketId()
    {
        // Test invalid ticket IDs
        $invalidIds = array(null, 0, -1, 'abc');

        foreach ($invalidIds as $invalidId) {
            $response = $this->controller->getChildren($invalidId);

            $this->assertFalse($response['success']);
            $this->assertEquals('Invalid ticket ID', $response['message']);
            $this->assertEmpty($response['data']);
        }
    }

    // ============================================================
    // RED Phase Tests for unlinkTicket() AJAX Endpoint
    // ============================================================

    /**
     * Test that unlinkTicket() returns success response when unlink succeeds
     *
     * Expected behavior:
     * - Accepts child ticket ID and CSRF token as parameters
     * - Validates input (numeric, positive)
     * - Validates CSRF token
     * - Checks staff permissions
     * - Calls plugin->unlinkTicket($childId)
     * - Returns JSON response with structure:
     *   {
     *     "success": true,
     *     "message": "Ticket successfully unlinked",
     *     "data": []
     *   }
     */
    public function testUnlinkTicketReturnsSuccessResponseWhenUnlinkSucceeds()
    {
        // Mock successful database UPDATE (unlinkTicket returns true)
        $this->mockDbQuery(true);

        // Mock CSRF token validation
        $this->mockCsrfToken('valid-csrf-token-12345');

        // Mock staff permissions
        $this->mockStaffPermissions(true);

        // Call controller method
        $childId = $this->testTickets['child1']['ticket_id'];
        $response = $this->controller->unlinkTicket($childId, 'valid-csrf-token-12345');

        // Assert response structure
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('message', $response);
        $this->assertArrayHasKey('data', $response);

        // Assert success
        $this->assertTrue($response['success']);
        $this->assertEquals('Ticket successfully unlinked', $response['message']);
        $this->assertEmpty($response['data']);
    }

    /**
     * Test that unlinkTicket() validates child ticket ID
     *
     * Expected behavior:
     * - Returns error response for invalid child ID (null, 0, negative, non-numeric)
     * - Does NOT call plugin->unlinkTicket()
     * - success = false
     * - message = "Invalid child ticket ID"
     */
    public function testUnlinkTicketValidatesChildTicketId()
    {
        // Mock CSRF token validation
        $this->mockCsrfToken('valid-csrf-token-12345');

        // Mock staff permissions
        $this->mockStaffPermissions(true);

        // Test invalid child ticket IDs
        $invalidIds = array(null, 0, -1, 'abc');

        foreach ($invalidIds as $invalidId) {
            $response = $this->controller->unlinkTicket($invalidId, 'valid-csrf-token-12345');

            $this->assertFalse($response['success']);
            $this->assertEquals('Invalid child ticket ID', $response['message']);
            $this->assertEmpty($response['data']);
        }

        // Verify plugin->unlinkTicket() was NEVER called
        $queries = get_test_db_queries();
        $this->assertEmpty($queries, 'No database queries should be executed for invalid input');
    }

    /**
     * Test that unlinkTicket() validates CSRF token
     *
     * Expected behavior:
     * - Returns error response when CSRF token is missing
     * - Returns error response when CSRF token is invalid
     * - Does NOT call plugin->unlinkTicket()
     */
    public function testUnlinkTicketValidatesCsrfToken()
    {
        // Mock valid CSRF token
        $this->mockCsrfToken('valid-csrf-token-12345');

        // Mock staff permissions
        $this->mockStaffPermissions(true);

        $childId = $this->testTickets['child1']['ticket_id'];

        // Test 1: Missing CSRF token
        $response = $this->controller->unlinkTicket($childId, null);

        $this->assertFalse($response['success']);
        $this->assertEquals('Missing CSRF token', $response['message']);
        $this->assertEmpty($response['data']);

        // Test 2: Invalid CSRF token
        reset_test_db_queries(); // Reset query tracker
        $response = $this->controller->unlinkTicket($childId, 'invalid-token');

        $this->assertFalse($response['success']);
        $this->assertEquals('Invalid CSRF token', $response['message']);
        $this->assertEmpty($response['data']);

        // Verify plugin->unlinkTicket() was NEVER called
        $queries = get_test_db_queries();
        $this->assertEmpty($queries, 'No database queries should be executed for invalid CSRF token');
    }

    /**
     * Test that unlinkTicket() checks staff permissions
     *
     * Expected behavior:
     * - Returns error response when user is not staff
     * - Does NOT call plugin->unlinkTicket()
     * - success = false
     * - message = "Permission denied"
     */
    public function testUnlinkTicketChecksStaffPermissions()
    {
        // Mock valid CSRF token
        $this->mockCsrfToken('valid-csrf-token-12345');

        // Mock NO staff permissions
        $this->mockStaffPermissions(false);

        $childId = $this->testTickets['child1']['ticket_id'];
        $response = $this->controller->unlinkTicket($childId, 'valid-csrf-token-12345');

        $this->assertFalse($response['success']);
        $this->assertEquals('Permission denied', $response['message']);
        $this->assertEmpty($response['data']);

        // Verify plugin->unlinkTicket() was NEVER called
        $queries = get_test_db_queries();
        $this->assertEmpty($queries, 'No database queries should be executed without permission');
    }

    /**
     * Test that unlinkTicket() returns error when operation fails
     *
     * Expected behavior:
     * - Valid inputs but unlinkTicket() returns false
     * - Returns error response
     * - success = false
     * - message = "Failed to unlink ticket"
     */
    public function testUnlinkTicketReturnsErrorWhenOperationFails()
    {
        // Mock FAILED database UPDATE (unlinkTicket returns false)
        // First query: getParent() (no parent), second: the UPDATE fails
        $this->mockMultipleDbQueries(array(array(), false));

        // Mock valid CSRF token
        $this->mockCsrfToken('valid-csrf-token-12345');

        // Mock staff permissions
        $this->mockStaffPermissions(true);

        $childId = $this->testTickets['child1']['ticket_id'];
        $response = $this->controller->unlinkTicket($childId, 'valid-csrf-token-12345');

        $this->assertFalse($response['success']);
        $this->assertEquals('Failed to unlink ticket', $response['message']);
        $this->assertEmpty($response['data']);
    }

    // ============================================================
    // Helper Methods for Mocking Database
    // ============================================================

    /**
     * Mock single database query result
     *
     * @param array|bool $resultData Array of rows or boolean for UPDATE/INSERT
     */
    private function mockDbQuery($resultData)
    {
        $this->mockMultipleDbQueries(array($resultData));
    }

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

    /**
     * Mock CSRF token validation
     *
     * @param string $validToken The valid CSRF token for this test
     */
    private function mockCsrfToken($validToken)
    {
        $GLOBALS['__test_csrf_token'] = $validToken;
    }

    /**
     * Mock staff permissions
     *
     * @param bool $hasPermission Whether user has staff permissions
     */
    private function mockStaffPermissions($hasPermission)
    {
        $GLOBALS['__test_staff_permission'] = $hasPermission;
    }

    // ============================================================
    // RED Phase Tests for linkExistingTicket() AJAX Endpoint
    // ============================================================

    /**
     * Test that linkExistingTicket() returns success response when link succeeds
     *
     * Expected behavior:
     * - Accepts child ID, parent ID, and CSRF token as parameters
     * - Validates inputs (numeric, positive)
     * - Validates CSRF token
     * - Checks staff permissions
     * - Calls plugin->linkTicket($childId, $parentId)
     * - Returns JSON response with structure:
     *   {
     *     "success": true,
     *     "message": "Tickets successfully linked",
     *     "data": []
     *   }
     */
    public function testLinkExistingTicketReturnsSuccessResponseWhenLinkSucceeds()
    {
        // Mock successful link operation (plugin->linkTicketByNumber returns true)
        // linkTicketByNumber needs:
        //   1. SELECT to convert parent number to ID (getTicketIdByNumber)
        //   2. SELECT to validate child exists
        //   3. SELECT to validate parent exists
        //   4. UPDATE to link tickets
        $this->mockMultipleDbQueries(array(
            array(array('ticket_id' => 1)), // Parent number -> ID conversion
            array(array('ticket_id' => 2)), // Child exists
            array(array('ticket_id' => 1)), // Parent exists
            true // UPDATE succeeds
        ));

        // Mock CSRF token validation
        $this->mockCsrfToken('valid-csrf-token-12345');

        // Mock staff permissions
        $this->mockStaffPermissions(true);

        // Call controller method with ticket NUMBER (not ID) for parent
        $childId = $this->testTickets['child1']['ticket_id'];
        $parentNumber = $this->testTickets['parent']['number']; // Use NUMBER!
        $response = $this->controller->linkExistingTicket($childId, $parentNumber, 'valid-csrf-token-12345');

        // Assert response structure
        $this->assertIsArray($response);
        $this->assertArrayHasKey('success', $response);
        $this->assertArrayHasKey('message', $response);
        $this->assertArrayHasKey('data', $response);

        // Assert success
        $this->assertTrue($response['success']);
        $this->assertEquals('Tickets successfully linked', $response['message']);
        $this->assertEmpty($response['data']);
    }

    /**
     * Test that linkExistingTicket() validates child ticket ID
     *
     * Expected behavior:
     * - Returns error response for invalid child ID (null, 0, negative, non-numeric)
     * - Does NOT call plugin->linkTicket()
     * - success = false
     * - message = "Invalid child ticket ID"
     */
    public function testLinkExistingTicketValidatesChildTicketId()
    {
        // Mock CSRF token validation
        $this->mockCsrfToken('valid-csrf-token-12345');

        // Mock staff permissions
        $this->mockStaffPermissions(true);

        // Test invalid child ticket IDs
        $invalidIds = array(null, 0, -1, 'abc');
        $parentId = $this->testTickets['parent']['ticket_id'];

        foreach ($invalidIds as $invalidId) {
            reset_test_db_queries(); // Reset query tracker
            $response = $this->controller->linkExistingTicket($invalidId, $parentId, 'valid-csrf-token-12345');

            $this->assertFalse($response['success']);
            $this->assertEquals('Invalid child ticket ID', $response['message']);
            $this->assertEmpty($response['data']);
        }

        // Verify plugin->linkTicket() was NEVER called
        $queries = get_test_db_queries();
        $this->assertEmpty($queries, 'No database queries should be executed for invalid input');
    }

    /**
     * Test that linkExistingTicket() validates parent ticket ID
     *
     * Expected behavior:
     * - Returns error response for invalid parent ID (null, 0, negative, non-numeric)
     * - Does NOT call plugin->linkTicket()
     * - success = false
     * - message = "Invalid parent ticket ID"
     */
    public function testLinkExistingTicketValidatesParentTicketId()
    {
        // Mock CSRF token validation
        $this->mockCsrfToken('valid-csrf-token-12345');

        // Mock staff permissions
        $this->mockStaffPermissions(true);

        // Test invalid parent ticket IDs
        $invalidIds = array(null, 0, -1, 'abc');
        $childId = $this->testTickets['child1']['ticket_id'];

        foreach ($invalidIds as $invalidId) {
            reset_test_db_queries(); // Reset query tracker
            $response = $this->controller->linkExistingTicket($childId, $invalidId, 'valid-csrf-token-12345');

            $this->assertFalse($response['success']);
            $this->assertEquals('Invalid parent ticket ID/number', $response['message']);
            $this->assertEmpty($response['data']);
        }

        // Verify plugin->linkTicket() was NEVER called
        $queries = get_test_db_queries();
        $this->assertEmpty($queries, 'No database queries should be executed for invalid input');
    }

    /**
     * Test that linkExistingTicket() validates CSRF token
     *
     * Expected behavior:
     * - Returns error response when CSRF token is missing
     * - Returns error response when CSRF token is invalid
     * - Does NOT call plugin->linkTicket()
     */
    public function testLinkExistingTicketValidatesCsrfToken()
    {
        // Mock valid CSRF token
        $this->mockCsrfToken('valid-csrf-token-12345');

        // Mock staff permissions
        $this->mockStaffPermissions(true);

        $childId = $this->testTickets['child1']['ticket_id'];
        $parentId = $this->testTickets['parent']['ticket_id'];

        // Test 1: Missing CSRF token
        $response = $this->controller->linkExistingTicket($childId, $parentId, null);

        $this->assertFalse($response['success']);
        $this->assertEquals('Missing CSRF token', $response['message']);
        $this->assertEmpty($response['data']);

        // Test 2: Invalid CSRF token
        reset_test_db_queries(); // Reset query tracker
        $response = $this->controller->linkExistingTicket($childId, $parentId, 'invalid-token');

        $this->assertFalse($response['success']);
        $this->assertEquals('Invalid CSRF token', $response['message']);
        $this->assertEmpty($response['data']);

        // Verify plugin->linkTicket() was NEVER called
        $queries = get_test_db_queries();
        $this->assertEmpty($queries, 'No database queries should be executed for invalid CSRF token');
    }

    /**
     * Test that linkExistingTicket() checks staff permissions
     *
     * Expected behavior:
     * - Returns error response when user is not staff
     * - Does NOT call plugin->linkTicket()
     * - success = false
     * - message = "Permission denied"
     */
    public function testLinkExistingTicketChecksStaffPermissions()
    {
        // Mock valid CSRF token
        $this->mockCsrfToken('valid-csrf-token-12345');

        // Mock NO staff permissions
        $this->mockStaffPermissions(false);

        $childId = $this->testTickets['child1']['ticket_id'];
        $parentId = $this->testTickets['parent']['ticket_id'];
        $response = $this->controller->linkExistingTicket($childId, $parentId, 'valid-csrf-token-12345');

        $this->assertFalse($response['success']);
        $this->assertEquals('Permission denied', $response['message']);
        $this->assertEmpty($response['data']);

        // Verify plugin->linkTicket() was NEVER called
        $queries = get_test_db_queries();
        $this->assertEmpty($queries, 'No database queries should be executed without permission');
    }

    /**
     * Test that linkExistingTicket() returns error when operation fails
     *
     * Expected behavior:
     * - Valid inputs but linkTicket() returns false (e.g., ticket not found, database error)
     * - Returns error response
     * - success = false
     * - message = "Failed to link tickets"
     */
    public function testLinkExistingTicketReturnsErrorWhenOperationFails()
    {
        // Mock FAILED link operation: Child ticket doesn't exist
        // Plugin's linkTicket() checks existence with SELECT queries
        $this->mockMultipleDbQueries(array(
            array(), // Child doesn't exist (empty result)
            array(array('ticket_id' => 1)) // Parent exists (not reached if child check fails first)
        ));

        // Mock valid CSRF token
        $this->mockCsrfToken('valid-csrf-token-12345');

        // Mock staff permissions
        $this->mockStaffPermissions(true);

        $childId = $this->testTickets['child1']['ticket_id'];
        $parentId = $this->testTickets['parent']['ticket_id'];
        $response = $this->controller->linkExistingTicket($childId, $parentId, 'valid-csrf-token-12345');

        $this->assertFalse($response['success']);
        $this->assertEquals('Failed to link tickets', $response['message']);
        $this->assertEmpty($response['data']);
    }

}
