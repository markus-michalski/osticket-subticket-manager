<?php
/**
 * Parent Ticket Queue Column Annotation
 *
 * Shows a code-fork icon with child ticket count in the ticket queue
 * for tickets that have sub-tickets.
 *
 * This file must be loaded early (before bootstrap) so that osTicket's
 * QueueColumnAnnotation::getAnnotations() can find the class via get_declared_classes().
 *
 * @since 1.5.0
 */

// Ensure class.queue.php is loaded
if (!class_exists('QueueColumnAnnotation')) {
    require_once(INCLUDE_DIR . 'class.queue.php');
}

class ParentTicketDecoration extends QueueColumnAnnotation {
    static $icon = 'code-fork';
    static $qname = '_child_count';
    static $desc = 'Parent Ticket Icon';

    /**
     * Annotate the query with child ticket count
     *
     * Adds a subquery that counts the number of child tickets for each ticket.
     *
     * @param QuerySet $query The query to annotate
     * @param string|false $name Optional name for the annotation field
     * @return QuerySet Modified query
     */
    static function annotate($query, $name=false) {
        $name = $name ?: static::$qname;

        // Only annotate if the necessary classes exist (osTicket 1.10+)
        if (!class_exists('SqlField') || !class_exists('SqlAggregate')) {
            return $query;
        }

        return $query->annotate([
            $name => Ticket::objects()
                ->filter(['ticket_pid' => new SqlField('ticket_id', 1)])
                ->aggregate(['count' => SqlAggregate::COUNT('ticket_id')])
        ]);
    }

    /**
     * Get HTML decoration for parent tickets
     *
     * Returns a code-fork icon with the number of child tickets.
     *
     * @param array $row Ticket row data
     * @param string $text Original text
     * @return string HTML decoration
     */
    function getDecoration($row, $text) {
        $childCount = isset($row[static::$qname]) ? $row[static::$qname] : 0;

        if ($childCount > 0) {
            return sprintf(
                '<a href="#" data-placement="bottom" data-toggle="tooltip" title="%s"><i class="icon-code-fork"></i> <small class="faded-more">%d</small></a>',
                sprintf(__('%d sub-ticket(s)'), $childCount),
                $childCount
            );
        }

        return '';
    }

    /**
     * Check if decoration should be visible
     *
     * Only show for tickets with at least one child ticket.
     *
     * @param array $row Ticket row data
     * @return bool True if decoration should be shown
     */
    function isVisible($row) {
        $childCount = isset($row[static::$qname]) ? $row[static::$qname] : 0;
        return $childCount > 0;
    }
}
