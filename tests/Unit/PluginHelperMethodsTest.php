<?php
/**
 * Comprehensive Tests for Phase 2 Helper Methods
 *
 * Tests the following methods in SubticketPlugin:
 * - getChildren($parentId)
 * - getParent($childId)
 * - linkTicket($childId, $parentId)
 * - unlinkTicket($childId)
 * - isDescendant($ticketId, $ancestorId) - tested via linkTicket
 *
 * Test Strategy: Integration tests with mocked database
 * Phase: RED (tests written first, expect failures)
 */

namespace SubticketManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

// Load bootstrap FIRST (defines MockDbResult and TestableSubticketPlugin)
require_once dirname(__DIR__) . '/bootstrap.php';

class PluginHelperMethodsTest extends TestCase
{
    /** @var \TestableSubticketPlugin */
    private $plugin;

    /** @var array */
    private $testTickets = array();

    protected function setUp(): void
    {
        parent::setUp();

        // Reset global test query tracker
        reset_test_db_queries();

        // Create plugin instance (using testable wrapper)
        $this->plugin = new \TestableSubticketPlugin();

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
            ),
            'orphan' => array(
                'ticket_id' => 4,
                'number' => '100004',
                'subject' => 'Bob Johnson Orphan Ticket',
                'status' => 'Open',
                'status_id' => 1,
                'ticket_pid' => null,
                'created' => '2025-01-01 13:00:00'
            ),
            'grandchild' => array(
                'ticket_id' => 5,
                'number' => '100005',
                'subject' => 'Alice Brown Grandchild Ticket',
                'status' => 'Open',
                'status_id' => 1,
                'ticket_pid' => 2,
                'created' => '2025-01-01 14:00:00'
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
    // Tests for getChildren($parentId)
    // ============================================================

    /**
     * Test that getChildren() returns empty array for ticket without children
     *
     * Expected behavior:
     * - Query executed with correct parent ID
     * - Returns empty array when no children exist
     */
    public function testGetChildrenReturnsEmptyArrayForTicketWithoutChildren()
    {
        // Mock database to return no children
        $this->mockDbQuery(array());

        $result = $this->plugin->getChildren($this->testTickets['orphan']['ticket_id']);

        // Assert result is empty array
        $this->assertIsArray($result, 'getChildren should return an array');
        $this->assertEmpty($result, 'getChildren should return empty array for ticket without children');

        // Assert correct SQL was executed (db_input format - osTicket standard)
        $queries = get_test_db_queries();
        $this->assertCount(1, $queries, 'Exactly one query should be executed');
        $this->assertStringContainsString('WHERE t.ticket_pid = ' . $this->testTickets['orphan']['ticket_id'], $queries[0]['query']);
    }

    /**
     * Test that getChildren() returns correct children data
     *
     * Expected behavior:
     * - Returns array of child ticket data
     * - Each child has: ticket_id, number, ticket_pid, subject, status, created
     * - Children ordered by created ASC
     */
    public function testGetChildrenReturnsCorrectChildrenData()
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

        $result = $this->plugin->getChildren($this->testTickets['parent']['ticket_id']);

        // Assert correct data structure
        $this->assertIsArray($result);
        $this->assertCount(2, $result, 'Should return 2 children');

        // Assert first child data (API returns 'id' not 'ticket_id')
        $this->assertEquals($this->testTickets['child1']['ticket_id'], $result[0]['id']);
        $this->assertEquals($this->testTickets['child1']['number'], $result[0]['number']);
        // Subject now comes from COALESCE/CONCAT in JOIN query
        $this->assertEquals($this->testTickets['child1']['subject'], $result[0]['subject']);

        // Assert second child data (API returns 'id' not 'ticket_id')
        $this->assertEquals($this->testTickets['child2']['ticket_id'], $result[1]['id']);
        $this->assertEquals($this->testTickets['child2']['number'], $result[1]['number']);
        $this->assertEquals($this->testTickets['child2']['subject'], $result[1]['subject']);
    }

    /**
     * Test that getChildren() executes correct SQL query
     *
     * Expected behavior:
     * - Query joins ost_ticket, ost_user, ost_ticket_status, ost_user_email, ost_contact
     * - Query filters by ticket_pid
     * - Query orders by created ASC
     */
    public function testGetChildrenExecutesCorrectSqlQuery()
    {
        $this->mockDbQuery(array());

        $this->plugin->getChildren(1);

        $queries = get_test_db_queries();
        $this->assertCount(1, $queries);

        $query = $queries[0]['query'];

        // Assert query structure (JOIN format with db_input escaping)
        $this->assertStringContainsString('SELECT t.ticket_id, t.number', $query);
        $this->assertStringContainsString('cdata.subject', $query);
        $this->assertStringContainsString('FROM ost_ticket t', $query);
        $this->assertStringContainsString('LEFT JOIN ost_ticket__cdata cdata', $query);
        $this->assertStringContainsString('LEFT JOIN ost_ticket_status s', $query);
        $this->assertStringContainsString('WHERE t.ticket_pid = 1', $query);
        $this->assertStringContainsString('ORDER BY t.created ASC', $query);
    }

    // ============================================================
    // Tests for getParent($childId)
    // ============================================================

    /**
     * Test that getParent() returns null for root ticket
     *
     * Expected behavior:
     * - Query executed with correct child ID
     * - Returns null when ticket has no parent (ticket_pid is NULL)
     */
    public function testGetParentReturnsNullForRootTicket()
    {
        // Mock database to return no parent (empty result)
        $this->mockDbQuery(array());

        $result = $this->plugin->getParent($this->testTickets['parent']['ticket_id']);

        // Assert result is null
        $this->assertNull($result, 'getParent should return null for root ticket');

        // Assert correct SQL was executed (db_input format)
        $queries = get_test_db_queries();
        $this->assertCount(1, $queries, 'Exactly one query should be executed');
        $this->assertStringContainsString('INNER JOIN ost_ticket p ON t.ticket_pid = p.ticket_id', $queries[0]['query']);
        $this->assertStringContainsString('WHERE t.ticket_id = ' . $this->testTickets['parent']['ticket_id'], $queries[0]['query']);
    }

    /**
     * Test that getParent() returns correct parent data
     *
     * Expected behavior:
     * - Returns array with parent ticket data
     * - Parent has: ticket_id, number, subject, status
     */
    public function testGetParentReturnsCorrectParentData()
    {
        // Mock database to return parent data
        $mockParent = array(
            'ticket_id' => $this->testTickets['parent']['ticket_id'],
            'number' => $this->testTickets['parent']['number'],
            'subject' => $this->testTickets['parent']['subject'],
            'status' => $this->testTickets['parent']['status']
        );

        $this->mockDbQuery(array($mockParent));

        $result = $this->plugin->getParent($this->testTickets['child1']['ticket_id']);

        // Assert correct data structure
        $this->assertIsArray($result);
        $this->assertArrayHasKey('ticket_id', $result);
        $this->assertArrayHasKey('number', $result);
        $this->assertArrayHasKey('subject', $result);
        $this->assertArrayHasKey('status', $result);

        // Assert correct values
        $this->assertEquals($this->testTickets['parent']['ticket_id'], $result['ticket_id']);
        $this->assertEquals($this->testTickets['parent']['number'], $result['number']);
        $this->assertEquals($this->testTickets['parent']['subject'], $result['subject']);
        $this->assertEquals($this->testTickets['parent']['status'], $result['status']);
    }

    /**
     * Test that getParent() executes correct SQL query
     *
     * Expected behavior:
     * - Query joins ost_ticket with itself (child and parent)
     * - Query filters by child ticket_id
     */
    public function testGetParentExecutesCorrectSqlQuery()
    {
        $this->mockDbQuery(array());

        $this->plugin->getParent(2);

        $queries = get_test_db_queries();
        $this->assertCount(1, $queries);

        $query = $queries[0]['query'];

        // Assert query structure (db_input format)
        $this->assertStringContainsString('SELECT p.ticket_id, p.number', $query);
        $this->assertStringContainsString('FROM ost_ticket t', $query);
        $this->assertStringContainsString('INNER JOIN ost_ticket p ON t.ticket_pid = p.ticket_id', $query);
        $this->assertStringContainsString('WHERE t.ticket_id = 2', $query);
    }

    // ============================================================
    // Tests for linkTicket($childId, $parentId)
    // ============================================================

    /**
     * Test that linkTicket() successfully links tickets
     *
     * Expected behavior:
     * - Validates both tickets exist
     * - Updates child ticket's ticket_pid
     * - Returns true on success
     */
    public function testLinkTicketSuccessfullyLinksTickets()
    {
        // Mock database for validation queries (tickets exist)
        $this->mockMultipleDbQueries(array(
            // First query: Check child exists
            array(array('ticket_id' => $this->testTickets['orphan']['ticket_id'])),
            // Second query: Check parent exists
            array(array('ticket_id' => $this->testTickets['parent']['ticket_id'])),
            // Third query: Check circular dependency (no parent chain)
            array(),
            // Fourth query: UPDATE ticket_pid (success)
            true
        ));

        $result = $this->plugin->linkTicket(
            $this->testTickets['orphan']['ticket_id'],
            $this->testTickets['parent']['ticket_id']
        );

        // Assert success
        $this->assertTrue($result, 'linkTicket should return true on success');

        // Assert correct queries executed
        $queries = get_test_db_queries();
        $this->assertGreaterThanOrEqual(3, count($queries), 'Should execute validation and update queries');

        // Check UPDATE query (new format with db_input, no prepared statements)
        $updateQuery = end($queries);
        $this->assertStringContainsString(
            'UPDATE ost_ticket SET ticket_pid = ' . $this->testTickets['parent']['ticket_id'],
            $updateQuery['query']
        );
        $this->assertStringContainsString(
            'WHERE ticket_id = ' . $this->testTickets['orphan']['ticket_id'],
            $updateQuery['query']
        );
    }

    /**
     * Test that linkTicket() fails when child doesn't exist
     *
     * Expected behavior:
     * - Validates child ticket exists
     * - Returns false if child not found
     * - No UPDATE query executed
     */
    public function testLinkTicketFailsWhenChildDoesNotExist()
    {
        // Mock database: child doesn't exist (empty result)
        $this->mockMultipleDbQueries(array(
            // First query: Check child exists (EMPTY = doesn't exist)
            array(),
            // Second query: Check parent exists
            array(array('ticket_id' => $this->testTickets['parent']['ticket_id']))
        ));

        $result = $this->plugin->linkTicket(999, $this->testTickets['parent']['ticket_id']);

        // Assert failure
        $this->assertFalse($result, 'linkTicket should return false when child does not exist');

        // Assert no UPDATE query executed
        $queries = get_test_db_queries();
        $lastQuery = end($queries);
        $this->assertStringNotContainsString('UPDATE', $lastQuery['query'], 'Should not execute UPDATE when validation fails');
    }

    /**
     * Test that linkTicket() fails when parent doesn't exist
     *
     * Expected behavior:
     * - Validates parent ticket exists
     * - Returns false if parent not found
     * - No UPDATE query executed
     */
    public function testLinkTicketFailsWhenParentDoesNotExist()
    {
        // Mock database: parent doesn't exist
        $this->mockMultipleDbQueries(array(
            // First query: Check child exists
            array(array('ticket_id' => $this->testTickets['orphan']['ticket_id'])),
            // Second query: Check parent exists (EMPTY = doesn't exist)
            array()
        ));

        $result = $this->plugin->linkTicket($this->testTickets['orphan']['ticket_id'], 999);

        // Assert failure
        $this->assertFalse($result, 'linkTicket should return false when parent does not exist');

        // Assert no UPDATE query executed
        $queries = get_test_db_queries();
        $lastQuery = end($queries);
        $this->assertStringNotContainsString('UPDATE', $lastQuery['query'], 'Should not execute UPDATE when validation fails');
    }

    /**
     * Test that linkTicket() prevents circular dependency
     *
     * Expected behavior:
     * - Calls isDescendant() to check circular dependency
     * - Returns false if parent is descendant of child
     * - No UPDATE query executed
     *
     * Scenario: Try to make grandparent a child of grandchild (circular)
     * Hierarchy: Parent (1) -> Child (2) -> Grandchild (5)
     * Try: linkTicket(1, 5) = Make Parent child of Grandchild (CIRCULAR!)
     */
    public function testLinkTicketPreventsCircularDependency()
    {
        // Mock database for circular dependency detection
        $this->mockMultipleDbQueries(array(
            // First query: Check child exists (Parent ticket)
            array(array('ticket_id' => $this->testTickets['parent']['ticket_id'])),
            // Second query: Check parent exists (Grandchild ticket)
            array(array('ticket_id' => $this->testTickets['grandchild']['ticket_id'])),
            // Third query: isDescendant check - Grandchild's parent chain
            // Grandchild (5) -> Child (2) -> Parent (1)
            // We're checking if ticket 5 is descendant of ticket 1 (YES!)
            array(array('ticket_pid' => $this->testTickets['child1']['ticket_id'])), // Grandchild's parent is Child
            array(array('ticket_pid' => $this->testTickets['parent']['ticket_id'])), // Child's parent is Parent
            array(array('ticket_pid' => null)) // Parent has no parent
        ));

        $result = $this->plugin->linkTicket(
            $this->testTickets['parent']['ticket_id'],   // Try to make Parent a child
            $this->testTickets['grandchild']['ticket_id'] // of Grandchild (CIRCULAR!)
        );

        // Assert failure
        $this->assertFalse($result, 'linkTicket should return false when circular dependency detected');

        // Assert no UPDATE query executed
        $queries = get_test_db_queries();
        foreach ($queries as $q) {
            $this->assertStringNotContainsString('UPDATE ost_ticket SET ticket_pid', $q['query'],
                'Should not execute UPDATE when circular dependency detected');
        }
    }

    /**
     * Test that linkTicket() prevents self-linking (edge case)
     *
     * A ticket should not be able to become its own parent to prevent:
     * - Infinite loops in hierarchy traversal
     * - Circular references in parent-child relationships
     * - Data integrity issues
     *
     * Expected behavior:
     * - Explicit check: if ($childId == $parentId) return false
     * - No database queries executed (early return)
     * - Error logged
     */
    public function testLinkTicketPreventsSelflinking()
    {
        // Edge case: Try to link ticket to itself
        // No need to mock DB queries - check happens before any DB access

        $result = $this->plugin->linkTicket(1, 1); // Same ticket as parent and child

        // Expected behavior: Returns false (self-linking prevented)
        $this->assertFalse($result, 'linkTicket should prevent self-linking');

        // Verify NO database queries were executed (early return)
        $queries = get_test_db_queries();
        $this->assertCount(0, $queries, 'No DB queries should be executed for self-linking attempt');
    }

    // ============================================================
    // Tests for unlinkTicket($childId)
    // ============================================================

    /**
     * Test that unlinkTicket() successfully unlinks ticket
     *
     * Expected behavior:
     * - Updates ticket_pid to NULL
     * - Returns true on success
     */
    public function testUnlinkTicketSuccessfullyUnlinks()
    {
        // Mock database: UPDATE succeeds
        $this->mockDbQuery(true); // True = query success

        $result = $this->plugin->unlinkTicket($this->testTickets['child1']['ticket_id']);

        // Assert success
        $this->assertTrue($result, 'unlinkTicket should return true on success');

        // Assert correct SQL executed (new format with db_input, no params array)
        $queries = get_test_db_queries();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('UPDATE ost_ticket SET ticket_pid = NULL', $queries[0]['query']);
        $this->assertStringContainsString(
            'WHERE ticket_id = ' . $this->testTickets['child1']['ticket_id'],
            $queries[0]['query']
        );
    }

    /**
     * Test that unlinkTicket() handles non-existent ticket gracefully (idempotent)
     *
     * Expected behavior:
     * - Executes UPDATE query even if ticket doesn't exist (no validation)
     * - Returns true because UPDATE succeeds (0 rows affected is still success)
     *
     * Note: unlinkTicket() is idempotent - calling it on non-existent or
     * already-unlinked tickets returns true. Only actual database errors return false.
     */
    public function testUnlinkTicketHandlesNonExistentTicket()
    {
        // Mock database: UPDATE succeeds (even on non-existent ticket)
        // In real MySQL, UPDATE on non-existent row returns success (0 rows affected)
        $this->mockDbQuery(true); // True = query success

        $result = $this->plugin->unlinkTicket(999);

        // Assert success (idempotent behavior)
        $this->assertTrue($result, 'unlinkTicket should return true even for non-existent ticket (idempotent)');

        // Assert UPDATE was attempted
        $queries = get_test_db_queries();
        $this->assertCount(1, $queries);
        $this->assertStringContainsString('UPDATE ost_ticket SET ticket_pid = NULL', $queries[0]['query']);
    }

    /**
     * Test that unlinkTicket() can unlink already unlinked ticket (idempotent)
     *
     * Expected behavior:
     * - UPDATE executes successfully even if ticket_pid is already NULL
     * - Returns true (idempotent operation)
     */
    public function testUnlinkTicketIsIdempotent()
    {
        // Mock database: UPDATE succeeds (even though already unlinked)
        $this->mockDbQuery(true);

        $result = $this->plugin->unlinkTicket($this->testTickets['orphan']['ticket_id']);

        // Assert success (idempotent)
        $this->assertTrue($result, 'unlinkTicket should be idempotent');
    }

    /**
     * Test that unlinkTicket() returns false on actual database error
     *
     * Expected behavior:
     * - Returns false if UPDATE query fails due to database error
     */
    public function testUnlinkTicketReturnsFalseOnDatabaseError()
    {
        // Mock database: UPDATE fails due to database error
        $this->mockDbQuery(false); // False = actual database error

        $result = $this->plugin->unlinkTicket(1);

        // Assert failure
        $this->assertFalse($result, 'unlinkTicket should return false on database error');
    }

    // ============================================================
    // Edge Case Tests
    // ============================================================

    /**
     * Test that getChildren() handles NULL parent ID
     *
     * Expected behavior:
     * - Returns empty array or handles gracefully
     */
    public function testGetChildrenHandlesNullParentId()
    {
        $this->mockDbQuery(array());

        $result = $this->plugin->getChildren(null);

        $this->assertIsArray($result);
        // May return empty array or tickets with ticket_pid = NULL (orphans)
        // Implementation-dependent behavior
    }

    /**
     * Test that getParent() handles NULL child ID
     *
     * Expected behavior:
     * - Returns null or handles gracefully
     */
    public function testGetParentHandlesNullChildId()
    {
        $this->mockDbQuery(array());

        $result = $this->plugin->getParent(null);

        $this->assertNull($result, 'getParent should return null for invalid child ID');
    }

    /**
     * Test that linkTicket() handles deeply nested hierarchy (>10 levels)
     *
     * Expected behavior:
     * - isDescendant() has max depth limit (10)
     * - Should allow linking at max depth
     * - Should not allow linking beyond max depth if would create circular ref
     */
    public function testLinkTicketHandlesDeeplyNestedHierarchy()
    {
        // Create chain: 1 -> 2 -> 3 -> 4 -> 5 -> 6 -> 7 -> 8 -> 9 -> 10
        // Try to link: 10 -> 1 (should fail - circular, depth = 10)

        $this->mockMultipleDbQueries(array(
            array(array('ticket_id' => 10)), // Child exists
            array(array('ticket_id' => 1)),  // Parent exists
            // isDescendant checks (max 10 iterations)
            // Trace from 1 upwards: 1 -> 2 -> 3 -> ... -> 10
            array(array('ticket_pid' => 2)),  // 1's parent is 2
            array(array('ticket_pid' => 3)),  // 2's parent is 3
            array(array('ticket_pid' => 4)),  // 3's parent is 4
            array(array('ticket_pid' => 5)),  // 4's parent is 5
            array(array('ticket_pid' => 6)),  // 5's parent is 6
            array(array('ticket_pid' => 7)),  // 6's parent is 7
            array(array('ticket_pid' => 8)),  // 7's parent is 8
            array(array('ticket_pid' => 9)),  // 8's parent is 9
            array(array('ticket_pid' => 10)), // 9's parent is 10 (FOUND CIRCULAR!)
            array(array('ticket_pid' => null)) // 10's parent is null (shouldn't reach here)
        ));

        $result = $this->plugin->linkTicket(10, 1);

        // Should fail after detecting circular dependency
        $this->assertFalse($result, 'linkTicket should prevent circular dependency at max depth');
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
}
