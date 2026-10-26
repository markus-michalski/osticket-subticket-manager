<?php

declare(strict_types=1);

namespace SubticketManager\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SubticketPlugin;
use SubticketPluginConfig;

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/class.SubticketPlugin.php';

/**
 * osTicket stores the instance that enable() creates through the plugin's
 * configuration form. If pre_save() rejects that request, the instance stays
 * disabled and the plugin never bootstraps (found on a live 1.18.4 install).
 */
class ConfigPreSaveTest extends TestCase
{
    private SubticketPluginConfig $config;

    protected function setUp(): void
    {
        $this->config = new SubticketPluginConfig();
    }

    public function testEmptyLimitsFallBackToTheDefaults(): void
    {
        $values = ['max_depth' => '', 'max_children' => null];
        $errors = [];

        $this->assertTrue($this->config->pre_save($values, $errors));
        $this->assertSame([], $errors);
        $this->assertSame('3', $values['max_depth']);
        $this->assertSame('50', $values['max_children']);
    }

    public function testMissingLimitsFallBackToTheDefaults(): void
    {
        $values = ['name' => 'Subticket Manager', 'isactive' => 1];
        $errors = [];

        $this->assertTrue($this->config->pre_save($values, $errors));
        $this->assertSame('3', $values['max_depth']);
        $this->assertSame('50', $values['max_children']);
    }

    public function testValidLimitsAreNormalised(): void
    {
        $values = ['max_depth' => ' 05 ', 'max_children' => '1000'];
        $errors = [];

        $this->assertTrue($this->config->pre_save($values, $errors));
        $this->assertSame('5', $values['max_depth']);
        $this->assertSame('1000', $values['max_children']);
    }

    /**
     * @dataProvider invalidLimits
     */
    public function testOutOfRangeOrNonNumericLimitsAreRejected(string $key, string $value): void
    {
        $values = ['max_depth' => '3', 'max_children' => '50', $key => $value];
        $errors = [];

        $this->assertFalse($this->config->pre_save($values, $errors));
        $this->assertArrayHasKey($key, $errors);
        $this->assertCount(1, $errors);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public function invalidLimits(): array
    {
        return [
            'depth zero' => ['max_depth', '0'],
            'depth eleven' => ['max_depth', '11'],
            'depth negative' => ['max_depth', '-1'],
            'depth text' => ['max_depth', 'three'],
            'depth float' => ['max_depth', '2.5'],
            'children zero' => ['max_children', '0'],
            'children too many' => ['max_children', '1001'],
        ];
    }

    public function testInstanceVarsCreatedByEnablePassValidation(): void
    {
        $vars = SubticketPlugin::defaultInstanceVars('Subticket Manager');
        $errors = [];

        $this->assertSame(1, $vars['isactive']);
        $this->assertSame('Subticket Manager', $vars['name']);
        $this->assertTrue($this->config->pre_save($vars, $errors));
        $this->assertSame([], $errors);
        $this->assertSame('3', $vars['max_depth']);
        $this->assertSame('50', $vars['max_children']);
        $this->assertSame(1, $vars['require_parent_open']);
        $this->assertSame(1, $vars['allow_nested_subtickets']);
        $this->assertSame(0, $vars['remove_data_on_uninstall']);
    }
}
