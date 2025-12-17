<?php

declare(strict_types=1);

// Only include osTicket classes if they exist (not in test environment)
if (defined('INCLUDE_DIR') && file_exists(INCLUDE_DIR . 'class.plugin.php')) {
    require_once INCLUDE_DIR . 'class.plugin.php';
}

/**
 * Subticket Manager Plugin Configuration
 *
 * Defines configurable options for the subticket plugin
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
                    'desc' => 'Maximum nesting level for subtickets (0-10, default: 3)'
                ],
                'default' => '3'
            ]),

            'max_children' => new TextboxField([
                'id' => 'max_children',
                'label' => 'Maximum Children per Parent',
                'configuration' => [
                    'size' => 10,
                    'length' => 5,
                    'desc' => 'Maximum number of subtickets allowed per parent (default: 50)'
                ],
                'default' => '50'
            ]),

            'auto_close_parent' => new BooleanField([
                'id' => 'auto_close_parent',
                'label' => 'Auto-close Parent Ticket',
                'configuration' => [
                    'desc' => 'Automatically close parent ticket when all subtickets are closed'
                ],
                'default' => true
            ]),

            'cascade_hold' => new BooleanField([
                'id' => 'cascade_hold',
                'label' => 'Cascade Hold Status',
                'configuration' => [
                    'desc' => 'When parent is set to "On Hold", automatically hold all subtickets'
                ],
                'default' => true
            ]),

            'cascade_assignment' => new BooleanField([
                'id' => 'cascade_assignment',
                'label' => 'Cascade Assignment Changes',
                'configuration' => [
                    'desc' => 'When parent ticket is reassigned, automatically reassign all subtickets'
                ],
                'default' => false
            ]),

            'show_children_in_queue' => new BooleanField([
                'id' => 'show_children_in_queue',
                'label' => 'Show Subtickets in Queue',
                'configuration' => [
                    'desc' => 'Display subtickets in queue views (default: hide, show only parent)'
                ],
                'default' => false
            ]),

            'require_parent_open' => new BooleanField([
                'id' => 'require_parent_open',
                'label' => 'Require Parent Ticket to be Open',
                'configuration' => [
                    'desc' => 'Prevent creating subtickets when parent ticket is closed'
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

            'notify_on_auto_close' => new BooleanField([
                'id' => 'notify_on_auto_close',
                'label' => 'Notify on Auto-Close',
                'configuration' => [
                    'desc' => 'Send notification when parent ticket is automatically closed'
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

            // NOTE: Debug mode is currently controlled by SUBTICKET_DEBUG constant
            // in class.SubticketPlugin.php (line 12). UI config coming in future version.
        ];
    }
}
