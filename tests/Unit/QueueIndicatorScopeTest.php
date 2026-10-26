<?php

declare(strict_types=1);

namespace SubticketManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SubticketManager\Hierarchy\HierarchyService;
use SubticketManager\Signal\TicketEventHandler;
use SubticketManager\UI\PanelRenderer;

/**
 * The queue indicator loader is injected at bootstrap, before osTicket
 * defines OSTSCPINC. The client portal has a tickets.php as well; on a live
 * 1.18.4 install the loader ended up in the client ticket view. Only pages
 * under scp/ may receive it.
 */
class QueueIndicatorScopeTest extends TestCase
{
    /** @var string|null */
    private $previousScriptName;

    protected function setUp(): void
    {
        $this->previousScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
    }

    protected function tearDown(): void
    {
        if ($this->previousScriptName === null) {
            unset($_SERVER['SCRIPT_NAME']);
        } else {
            $_SERVER['SCRIPT_NAME'] = $this->previousScriptName;
        }
    }

    private function handler(): TicketEventHandler
    {
        return new TicketEventHandler(new HierarchyService(), new PanelRenderer('/include/plugins/subticket-manager'));
    }

    /**
     * @dataProvider scripts
     */
    public function testLoaderIsLimitedToStaffQueuePages(string $scriptName, bool $expected): void
    {
        $_SERVER['SCRIPT_NAME'] = $scriptName;

        $this->assertSame($expected, $this->handler()->shouldLoadQueueIndicator(), $scriptName);
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public function scripts(): array
    {
        return [
            'staff ticket list' => ['/scp/tickets.php', true],
            'staff open queue' => ['/scp/open.php', true],
            'staff closed queue' => ['/scp/closed.php', true],
            'staff in a subdirectory install' => ['/helpdesk/scp/tickets.php', true],
            'staff with backslashes' => ['\\scp\\tickets.php', true],
            'staff non-queue page' => ['/scp/profile.php', false],
            'client ticket view' => ['/tickets.php', false],
            'client new ticket form' => ['/open.php', false],
            'client in a subdirectory install' => ['/helpdesk/tickets.php', false],
            'empty' => ['', false],
        ];
    }

    public function testLoaderIsSkippedWhenScriptNameIsMissing(): void
    {
        unset($_SERVER['SCRIPT_NAME']);

        $this->assertFalse($this->handler()->shouldLoadQueueIndicator());
    }
}
