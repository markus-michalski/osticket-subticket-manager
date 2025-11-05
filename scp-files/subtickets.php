<?php
/**
 * Subticket Manager - Overview Page
 *
 * Shows all parent-child ticket relationships in the system.
 * Available to all staff members for better ticket management.
 */

require('staff.inc.php');

// Check staff permissions
if (!$thisstaff || !$thisstaff->isStaff()) {
    Http::response(403, 'Access Denied');
    exit;
}

// Page title
$nav->setTabActive('tickets');
$ost->addExtraHeader('<style>
.msg_info {
    background: #d9edf7;
    border: 1px solid #bce8f1;
    color: #31708f;
    padding: 10px;
    margin: 10px 0 20px 0;
    border-radius: 4px;
}
table.list {
    width: 100%;
    background: #fff;
}
table.list thead tr {
    background-color: #f5f5f5;
    border-bottom: 2px solid #ddd;
}
table.list th {
    font-weight: bold;
    padding: 8px;
    text-align: left;
    border-bottom: 1px solid #ddd;
}
table.list td {
    padding: 8px;
    border-bottom: 1px solid #eee;
    vertical-align: top;
}
table.list tbody tr:hover {
    background-color: #f9f9f9;
}
</style>');

// Render with admin header
include(STAFFINC_DIR . 'header.inc.php');
?>

<div id="subticket-admin-page">
    <h1>Ticket Hierarchies</h1>

    <p style="margin-bottom: 20px; color: #666;">
        Übersicht aller Parent-Child Ticket-Beziehungen im System.
    </p>

    <?php
    // Fetch all tickets with parent relationships
    // Simple query - we'll use osTicket Ticket class to get subject
    $sql = "SELECT t.ticket_id, t.number, t.ticket_pid,
                   s.name as status,
                   t.created
            FROM ost_ticket t
            LEFT JOIN ost_ticket_status s ON t.status_id = s.id
            WHERE t.ticket_pid IS NOT NULL AND t.ticket_pid > 0
            ORDER BY t.ticket_pid, t.created ASC
            LIMIT 100";

    $result = db_query($sql);
    $subtickets = array();

    while ($row = db_fetch_array($result)) {
        $parentId = $row['ticket_pid'];
        if (!isset($subtickets[$parentId])) {
            $subtickets[$parentId] = array();
        }

        // Use osTicket Ticket class to get subject
        $ticket = Ticket::lookup($row['ticket_id']);
        $row['subject'] = $ticket ? $ticket->getSubject() : 'Unknown';

        $subtickets[$parentId][] = $row;
    }

    // Get parent ticket info
    $parentIds = array_keys($subtickets);
    $parents = array();

    if (!empty($parentIds)) {
        foreach ($parentIds as $parentId) {
            $ticket = Ticket::lookup($parentId);
            if ($ticket) {
                $parents[$parentId] = array(
                    'ticket_id' => $parentId,
                    'number' => $ticket->getNumber(),
                    'subject' => $ticket->getSubject(),
                    'status' => $ticket->getStatus()->getName()
                );
            } else {
                $parents[$parentId] = array(
                    'number' => 'Unknown',
                    'subject' => 'Unknown',
                    'status' => 'Unknown'
                );
            }
        }
    }
    ?>

    <table class="list" border="0" cellspacing="1" cellpadding="2">
        <thead>
            <tr>
                <th width="10%">Parent-Ticket</th>
                <th width="20%">Betreff</th>
                <th width="10%">Status</th>
                <th width="8%">Anzahl</th>
                <th width="52%">Child-Tickets</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($subtickets)): ?>
            <tr>
                <td colspan="5" style="text-align: center; padding: 40px;">
                    <em>Keine Ticket-Hierarchien gefunden.</em>
                </td>
            </tr>
            <?php else: ?>
            <?php foreach ($subtickets as $parentId => $children):
                $parent = $parents[$parentId] ?? array('number' => 'Unknown', 'subject' => 'Unknown', 'status' => 'Unknown');
            ?>
            <tr>
                <td>
                    <a href="tickets.php?id=<?php echo $parentId; ?>" target="_blank">
                        #<?php echo Format::htmlchars($parent['number']); ?>
                    </a>
                </td>
                <td><?php echo Format::htmlchars($parent['subject']); ?></td>
                <td><?php echo Format::htmlchars($parent['status']); ?></td>
                <td style="text-align: center;"><strong><?php echo count($children); ?></strong></td>
                <td>
                    <ul style="margin: 0; padding-left: 20px;">
                        <?php foreach ($children as $child): ?>
                        <li>
                            <a href="tickets.php?id=<?php echo $child['ticket_id']; ?>" target="_blank">
                                #<?php echo Format::htmlchars($child['number']); ?>
                            </a>
                            - <?php echo Format::htmlchars($child['subject']); ?>
                            (<?php echo Format::htmlchars($child['status']); ?>)
                            <em style="color: #666; font-size: 0.9em;"><?php echo Format::datetime($child['created']); ?></em>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
include(STAFFINC_DIR . 'footer.inc.php');
?>
