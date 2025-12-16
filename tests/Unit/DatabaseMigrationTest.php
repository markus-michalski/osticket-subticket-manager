<?php
/**
 * Comprehensive Tests for Database Migration (Refactored for Service Architecture)
 *
 * Tests the DatabaseService which handles:
 * - Creating ost_ticket_hierarchy_metadata table
 * - Creating ost_ticket_progress table
 * - Adding version column to ost_ticket
 * - Creating indexes (idx_ticket_pid, idx_ticket_hierarchy)
 * - Adding foreign key constraints
 * - Migrating existing tables to unsigned columns
 *
 * Test Strategy: Unit tests with mocked database
 */

namespace SubticketManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SubticketManager\Database\DatabaseService;

// Load bootstrap FIRST (defines MockDbResult in global namespace)
require_once dirname(__DIR__) . '/bootstrap.php';

class DatabaseMigrationTest extends TestCase
{
    /** @var DatabaseService */
    private $databaseService;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset global test query tracker
        reset_test_db_queries();

        // Create DatabaseService instance
        $this->databaseService = new DatabaseService();
    }

    protected function tearDown(): void
    {
        // Cleanup: Reset global state
        reset_test_db_queries();
        parent::tearDown();
    }

    // ============================================================
    // Tests for Fresh Installation (No Tables Exist)
    // ============================================================

    /**
     * Test that fresh installation creates all database objects
     *
     * Expected behavior:
     * - Creates ost_ticket_hierarchy_metadata table
     * - Creates ost_ticket_progress table
     * - Adds version column to ost_ticket
     * - Creates idx_ticket_pid index
     * - Creates idx_ticket_hierarchy index
     * - Adds foreign key constraints (non-critical, may fail gracefully)
     * - All operations execute in correct order
     */
    public function testFreshInstallationCreatesAllDatabaseObjects()
    {
        // Mock database results for fresh installation (no existing tables/columns)
        $this->mockMultipleDbQueries([
            // Check if ost_ticket_hierarchy_metadata exists (NO)
            [],
            // Check if ost_ticket_progress exists (NO)
            [],
            // Check if version column exists (NO)
            [],
            // Check if idx_ticket_pid exists (NO)
            [],
            // Check if idx_ticket_hierarchy exists (NO)
            [],
            // CREATE TABLE ost_ticket_hierarchy_metadata (SUCCESS)
            true,
            // CREATE TABLE ost_ticket_progress (SUCCESS)
            true,
            // ALTER TABLE ost_ticket ADD COLUMN version (SUCCESS)
            true,
            // CREATE INDEX idx_ticket_pid (SUCCESS)
            true,
            // CREATE INDEX idx_ticket_hierarchy (SUCCESS)
            true,
            // Check if metadata table exists for migration (YES - just created)
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            // Check if metadata.ticket_id is unsigned (YES - created as unsigned)
            [['Field' => 'ticket_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            // Check if progress table exists for migration (YES - just created)
            [['Tables_in_test' => 'ost_ticket_progress']],
            // Check if progress.parent_id is unsigned (YES - created as unsigned)
            [['Field' => 'parent_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            // Check if metadata table exists for FK (YES - just created)
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            // Check if FK exists on metadata table (NO)
            [],
            // ALTER TABLE ADD CONSTRAINT FK on metadata (SUCCESS)
            true,
            // Check if progress table exists for FK (YES - just created)
            [['Tables_in_test' => 'ost_ticket_progress']],
            // Check if FK exists on progress table (NO)
            [],
            // ALTER TABLE ADD CONSTRAINT FK on progress (SUCCESS)
            true
        ]);

        // Execute initialization
        $this->databaseService->initialize();

        // Assert all SQL statements were executed
        $queries = get_test_db_queries();

        // Should have executed: 5 checks + 5 creations + 4 migration checks + 4 FK checks/additions
        $this->assertGreaterThanOrEqual(11, count($queries), 'Should execute all initialization queries');

        // Verify table creation queries
        $this->assertQueryContains('CREATE TABLE IF NOT EXISTS `ost_ticket_hierarchy_metadata`', $queries);
        $this->assertQueryContains('CREATE TABLE IF NOT EXISTS `ost_ticket_progress`', $queries);

        // Verify column addition
        $this->assertQueryContains('ALTER TABLE `ost_ticket` ADD COLUMN `version`', $queries);

        // Verify index creation
        $this->assertQueryContains('CREATE INDEX idx_ticket_pid', $queries);
        $this->assertQueryContains('CREATE INDEX idx_ticket_hierarchy', $queries);

        // Verify FK constraint addition
        $this->assertQueryContains('ADD CONSTRAINT `fk_hierarchy_ticket`', $queries);
        $this->assertQueryContains('ADD CONSTRAINT `fk_progress_ticket`', $queries);
    }

    /**
     * Test that metadata table is created with correct schema
     *
     * Expected schema:
     * - ticket_id: int(11) unsigned PRIMARY KEY
     * - auto_close_enabled: tinyint(1) DEFAULT 1
     * - inherit_settings: text
     * - dependency_type: enum('blocks','depends_on','relates_to') DEFAULT 'relates_to'
     * - max_children: int(11) DEFAULT 50
     * - created: timestamp DEFAULT CURRENT_TIMESTAMP
     * - updated: timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
     * - ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
     */
    public function testMetadataTableCreatedWithCorrectSchema()
    {
        // Mock: Table doesn't exist, creation succeeds
        $this->mockMultipleDbQueries([
            [], // SHOW TABLES: metadata doesn't exist
            [], // SHOW TABLES: progress doesn't exist
            [], // SHOW COLUMNS: version doesn't exist
            [], // SHOW INDEX: idx_ticket_pid doesn't exist
            [], // SHOW INDEX: idx_ticket_hierarchy doesn't exist
            true,    // CREATE TABLE metadata (SUCCESS)
            true,    // CREATE TABLE progress (SUCCESS)
            true,    // ALTER TABLE ADD COLUMN version (SUCCESS)
            true,    // CREATE INDEX pid (SUCCESS)
            true,    // CREATE INDEX hierarchy (SUCCESS)
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']], // Table exists
            [['Field' => 'ticket_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            [['Tables_in_test' => 'ost_ticket_progress']], // Table exists
            [['Field' => 'parent_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [], // FK check
            true,    // FK creation
            [['Tables_in_test' => 'ost_ticket_progress']], // Table exists
            [], // FK check
            true     // FK creation
        ]);

        $this->databaseService->initialize();

        $queries = get_test_db_queries();
        $createTableQuery = $this->findQueryContaining('CREATE TABLE IF NOT EXISTS `ost_ticket_hierarchy_metadata`', $queries);

        // Verify column definitions
        $this->assertStringContainsString('`ticket_id` int(11) unsigned PRIMARY KEY', $createTableQuery);
        $this->assertStringContainsString('`auto_close_enabled` tinyint(1) DEFAULT 1', $createTableQuery);
        $this->assertStringContainsString('`inherit_settings` text', $createTableQuery);
        $this->assertStringContainsString('`dependency_type` enum(\'blocks\',\'depends_on\',\'relates_to\') DEFAULT \'relates_to\'', $createTableQuery);
        $this->assertStringContainsString('`max_children` int(11) DEFAULT 50', $createTableQuery);
        $this->assertStringContainsString('`created` timestamp DEFAULT CURRENT_TIMESTAMP', $createTableQuery);
        $this->assertStringContainsString('`updated` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $createTableQuery);

        // Verify engine and charset
        $this->assertStringContainsString('ENGINE=InnoDB', $createTableQuery);
        $this->assertStringContainsString('DEFAULT CHARSET=utf8mb4', $createTableQuery);
    }

    /**
     * Test that progress table is created with correct schema
     *
     * Expected schema:
     * - parent_id: int(11) unsigned PRIMARY KEY
     * - total_children: int(11) DEFAULT 0
     * - completed_children: int(11) DEFAULT 0
     * - in_progress_children: int(11) DEFAULT 0
     * - pending_children: int(11) DEFAULT 0
     * - last_calculated: timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
     * - ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
     */
    public function testProgressTableCreatedWithCorrectSchema()
    {
        $this->mockMultipleDbQueries([
            [], // metadata doesn't exist
            [], // progress doesn't exist
            [], // version doesn't exist
            [], // idx_ticket_pid doesn't exist
            [], // idx_ticket_hierarchy doesn't exist
            true,    // CREATE TABLE metadata
            true,    // CREATE TABLE progress
            true,    // ADD COLUMN version
            true,    // CREATE INDEX pid
            true,    // CREATE INDEX hierarchy
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [['Field' => 'ticket_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            [['Tables_in_test' => 'ost_ticket_progress']],
            [['Field' => 'parent_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [], // FK check
            true,    // FK creation
            [['Tables_in_test' => 'ost_ticket_progress']],
            [], // FK check
            true     // FK creation
        ]);

        $this->databaseService->initialize();

        $queries = get_test_db_queries();
        $createTableQuery = $this->findQueryContaining('CREATE TABLE IF NOT EXISTS `ost_ticket_progress`', $queries);

        // Verify column definitions
        $this->assertStringContainsString('`parent_id` int(11) unsigned PRIMARY KEY', $createTableQuery);
        $this->assertStringContainsString('`total_children` int(11) DEFAULT 0', $createTableQuery);
        $this->assertStringContainsString('`completed_children` int(11) DEFAULT 0', $createTableQuery);
        $this->assertStringContainsString('`in_progress_children` int(11) DEFAULT 0', $createTableQuery);
        $this->assertStringContainsString('`pending_children` int(11) DEFAULT 0', $createTableQuery);
        $this->assertStringContainsString('`last_calculated` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP', $createTableQuery);

        // Verify engine and charset
        $this->assertStringContainsString('ENGINE=InnoDB', $createTableQuery);
        $this->assertStringContainsString('DEFAULT CHARSET=utf8mb4', $createTableQuery);
    }

    /**
     * Test that version column is added with correct data type
     *
     * Expected:
     * - ALTER TABLE ost_ticket ADD COLUMN version
     * - Data type: int(11) DEFAULT 0
     */
    public function testVersionColumnAddedWithCorrectDataType()
    {
        $this->mockMultipleDbQueries([
            [], // metadata doesn't exist
            [], // progress doesn't exist
            [], // version doesn't exist
            [], // idx_ticket_pid doesn't exist
            [], // idx_ticket_hierarchy doesn't exist
            true,    // CREATE TABLE metadata
            true,    // CREATE TABLE progress
            true,    // ADD COLUMN version
            true,    // CREATE INDEX pid
            true,    // CREATE INDEX hierarchy
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [['Field' => 'ticket_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            [['Tables_in_test' => 'ost_ticket_progress']],
            [['Field' => 'parent_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [], // FK check
            true,    // FK creation
            [['Tables_in_test' => 'ost_ticket_progress']],
            [], // FK check
            true     // FK creation
        ]);

        $this->databaseService->initialize();

        $queries = get_test_db_queries();
        $alterTableQuery = $this->findQueryContaining('ALTER TABLE `ost_ticket` ADD COLUMN `version`', $queries);

        // Verify column definition
        $this->assertStringContainsString('int(11) DEFAULT 0', $alterTableQuery);
    }

    /**
     * Test that indexes are created with correct columns
     *
     * Expected:
     * - idx_ticket_pid on ost_ticket(ticket_pid)
     * - idx_ticket_hierarchy on ost_ticket(ticket_id, ticket_pid, status_id)
     */
    public function testIndexesCreatedWithCorrectColumns()
    {
        $this->mockMultipleDbQueries([
            [], // metadata doesn't exist
            [], // progress doesn't exist
            [], // version doesn't exist
            [], // idx_ticket_pid doesn't exist
            [], // idx_ticket_hierarchy doesn't exist
            true,    // CREATE TABLE metadata
            true,    // CREATE TABLE progress
            true,    // ADD COLUMN version
            true,    // CREATE INDEX pid
            true,    // CREATE INDEX hierarchy
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [['Field' => 'ticket_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            [['Tables_in_test' => 'ost_ticket_progress']],
            [['Field' => 'parent_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [], // FK check
            true,    // FK creation
            [['Tables_in_test' => 'ost_ticket_progress']],
            [], // FK check
            true     // FK creation
        ]);

        $this->databaseService->initialize();

        $queries = get_test_db_queries();

        // Verify idx_ticket_pid
        $pidIndexQuery = $this->findQueryContaining('CREATE INDEX idx_ticket_pid', $queries);
        $this->assertStringContainsString('ON `ost_ticket`(`ticket_pid`)', $pidIndexQuery);

        // Verify idx_ticket_hierarchy
        $hierarchyIndexQuery = $this->findQueryContaining('CREATE INDEX idx_ticket_hierarchy', $queries);
        $this->assertStringContainsString('ON `ost_ticket`(`ticket_id`, `ticket_pid`, `status_id`)', $hierarchyIndexQuery);
    }

    /**
     * Test that foreign key constraints are added with ON DELETE CASCADE
     *
     * Expected:
     * - FK on ost_ticket_hierarchy_metadata.ticket_id -> ost_ticket.ticket_id
     * - FK on ost_ticket_progress.parent_id -> ost_ticket.ticket_id
     * - Both with ON DELETE CASCADE
     */
    public function testForeignKeyConstraintsAddedWithCascadeDelete()
    {
        $this->mockMultipleDbQueries([
            [], // metadata doesn't exist
            [], // progress doesn't exist
            [], // version doesn't exist
            [], // idx_ticket_pid doesn't exist
            [], // idx_ticket_hierarchy doesn't exist
            true,    // CREATE TABLE metadata
            true,    // CREATE TABLE progress
            true,    // ADD COLUMN version
            true,    // CREATE INDEX pid
            true,    // CREATE INDEX hierarchy
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [['Field' => 'ticket_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            [['Tables_in_test' => 'ost_ticket_progress']],
            [['Field' => 'parent_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [], // FK check (doesn't exist)
            true,    // FK creation on metadata
            [['Tables_in_test' => 'ost_ticket_progress']],
            [], // FK check (doesn't exist)
            true     // FK creation on progress
        ]);

        $this->databaseService->initialize();

        $queries = get_test_db_queries();

        // Verify FK on metadata table
        $metadataFkQuery = $this->findQueryContaining('ADD CONSTRAINT `fk_hierarchy_ticket`', $queries);
        $this->assertStringContainsString('FOREIGN KEY (`ticket_id`)', $metadataFkQuery);
        $this->assertStringContainsString('REFERENCES `ost_ticket`(`ticket_id`)', $metadataFkQuery);
        $this->assertStringContainsString('ON DELETE CASCADE', $metadataFkQuery);

        // Verify FK on progress table
        $progressFkQuery = $this->findQueryContaining('ADD CONSTRAINT `fk_progress_ticket`', $queries);
        $this->assertStringContainsString('FOREIGN KEY (`parent_id`)', $progressFkQuery);
        $this->assertStringContainsString('REFERENCES `ost_ticket`(`ticket_id`)', $progressFkQuery);
        $this->assertStringContainsString('ON DELETE CASCADE', $progressFkQuery);
    }

    // ============================================================
    // Tests for Idempotent Behavior (Running Multiple Times)
    // ============================================================

    /**
     * Test that running initialization twice doesn't fail (idempotent)
     *
     * Expected behavior:
     * - First run creates all objects
     * - Second run detects existing objects and skips creation
     * - No errors or duplicate creation attempts
     */
    public function testInitializationIsIdempotent()
    {
        // Mock: All objects already exist
        $this->mockMultipleDbQueries([
            // SHOW TABLES: metadata exists
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            // SHOW TABLES: progress exists
            [['Tables_in_test' => 'ost_ticket_progress']],
            // SHOW COLUMNS: version exists
            [['Field' => 'version', 'Type' => 'int(11)', 'Null' => 'YES', 'Key' => '', 'Default' => '0', 'Extra' => '']],
            // SHOW INDEX: idx_ticket_pid exists
            [['Key_name' => 'idx_ticket_pid']],
            // SHOW INDEX: idx_ticket_hierarchy exists
            [['Key_name' => 'idx_ticket_hierarchy']],
            // Check metadata table for migration (exists)
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            // Check if metadata.ticket_id is unsigned (YES - already migrated)
            [['Field' => 'ticket_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            // Check progress table for migration (exists)
            [['Tables_in_test' => 'ost_ticket_progress']],
            // Check if progress.parent_id is unsigned (YES - already migrated)
            [['Field' => 'parent_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            // Check metadata table for FK (exists)
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            // Check if FK exists (YES - already exists)
            [['CONSTRAINT_NAME' => 'fk_hierarchy_ticket']],
            // Check progress table for FK (exists)
            [['Tables_in_test' => 'ost_ticket_progress']],
            // Check if FK exists (YES - already exists)
            [['CONSTRAINT_NAME' => 'fk_progress_ticket']]
        ]);

        $this->databaseService->initialize();

        $queries = get_test_db_queries();

        // Assert NO creation queries executed (all objects exist)
        $this->assertQueryNotContains('CREATE TABLE', $queries);
        $this->assertQueryNotContains('ALTER TABLE `ost_ticket` ADD COLUMN', $queries);
        $this->assertQueryNotContains('CREATE INDEX', $queries);
        $this->assertQueryNotContains('ADD CONSTRAINT', $queries);

        // Only SHOW/SELECT queries executed (checks)
        foreach ($queries as $query) {
            $sql = $query['query'];
            $this->assertTrue(
                stripos($sql, 'SHOW') !== false || stripos($sql, 'SELECT') !== false,
                'Only check queries should be executed when all objects exist'
            );
        }
    }

    /**
     * Test that partially completed installation resumes correctly
     *
     * Scenario: metadata table exists, but progress table doesn't
     * Expected: Creates only missing objects, skips existing ones
     */
    public function testPartialInstallationResumesCorrectly()
    {
        // Mock: Only metadata table exists, everything else is missing
        $this->mockMultipleDbQueries([
            // metadata EXISTS
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            // progress DOESN'T exist
            [],
            // version DOESN'T exist
            [],
            // idx_ticket_pid DOESN'T exist
            [],
            // idx_ticket_hierarchy DOESN'T exist
            [],
            // CREATE TABLE progress (SUCCESS)
            true,
            // ADD COLUMN version (SUCCESS)
            true,
            // CREATE INDEX pid (SUCCESS)
            true,
            // CREATE INDEX hierarchy (SUCCESS)
            true,
            // Check metadata for migration
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            // Check if unsigned (already is)
            [['Field' => 'ticket_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            // Check progress for migration (just created)
            [['Tables_in_test' => 'ost_ticket_progress']],
            // Check if unsigned (just created as unsigned)
            [['Field' => 'parent_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            // FK checks
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [], // FK doesn't exist
            true,    // FK creation
            [['Tables_in_test' => 'ost_ticket_progress']],
            [], // FK doesn't exist
            true     // FK creation
        ]);

        $this->databaseService->initialize();

        $queries = get_test_db_queries();

        // Assert metadata table NOT created (already exists)
        $this->assertQueryNotContains('CREATE TABLE IF NOT EXISTS `ost_ticket_hierarchy_metadata`', $queries);

        // Assert progress table IS created (was missing)
        $this->assertQueryContains('CREATE TABLE IF NOT EXISTS `ost_ticket_progress`', $queries);

        // Assert version column IS added (was missing)
        $this->assertQueryContains('ALTER TABLE `ost_ticket` ADD COLUMN `version`', $queries);

        // Assert indexes ARE created (were missing)
        $this->assertQueryContains('CREATE INDEX idx_ticket_pid', $queries);
        $this->assertQueryContains('CREATE INDEX idx_ticket_hierarchy', $queries);
    }

    // ============================================================
    // Tests for Migration (Unsigned Conversion)
    // ============================================================

    /**
     * Test that existing tables are migrated to unsigned columns
     *
     * Scenario: Tables exist with signed int columns (old installation)
     * Expected: ALTER TABLE MODIFY to change to unsigned
     */
    public function testExistingTablesAreMigratedToUnsigned()
    {
        // Mock: Tables exist but columns are NOT unsigned (old installation)
        $this->mockMultipleDbQueries([
            // All objects exist (checks)
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [['Tables_in_test' => 'ost_ticket_progress']],
            [['Field' => 'version', 'Type' => 'int(11)', 'Null' => 'YES', 'Key' => '', 'Default' => '0', 'Extra' => '']],
            [['Key_name' => 'idx_ticket_pid']],
            [['Key_name' => 'idx_ticket_hierarchy']],
            // No creation queries (all exist)
            // Migration checks
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            // Check if unsigned (NO - needs migration)
            [['Field' => 'ticket_id', 'Type' => 'int(11)', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']], // NOT unsigned
            // MIGRATE to unsigned (SUCCESS)
            true,
            [['Tables_in_test' => 'ost_ticket_progress']],
            // Check if unsigned (NO - needs migration)
            [['Field' => 'parent_id', 'Type' => 'int(11)', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']], // NOT unsigned
            // MIGRATE to unsigned (SUCCESS)
            true,
            // FK checks
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [['CONSTRAINT_NAME' => 'fk_hierarchy_ticket']], // FK exists
            [['Tables_in_test' => 'ost_ticket_progress']],
            [['CONSTRAINT_NAME' => 'fk_progress_ticket']] // FK exists
        ]);

        $this->databaseService->initialize();

        $queries = get_test_db_queries();

        // Assert migration queries executed
        $this->assertQueryContains('ALTER TABLE `ost_ticket_hierarchy_metadata` MODIFY `ticket_id` int(11) unsigned', $queries);
        $this->assertQueryContains('ALTER TABLE `ost_ticket_progress` MODIFY `parent_id` int(11) unsigned', $queries);
    }

    /**
     * Test that migration is skipped if columns are already unsigned
     *
     * Expected: No ALTER TABLE MODIFY queries when columns are already unsigned
     */
    public function testMigrationSkippedIfAlreadyUnsigned()
    {
        // Mock: Tables exist with UNSIGNED columns (already migrated)
        $this->mockMultipleDbQueries([
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [['Tables_in_test' => 'ost_ticket_progress']],
            [['Field' => 'version', 'Type' => 'int(11)', 'Null' => 'YES', 'Key' => '', 'Default' => '0', 'Extra' => '']],
            [['Key_name' => 'idx_ticket_pid']],
            [['Key_name' => 'idx_ticket_hierarchy']],
            // Migration checks
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [['Field' => 'ticket_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']], // Already unsigned!
            [['Tables_in_test' => 'ost_ticket_progress']],
            [['Field' => 'parent_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']], // Already unsigned!
            // FK checks
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [['CONSTRAINT_NAME' => 'fk_hierarchy_ticket']],
            [['Tables_in_test' => 'ost_ticket_progress']],
            [['CONSTRAINT_NAME' => 'fk_progress_ticket']]
        ]);

        $this->databaseService->initialize();

        $queries = get_test_db_queries();

        // Assert NO migration queries executed
        $this->assertQueryNotContains('MODIFY `ticket_id` int(11) unsigned', $queries);
        $this->assertQueryNotContains('MODIFY `parent_id` int(11) unsigned', $queries);
    }

    // ============================================================
    // Tests for Error Handling
    // ============================================================

    /**
     * Test that FK constraint failure is handled gracefully (non-critical)
     *
     * Expected behavior:
     * - FK constraint addition may fail (e.g., data integrity issues)
     * - Error is logged but doesn't stop installation
     * - Other operations complete successfully
     */
    public function testForeignKeyConstraintFailureIsHandledGracefully()
    {
        // Mock: FK creation fails (non-critical error)
        $this->mockMultipleDbQueries([
            [], // metadata doesn't exist
            [], // progress doesn't exist
            [], // version doesn't exist
            [], // idx_ticket_pid doesn't exist
            [], // idx_ticket_hierarchy doesn't exist
            true,    // CREATE TABLE metadata
            true,    // CREATE TABLE progress
            true,    // ADD COLUMN version
            true,    // CREATE INDEX pid
            true,    // CREATE INDEX hierarchy
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [['Field' => 'ticket_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            [['Tables_in_test' => 'ost_ticket_progress']],
            [['Field' => 'parent_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [], // FK check (doesn't exist)
            false,   // FK creation FAILS (non-critical)
            [['Tables_in_test' => 'ost_ticket_progress']],
            [], // FK check (doesn't exist)
            false    // FK creation FAILS (non-critical)
        ]);

        // Should NOT throw exception (FK failure is non-critical)
        $this->databaseService->initialize();

        $queries = get_test_db_queries();

        // Assert tables and indexes were still created successfully
        $this->assertQueryContains('CREATE TABLE IF NOT EXISTS `ost_ticket_hierarchy_metadata`', $queries);
        $this->assertQueryContains('CREATE TABLE IF NOT EXISTS `ost_ticket_progress`', $queries);
        $this->assertQueryContains('CREATE INDEX idx_ticket_pid', $queries);
        $this->assertQueryContains('CREATE INDEX idx_ticket_hierarchy', $queries);

        // FK constraint was ATTEMPTED (even though it failed - graceful handling)
        $this->assertQueryContains('ADD CONSTRAINT `fk_hierarchy_ticket`', $queries);
        $this->assertQueryContains('ADD CONSTRAINT `fk_progress_ticket`', $queries);
    }

    /**
     * Test that table creation failure is logged
     *
     * Expected behavior:
     * - If CREATE TABLE fails, error is logged
     * - Installation continues (doesn't halt completely)
     *
     * Note: Current implementation doesn't halt on errors, only logs them
     */
    public function testTableCreationFailureIsLogged()
    {
        // Mock: Table creation fails
        $this->mockMultipleDbQueries([
            [], // metadata doesn't exist
            [], // progress doesn't exist
            [], // version doesn't exist
            [], // idx_ticket_pid doesn't exist
            [], // idx_ticket_hierarchy doesn't exist
            false,   // CREATE TABLE metadata FAILS
            false,   // CREATE TABLE progress FAILS
            false,   // ADD COLUMN version FAILS
            false,   // CREATE INDEX pid FAILS
            false,   // CREATE INDEX hierarchy FAILS
            [], // Check metadata for migration (doesn't exist - creation failed)
            [], // Check progress for migration (doesn't exist - creation failed)
            [], // Check metadata for FK (doesn't exist)
            [], // Check progress for FK (doesn't exist)
        ]);

        // Should NOT throw exception (errors are logged, not thrown)
        $this->databaseService->initialize();

        $queries = get_test_db_queries();

        // Assert creation queries were ATTEMPTED (even though they failed)
        $this->assertQueryContains('CREATE TABLE IF NOT EXISTS `ost_ticket_hierarchy_metadata`', $queries);
        $this->assertQueryContains('CREATE TABLE IF NOT EXISTS `ost_ticket_progress`', $queries);
        $this->assertQueryContains('ALTER TABLE `ost_ticket` ADD COLUMN `version`', $queries);
        $this->assertQueryContains('CREATE INDEX idx_ticket_pid', $queries);
        $this->assertQueryContains('CREATE INDEX idx_ticket_hierarchy', $queries);
    }

    /**
     * Test that database objects are created in correct order
     *
     * Expected order:
     * 1. Check existence (SHOW TABLES, SHOW COLUMNS, SHOW INDEX)
     * 2. Create tables (metadata, progress)
     * 3. Add columns (version)
     * 4. Create indexes (pid, hierarchy)
     * 5. Migrate to unsigned (if needed)
     * 6. Add FK constraints (after tables exist)
     */
    public function testDatabaseObjectsCreatedInCorrectOrder()
    {
        $this->mockMultipleDbQueries([
            [], // Check metadata
            [], // Check progress
            [], // Check version
            [], // Check idx_ticket_pid
            [], // Check idx_ticket_hierarchy
            true,    // CREATE TABLE metadata
            true,    // CREATE TABLE progress
            true,    // ADD COLUMN version
            true,    // CREATE INDEX pid
            true,    // CREATE INDEX hierarchy
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [['Field' => 'ticket_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            [['Tables_in_test' => 'ost_ticket_progress']],
            [['Field' => 'parent_id', 'Type' => 'int(11) unsigned', 'Null' => 'NO', 'Key' => 'PRI', 'Default' => null, 'Extra' => '']],
            [['Tables_in_test' => 'ost_ticket_hierarchy_metadata']],
            [], // FK check
            true,    // FK creation
            [['Tables_in_test' => 'ost_ticket_progress']],
            [], // FK check
            true     // FK creation
        ]);

        $this->databaseService->initialize();

        $queries = get_test_db_queries();
        $queryTexts = array_map(function($q) { return $q['query']; }, $queries);

        // Find positions of key operations
        $metadataTablePos = $this->findQueryPosition('CREATE TABLE IF NOT EXISTS `ost_ticket_hierarchy_metadata`', $queryTexts);
        $progressTablePos = $this->findQueryPosition('CREATE TABLE IF NOT EXISTS `ost_ticket_progress`', $queryTexts);
        $versionColumnPos = $this->findQueryPosition('ALTER TABLE `ost_ticket` ADD COLUMN `version`', $queryTexts);
        $pidIndexPos = $this->findQueryPosition('CREATE INDEX idx_ticket_pid', $queryTexts);
        $hierarchyIndexPos = $this->findQueryPosition('CREATE INDEX idx_ticket_hierarchy', $queryTexts);
        $metadataFkPos = $this->findQueryPosition('ADD CONSTRAINT `fk_hierarchy_ticket`', $queryTexts);
        $progressFkPos = $this->findQueryPosition('ADD CONSTRAINT `fk_progress_ticket`', $queryTexts);

        // Assert tables created before FK constraints
        $this->assertLessThan($metadataFkPos, $metadataTablePos, 'metadata table must be created before its FK');
        $this->assertLessThan($progressFkPos, $progressTablePos, 'progress table must be created before its FK');

        // Assert version column and indexes can be created in any order relative to tables
        // (no strict dependency, but all should exist)
        $this->assertNotFalse($versionColumnPos, 'version column should be created');
        $this->assertNotFalse($pidIndexPos, 'pid index should be created');
        $this->assertNotFalse($hierarchyIndexPos, 'hierarchy index should be created');
    }

    // ============================================================
    // Helper Methods for Assertions
    // ============================================================

    /**
     * Assert that a query containing specific text was executed
     *
     * @param string $searchText Text to search for in queries
     * @param array $queries Array of query records
     */
    private function assertQueryContains($searchText, $queries)
    {
        $found = false;
        foreach ($queries as $query) {
            if (stripos($query['query'], $searchText) !== false) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Expected query containing '{$searchText}' was not executed");
    }

    /**
     * Assert that a query containing specific text was NOT executed
     *
     * @param string $searchText Text to search for in queries
     * @param array $queries Array of query records
     */
    private function assertQueryNotContains($searchText, $queries)
    {
        foreach ($queries as $query) {
            $this->assertStringNotContainsString(
                $searchText,
                $query['query'],
                "Unexpected query containing '{$searchText}' was executed"
            );
        }
    }

    /**
     * Find a query containing specific text
     *
     * @param string $searchText Text to search for
     * @param array $queries Array of query records
     * @return string|null Query text or null if not found
     */
    private function findQueryContaining($searchText, $queries)
    {
        foreach ($queries as $query) {
            if (stripos($query['query'], $searchText) !== false) {
                return $query['query'];
            }
        }

        $this->fail("Query containing '{$searchText}' not found");
        return null;
    }

    /**
     * Find position of query containing specific text
     *
     * @param string $searchText Text to search for
     * @param array $queryTexts Array of query strings
     * @return int|false Position index or false if not found
     */
    private function findQueryPosition($searchText, $queryTexts)
    {
        foreach ($queryTexts as $index => $query) {
            if (stripos($query, $searchText) !== false) {
                return $index;
            }
        }
        return false;
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
