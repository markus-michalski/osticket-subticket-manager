<?php

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
            return array(
                function($x) { return $x; },
                function($x, $y, $n) { return $n != 1 ? $y : $x; },
            );
        }
        return Plugin::translate($plugin);
    }

    /**
     * Get configuration form options
     *
     * @return array Configuration fields
     */
    function getOptions()
    {
        return array(
            'max_depth' => new TextboxField(array(
                'id' => 'max_depth',
                'label' => 'Maximum Hierarchy Depth',
                'configuration' => array(
                    'size' => 10,
                    'length' => 5,
                    'desc' => 'Maximum nesting level for subtickets (0-10, default: 3)'
                ),
                'default' => '3'
            )),

            'max_children' => new TextboxField(array(
                'id' => 'max_children',
                'label' => 'Maximum Children per Parent',
                'configuration' => array(
                    'size' => 10,
                    'length' => 5,
                    'desc' => 'Maximum number of subtickets allowed per parent (default: 50)'
                ),
                'default' => '50'
            )),

            'auto_close_parent' => new BooleanField(array(
                'id' => 'auto_close_parent',
                'label' => 'Auto-close Parent Ticket',
                'configuration' => array(
                    'desc' => 'Automatically close parent ticket when all subtickets are closed'
                ),
                'default' => true
            )),

            'cascade_hold' => new BooleanField(array(
                'id' => 'cascade_hold',
                'label' => 'Cascade Hold Status',
                'configuration' => array(
                    'desc' => 'When parent is set to "On Hold", automatically hold all subtickets'
                ),
                'default' => true
            )),

            'cascade_assignment' => new BooleanField(array(
                'id' => 'cascade_assignment',
                'label' => 'Cascade Assignment Changes',
                'configuration' => array(
                    'desc' => 'When parent ticket is reassigned, automatically reassign all subtickets'
                ),
                'default' => false
            )),

            'show_children_in_queue' => new BooleanField(array(
                'id' => 'show_children_in_queue',
                'label' => 'Show Subtickets in Queue',
                'configuration' => array(
                    'desc' => 'Display subtickets in queue views (default: hide, show only parent)'
                ),
                'default' => false
            )),

            'require_parent_open' => new BooleanField(array(
                'id' => 'require_parent_open',
                'label' => 'Require Parent Ticket to be Open',
                'configuration' => array(
                    'desc' => 'Prevent creating subtickets when parent ticket is closed'
                ),
                'default' => true
            )),

            'allow_nested_subtickets' => new BooleanField(array(
                'id' => 'allow_nested_subtickets',
                'label' => 'Allow Nested Subtickets',
                'configuration' => array(
                    'desc' => 'Allow subtickets to have their own subtickets (child tickets can become parents)'
                ),
                'default' => true
            )),

            'notify_on_auto_close' => new BooleanField(array(
                'id' => 'notify_on_auto_close',
                'label' => 'Notify on Auto-Close',
                'configuration' => array(
                    'desc' => 'Send notification when parent ticket is automatically closed'
                ),
                'default' => true
            )),

            'remove_data_on_uninstall' => new BooleanField(array(
                'id' => 'remove_data_on_uninstall',
                'label' => 'Remove Data on Uninstall',
                'configuration' => array(
                    'desc' => 'Delete all subticket metadata when plugin is uninstalled (WARNING: This cannot be undone!)'
                ),
                'default' => false
            ))

            // NOTE: Debug mode is currently controlled by SUBTICKET_DEBUG constant
            // in class.SubticketPlugin.php (line 12). UI config coming in future version.
        );
    }
}
