<?php

declare(strict_types=1);

namespace SubticketManager\Database;

/**
 * Table - resolves osTicket table names with the configured prefix
 *
 * osTicket lets the installer choose a table prefix (TABLE_PREFIX in
 * ost-config.php, "ost_" by default). Hardcoded names such as "ost_ticket"
 * fail on every installation that chose something else: db_query() returns
 * false, db_num_rows() returns 0, and the agent sees "Ticket not found".
 *
 * @package SubticketManager
 */
final class Table
{
    /**
     * Prefix used when TABLE_PREFIX is not defined (unit tests outside osTicket)
     */
    private const DEFAULT_PREFIX = 'ost_';

    /**
     * Full table name for an osTicket base name, e.g. name('ticket') => "ost_ticket"
     */
    public static function name(string $base): string
    {
        return self::prefix() . $base;
    }

    /**
     * The configured table prefix
     */
    public static function prefix(): string
    {
        if (defined('TABLE_PREFIX') && is_string(TABLE_PREFIX)) {
            return TABLE_PREFIX;
        }

        return self::DEFAULT_PREFIX;
    }
}
