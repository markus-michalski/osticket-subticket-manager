<?php
/**
 * Tests that every query respects osTicket's configurable table prefix
 *
 * osTicket's installer lets the operator choose TABLE_PREFIX (default
 * "ost_"). The plugin used to hardcode "ost_ticket" and friends, so on any
 * other prefix every lookup failed with "Ticket not found".
 */

namespace SubticketManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SubticketManager\Database\Table;

require_once dirname(__DIR__) . '/bootstrap.php';

class TablePrefixTest extends TestCase
{
    public function testTableNameUsesTheConfiguredPrefix(): void
    {
        $this->assertSame(TABLE_PREFIX, Table::prefix());
        $this->assertSame(TABLE_PREFIX . 'ticket', Table::name('ticket'));
        $this->assertSame(TABLE_PREFIX . 'ticket__cdata', Table::name('ticket__cdata'));
    }

    /**
     * No production file may spell out an "ost_" table name any more.
     *
     * Comment lines are skipped; everything else counts.
     */
    public function testNoHardcodedTableNamesRemainInProductionCode(): void
    {
        $root = dirname(__DIR__, 2);
        $files = array_merge(
            glob($root . '/*.php') ?: [],
            glob($root . '/ajax/*.php') ?: [],
            glob($root . '/scp-files/*.php') ?: [],
            glob($root . '/src/*/*.php') ?: []
        );

        $this->assertNotEmpty($files);

        $hits = [];
        foreach ($files as $file) {
            foreach (file($file) as $lineNo => $line) {
                if (preg_match('~^\s*(\*|//|/\*)~', $line)) {
                    continue;
                }
                if (preg_match('/\bost_[a-z_]+/', $line)) {
                    $hits[] = substr($file, strlen($root) + 1) . ':' . ($lineNo + 1) . ': ' . trim($line);
                }
            }
        }

        $this->assertSame([], $hits, "Hardcoded table names:\n" . implode("\n", $hits));
    }

    /**
     * Run the hierarchy queries in a fresh PHP process with TABLE_PREFIX set
     * to something other than "ost_" and check the SQL that comes out.
     */
    public function testQueriesUseADifferentPrefixWhenConfigured(): void
    {
        $root = str_replace('\\', '/', dirname(__DIR__, 2));

        $script = <<<'PHP'
define('TABLE_PREFIX', 'acme_');
require $argv[1] . '/tests/bootstrap.php';
require_once $argv[1] . '/ajax/SubticketController.php';

$service = new SubticketManager\Hierarchy\HierarchyService();
$GLOBALS['__test_mock_results'] = [[['ticket_id' => 1]], [['ticket_id' => 2]], [['ticket_id' => 1]]];
$GLOBALS['__test_mock_index'] = 0;
$service->getTicketIdByNumber('100001');
$service->getParent(2);
$service->getChildren(1);
$service->linkTicket(2, 1);

$db = new SubticketManager\Database\DatabaseService();
$db->initialize();

$GLOBALS['__test_csrf_token'] = 'tok';
$GLOBALS['__test_staff_permission'] = true;
$controller = new SubticketController(new TestableSubticketPlugin());
$controller->getBatchParentStatus('1,2');

$plugin = new TestableSubticketPlugin();
$plugin->isCompatible();

foreach (get_test_db_queries() as $q) {
    echo preg_replace('/\s+/', ' ', trim($q['query'])), "\n";
}
PHP;

        $scriptFile = tempnam(sys_get_temp_dir(), 'subticket-prefix-');
        file_put_contents($scriptFile, "<?php\n" . $script);

        try {
            $cmd = escapeshellarg(PHP_BINARY) . ' -d display_errors=stderr ' . escapeshellarg($scriptFile) . ' ' . escapeshellarg($root) . ' 2>&1';
            exec($cmd, $output, $exitCode);
        } finally {
            @unlink($scriptFile);
        }

        $sql = implode("\n", $output);

        $this->assertSame(0, $exitCode, "subprocess failed:\n$sql");
        $this->assertStringContainsString('acme_ticket', $sql);
        $this->assertStringContainsString('acme_ticket__cdata', $sql);
        $this->assertStringContainsString('acme_ticket_status', $sql);
        $this->assertStringContainsString('acme_ticket_hierarchy_metadata', $sql);
        $this->assertStringContainsString('acme_ticket_progress', $sql);
        $this->assertStringNotContainsString('ost_', $sql, "default prefix leaked into SQL:\n$sql");
    }
}
