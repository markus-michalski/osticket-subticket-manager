<?php

declare(strict_types=1);

// Only include osTicket classes if they exist (not in test environment)
if (defined('INCLUDE_DIR') && file_exists(INCLUDE_DIR . 'class.plugin.php')) {
    require_once INCLUDE_DIR . 'class.plugin.php';
}

/**
 * Subticket Manager Plugin Configuration
 *
 * Every option listed here is read by the plugin. The hierarchy limits are
 * enforced in HierarchyService::linkTicket(), remove_data_on_uninstall in
 * SubticketPlugin::uninstall().
 */
class SubticketPluginConfig extends PluginConfig
{
    /**
     * Translate strings (for i18n support)
     *
     * @param string $plugin Plugin identifier
     * @return array Translation functions
     */
    static function translate($plugin = 'subticket-manager') {
        if (!method_exists('Plugin', 'translate')) {
            return [
                function($x) { return $x; },
                function($x, $y, $n) { return $n != 1 ? $y : $x; },
            ];
        }
        /** @disregard P1013 (Plugin class may not exist in test environment) */
        return Plugin::translate($plugin);
    }

    /**
     * Get configuration form options
     *
     * @disregard P1013 (Parent class PluginConfig not available in IDE context)
     * @return array Configuration fields
     */
    function getOptions(): array
    {
        return [
            'max_depth' => new TextboxField([
                'id' => 'max_depth',
                'label' => 'Maximum Hierarchy Depth',
                'configuration' => [
                    'size' => 10,
                    'length' => 5,
                    'desc' => 'How many levels a chain of subtickets may have below a root ticket (1-10, default: 3). Linking is refused beyond this depth.'
                ],
                'default' => '3'
            ]),

            'max_children' => new TextboxField([
                'id' => 'max_children',
                'label' => 'Maximum Children per Parent',
                'configuration' => [
                    'size' => 10,
                    'length' => 5,
                    'desc' => 'Maximum number of subtickets allowed per parent (1-1000, default: 50)'
                ],
                'default' => '50'
            ]),

            'require_parent_open' => new BooleanField([
                'id' => 'require_parent_open',
                'label' => 'Require Parent Ticket to be Open',
                'configuration' => [
                    'desc' => 'Refuse linking a subticket to a parent that is not open'
                ],
                'default' => true
            ]),

            'allow_nested_subtickets' => new BooleanField([
                'id' => 'allow_nested_subtickets',
                'label' => 'Allow Nested Subtickets',
                'configuration' => [
                    'desc' => 'Allow subtickets to have their own subtickets (child tickets can become parents)'
                ],
                'default' => true
            ]),

            'remove_data_on_uninstall' => new BooleanField([
                'id' => 'remove_data_on_uninstall',
                'label' => 'Remove Data on Uninstall',
                'configuration' => [
                    'desc' => 'Delete all subticket metadata when plugin is uninstalled (WARNING: This cannot be undone!)'
                ],
                'default' => false
            ])

            // NOTE: Debug mode is controlled by the SUBTICKET_DEBUG constant
            // in class.SubticketPlugin.php.
        ];
    }

    /**
     * Defaults of the numeric limits, used when a value is missing
     *
     * osTicket stores the instance that enable() creates through the same
     * form as the admin page. That request carries no limit values, and a
     * rejected form leaves the instance disabled, so an empty value means
     * "the default", not "invalid".
     *
     * @return array<string, array{int, int, int}> key => [min, max, default]
     */
    public static function limits(): array
    {
        return [
            'max_depth' => [1, 10, 3],
            'max_children' => [1, 1000, 50],
        ];
    }

    /**
     * Validate the numeric limits before osTicket stores them
     *
     * @param array $config Submitted values (by reference)
     * @param array $errors Validation errors (by reference)
     * @return bool True when the configuration may be saved
     */
    function pre_save(&$config, &$errors)
    {
        foreach (self::limits() as $key => [$min, $max, $default]) {
            $raw = isset($config[$key]) ? trim((string)$config[$key]) : '';

            if ($raw === '') {
                $config[$key] = (string)$default;
                continue;
            }

            if (!ctype_digit($raw) || (int)$raw < $min || (int)$raw > $max) {
                $errors[$key] = sprintf('Enter a whole number between %d and %d', $min, $max);
                continue;
            }

            $config[$key] = (string)(int)$raw;
        }

        return empty($errors);
    }
}
