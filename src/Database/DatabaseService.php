<?php

declare(strict_types=1);

namespace SubticketManager\Database;

/**
 * DatabaseService - Handles database initialization and schema management
 *
 * Manages:
 * - Table creation (ost_ticket_hierarchy_metadata, ost_ticket_progress)
 * - Index creation
 * - Column migrations
 * - Foreign key constraints
 *
 * @package SubticketManager
 */
final class DatabaseService
{
    /**
     * Initialize database tables and indexes
     */
    public function initialize(): void
    {
        $this->log('Database initialization started');

        $sql = [];

        // Create hierarchy metadata table
        if (!$this->tableExists('ost_ticket_hierarchy_metadata')) {
            $this->log('Creating table', 'ost_ticket_hierarchy_metadata');
            $sql[] = "CREATE TABLE IF NOT EXISTS `ost_ticket_hierarchy_metadata` (
                `ticket_id` int(11) unsigned PRIMARY KEY,
                `auto_close_enabled` tinyint(1) DEFAULT 1,
                `inherit_settings` text,
                `dependency_type` enum('blocks','depends_on','relates_to') DEFAULT 'relates_to',
                `max_children` int(11) DEFAULT 50,
                `created` timestamp DEFAULT CURRENT_TIMESTAMP,
                `updated` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        }

        // Create progress table
        if (!$this->tableExists('ost_ticket_progress')) {
            $this->log('Creating table', 'ost_ticket_progress');
            $sql[] = "CREATE TABLE IF NOT EXISTS `ost_ticket_progress` (
                `parent_id` int(11) unsigned PRIMARY KEY,
                `total_children` int(11) DEFAULT 0,
                `completed_children` int(11) DEFAULT 0,
                `in_progress_children` int(11) DEFAULT 0,
                `pending_children` int(11) DEFAULT 0,
                `last_calculated` timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        }

        // Add version column to ost_ticket
        if (!$this->columnExists('ost_ticket', 'version')) {
            $this->log('Adding column', 'version to ost_ticket');
            $sql[] = "ALTER TABLE `ost_ticket` ADD COLUMN `version` int(11) DEFAULT 0";
        }

        // Add indexes
        if (!$this->indexExists('ost_ticket', 'idx_ticket_pid')) {
            $this->log('Creating index', 'idx_ticket_pid');
            $sql[] = "CREATE INDEX idx_ticket_pid ON `ost_ticket`(`ticket_pid`)";
        }

        if (!$this->indexExists('ost_ticket', 'idx_ticket_hierarchy')) {
            $this->log('Creating index', 'idx_ticket_hierarchy');
            $sql[] = "CREATE INDEX idx_ticket_hierarchy ON `ost_ticket`(`ticket_id`, `ticket_pid`, `status_id`)";
        }

        // Execute SQL statements
        $this->log('Executing SQL statements', count($sql) . ' total');
        foreach ($sql as $i => $query) {
            $this->executeQuery($query, $i + 1, count($sql));
        }

        // Run migrations
        $this->runMigrations();

        // Add foreign key constraints
        $this->addForeignKeyConstraints();

        $this->log('Database initialization completed');
    }

    /**
     * Remove all plugin database structures
     *
     * Called during plugin uninstall when remove_data_on_uninstall is enabled
     */
    public function removeAll(): void
    {
        db_query("DROP TABLE IF EXISTS `ost_ticket_progress`");
        db_query("DROP TABLE IF EXISTS `ost_ticket_hierarchy_metadata`");
        db_query("ALTER TABLE `ost_ticket` DROP COLUMN IF EXISTS `version`");
        db_query("DROP INDEX IF EXISTS `idx_ticket_pid` ON `ost_ticket`");
        db_query("DROP INDEX IF EXISTS `idx_ticket_hierarchy` ON `ost_ticket`");
    }

    /**
     * Check if a table exists
     */
    private function tableExists(string $tableName): bool
    {
        $result = db_query("SHOW TABLES LIKE '$tableName'");
        $exists = db_num_rows($result) > 0;

        if ($exists) {
            $this->log('Table already exists', $tableName);
        }

        return $exists;
    }

    /**
     * Check if a column exists
     */
    private function columnExists(string $tableName, string $columnName): bool
    {
        $result = db_query("SHOW COLUMNS FROM `$tableName` LIKE '$columnName'");
        $exists = db_num_rows($result) > 0;

        if ($exists) {
            $this->log('Column already exists', $columnName);
        }

        return $exists;
    }

    /**
     * Check if an index exists
     */
    private function indexExists(string $tableName, string $indexName): bool
    {
        $result = db_query("SHOW INDEX FROM `$tableName` WHERE Key_name = '$indexName'");
        $exists = db_num_rows($result) > 0;

        if ($exists) {
            $this->log('Index already exists', $indexName);
        }

        return $exists;
    }

    /**
     * Execute a query with logging
     */
    private function executeQuery(string $query, int $current, int $total): void
    {
        $this->log('Executing SQL', "$current/$total");
        $result = db_query($query);

        if (!$result) {
            $error = function_exists('db_error') ? db_error() : 'Unknown error';
            $this->log('SQL FAILED', "Error: $error | Query: $query");
        } else {
            $this->log('SQL executed successfully', "$current/$total");
        }
    }

    /**
     * Run column type migrations
     */
    private function runMigrations(): void
    {
        // Migrate ticket_id column to unsigned if needed
        $this->migrateColumnToUnsigned('ost_ticket_hierarchy_metadata', 'ticket_id');
        $this->migrateColumnToUnsigned('ost_ticket_progress', 'parent_id');
    }

    /**
     * Migrate a column to unsigned int
     */
    private function migrateColumnToUnsigned(string $tableName, string $columnName): void
    {
        if (!$this->tableExists($tableName)) {
            return;
        }

        $result = db_query("SHOW COLUMNS FROM `$tableName` WHERE Field = '$columnName'");
        if (!$result || db_num_rows($result) === 0) {
            return;
        }

        $row = db_fetch_array($result);
        if (stripos($row['Type'], 'unsigned') === false) {
            $this->log('Migrating column to unsigned', "$columnName in $tableName");
            db_query("ALTER TABLE `$tableName` MODIFY `$columnName` int(11) unsigned");
        }
    }

    /**
     * Add foreign key constraints
     */
    private function addForeignKeyConstraints(): void
    {
        $this->addForeignKey(
            'ost_ticket_hierarchy_metadata',
            'fk_hierarchy_ticket',
            'ticket_id',
            'ost_ticket',
            'ticket_id'
        );

        $this->addForeignKey(
            'ost_ticket_progress',
            'fk_progress_ticket',
            'parent_id',
            'ost_ticket',
            'ticket_id'
        );
    }

    /**
     * Add a foreign key constraint if it doesn't exist
     */
    private function addForeignKey(
        string $tableName,
        string $constraintName,
        string $column,
        string $refTable,
        string $refColumn
    ): void {
        if (!$this->tableExists($tableName)) {
            return;
        }

        // Check if FK already exists
        $fkCheck = db_query("SELECT CONSTRAINT_NAME
            FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_NAME = '$tableName'
            AND CONSTRAINT_TYPE = 'FOREIGN KEY'");

        if (db_num_rows($fkCheck) > 0) {
            return;
        }

        $this->log('Adding FK constraint', $tableName);
        $result = db_query("ALTER TABLE `$tableName`
            ADD CONSTRAINT `$constraintName`
            FOREIGN KEY (`$column`)
            REFERENCES `$refTable`(`$refColumn`)
            ON DELETE CASCADE");

        if (!$result) {
            $error = function_exists('db_error') ? db_error() : 'Unknown error';
            $this->log('FK constraint failed', "Non-critical: $error");
        } else {
            $this->log('FK constraint added', $tableName);
        }
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
