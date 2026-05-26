<?php

/**
 * Tests for Issue #3: hardcoded plugin directory name in ajax-subticket.php
 *
 * Verifies that subticket_resolve_plugin_dir() finds the plugin directory
 * regardless of the directory name it was installed under.
 *
 * Design note: scp-files/ajax-subticket.php inlines the same glob logic rather
 * than calling this function. This is intentional -- the deployed scp file has
 * no reliable self-referential path back to the plugin before the glob runs
 * (bootstrap chicken-and-egg). These tests validate the algorithm shared by
 * both the helper and the inline code.
 */

namespace SubticketManager\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__) . '/bootstrap.php';
require_once dirname(__DIR__, 2) . '/ajax/PluginPathResolver.php';

class AjaxHandlerPathResolutionTest extends TestCase
{
    /** @var string */
    private $tmpDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmpDir = sys_get_temp_dir() . '/osticket_test_' . uniqid();
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        if (is_dir($this->tmpDir)) {
            $this->removeDir($this->tmpDir);
        }
    }

    private function removeDir(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }

    private function createFakePluginDir(string $dirName): string
    {
        $pluginsDir = $this->tmpDir . '/plugins/' . $dirName;
        mkdir($pluginsDir, 0755, true);
        touch($pluginsDir . '/class.SubticketPlugin.php');
        return $pluginsDir;
    }

    public function testResolvesPluginDirWhenInstalledAsSubticketManager(): void
    {
        $expected = $this->createFakePluginDir('subticket-manager');

        $result = subticket_resolve_plugin_dir($this->tmpDir . '/');

        $this->assertSame($expected, $result);
    }

    public function testResolvesPluginDirWhenInstalledAsOsticketSubticketManager(): void
    {
        $expected = $this->createFakePluginDir('osticket-subticket-manager');

        $result = subticket_resolve_plugin_dir($this->tmpDir . '/');

        $this->assertSame($expected, $result);
    }

    public function testResolvesPluginDirWithArbitraryDirectoryName(): void
    {
        $expected = $this->createFakePluginDir('my-custom-subticket-plugin');

        $result = subticket_resolve_plugin_dir($this->tmpDir . '/');

        $this->assertSame($expected, $result);
    }

    public function testReturnsNullWhenNoMatchingDirectoryExists(): void
    {
        mkdir($this->tmpDir . '/plugins', 0755, true);

        $result = subticket_resolve_plugin_dir($this->tmpDir . '/');

        $this->assertNull($result);
    }

    public function testControllerFilePathIsCorrectlyDerived(): void
    {
        $this->createFakePluginDir('osticket-subticket-manager');

        $pluginDir = subticket_resolve_plugin_dir($this->tmpDir . '/');

        $this->assertStringContainsString('osticket-subticket-manager', $pluginDir);
        $this->assertStringEndsWith('/ajax/SubticketController.php', $pluginDir . '/ajax/SubticketController.php');
    }

    public function testThrowsOnMultipleInstallations(): void
    {
        $this->createFakePluginDir('subticket-manager');
        $this->createFakePluginDir('osticket-subticket-manager');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Multiple SubticketPlugin installations/');

        subticket_resolve_plugin_dir($this->tmpDir . '/');
    }

    public function testNormalizesIncludeDirWithoutTrailingSlash(): void
    {
        $expected = $this->createFakePluginDir('subticket-manager');

        // Pass without trailing slash -- should be normalized
        $result = subticket_resolve_plugin_dir($this->tmpDir);

        $this->assertSame($expected, $result);
    }
}
