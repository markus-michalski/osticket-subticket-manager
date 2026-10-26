<?php
/**
 * Queue indicator loader must end up inside <head>, never in front of the doctype.
 *
 * Regression test for the quirks-mode bug: bootstrap() runs before osTicket
 * prints <!DOCTYPE html>. Echoing the loader there switches the browser into
 * quirks mode and breaks the positioning of osTicket's dialogs.
 */

require_once dirname(__DIR__) . '/bootstrap.php';

use PHPUnit\Framework\TestCase;
use SubticketManager\UI\PanelRenderer;

class QueueIndicatorHeadInjectionTest extends TestCase
{
    private const DOCUMENT = "<!DOCTYPE html>\n<html lang=\"en\">\n<head>\n<meta charset=\"utf-8\">\n<title>Tickets</title>\n</head>\n<body>\n<p>queue</p>\n</body>\n</html>\n";

    /** @var string|null */
    private $previousScriptName;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousScriptName = $_SERVER['SCRIPT_NAME'] ?? null;
        $_SERVER['SCRIPT_NAME'] = '/scp/tickets.php';
        $GLOBALS['__test_is_staff_area'] = true;
        reset_test_signals();
    }

    protected function tearDown(): void
    {
        if ($this->previousScriptName === null) {
            unset($_SERVER['SCRIPT_NAME']);
        } else {
            $_SERVER['SCRIPT_NAME'] = $this->previousScriptName;
        }
        parent::tearDown();
    }

    public function testBootstrapOnQueuePageEmitsNoOutput()
    {
        $this->expectOutputString('');

        $plugin = new TestableSubticketPlugin();
        $plugin->bootstrap();
    }

    public function testSnippetIsInsertedBeforeClosingHead()
    {
        $snippet = '<script>/* loader */</script>';

        $result = PanelRenderer::insertBeforeHeadEnd(self::DOCUMENT, $snippet);

        $this->assertSame(0, strpos($result, '<!DOCTYPE html>'),
            'Doctype must remain the very first byte of the document');
        $this->assertSame(1, substr_count($result, $snippet));
        $this->assertLessThan(strpos($result, '</head>'), strpos($result, $snippet),
            'Snippet must sit inside <head>');
        $this->assertGreaterThan(strpos($result, '<head>'), strpos($result, $snippet));
    }

    public function testClosingHeadIsMatchedCaseInsensitively()
    {
        $document = str_replace('</head>', '</HEAD>', self::DOCUMENT);
        $snippet = '<script>/* loader */</script>';

        $result = PanelRenderer::insertBeforeHeadEnd($document, $snippet);

        $this->assertStringContainsString($snippet . "\n</HEAD>", $result);
    }

    public function testDocumentWithoutHeadIsReturnedUnchanged()
    {
        $csv = "number,subject\n100001,Example\n";
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";

        $this->assertSame($csv, PanelRenderer::insertBeforeHeadEnd($csv, '<script></script>'));
        $this->assertSame($pdf, PanelRenderer::insertBeforeHeadEnd($pdf, '<script></script>'));
        $this->assertSame('', PanelRenderer::insertBeforeHeadEnd('', '<script></script>'));
    }

    public function testEmptySnippetLeavesDocumentUnchanged()
    {
        $this->assertSame(self::DOCUMENT, PanelRenderer::insertBeforeHeadEnd(self::DOCUMENT, ''));
    }

    public function testBufferCallbackPlacesQueueIndicatorLoaderInHead()
    {
        $plugin = new TestableSubticketPlugin();
        $plugin->bootstrap();

        $result = $plugin->injectQueueIndicatorScript(self::DOCUMENT);

        $this->assertSame(0, strpos($result, '<!DOCTYPE html>'));
        $this->assertStringContainsString('queue-indicator.js', $result);
        $this->assertLessThan(strpos($result, '</head>'), strpos($result, 'queue-indicator.js'));
        $this->assertSame(1, substr_count($result, '<script>'),
            'Loader must be injected exactly once');
    }

    public function testBufferCallbackPrependsLoaderToPjaxFragment()
    {
        $fragment = "<div id=\"content\">queue rows</div>\n";
        $_SERVER['HTTP_X_PJAX'] = 'true';

        try {
            $plugin = new TestableSubticketPlugin();
            $plugin->bootstrap();
            $result = $plugin->injectQueueIndicatorScript($fragment);
        } finally {
            unset($_SERVER['HTTP_X_PJAX']);
        }

        $this->assertSame(0, strpos($result, '<script>'),
            'PJAX fragment has no <head>; loader must be prepended');
        $this->assertStringContainsString('queue-indicator.js', $result);
        $this->assertStringContainsString($fragment, $result);
    }
}
