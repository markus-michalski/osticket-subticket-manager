/**
 * Queue Indicator - Shows parent ticket icons in ticket lists
 *
 * Automatically adds code-fork icons to parent tickets in queue views.
 * Runs on all ticket list pages and queries the server for parent status.
 *
 * Debug mode: Set window.SUBTICKET_DEBUG = true in browser console
 *
 * @since 1.5.0
 */

// Wait for jQuery to be available
(function() {
    'use strict';

    // Check if jQuery is loaded
    if (typeof jQuery === 'undefined') {
        // Retry after a short delay
        setTimeout(arguments.callee, 100);
        return;
    }

    // jQuery is available, initialize with jQuery
    (function($) {

    var QueueIndicator = {
        /**
         * Debug logging helper
         */
        debug: function() {
            if (window.SUBTICKET_DEBUG) {
                console.log.apply(console, ['[SubticketManager]'].concat(Array.prototype.slice.call(arguments)));
            }
        },

        /**
         * Initialize queue indicator
         */
        init: function() {
            // Only run on ticket queue pages
            if (!this.isQueuePage()) {
                return;
            }

            this.debug('Queue indicator initializing...');

            // Wait for page load and queue table to render
            $(document).ready(function() {
                // osTicket may load queue table via AJAX, wait a bit
                setTimeout(function() {
                    QueueIndicator.addParentIndicators();
                }, 500);
            });

            // Re-run when queue is refreshed (AJAX pagination, sorting, etc.)
            $(document).on('DOMContentLoaded', function() {
                setTimeout(function() {
                    QueueIndicator.addParentIndicators();
                }, 500);
            });
        },

        /**
         * Check if current page is a queue page
         */
        isQueuePage: function() {
            return window.location.pathname.indexOf('tickets.php') !== -1;
        },

        /**
         * Add parent indicators to ticket rows
         */
        addParentIndicators: function() {
            var self = this;

            // Find all ticket rows in the queue table
            var $rows = $('table.queue tbody tr');

            if ($rows.length === 0) {
                self.debug('No ticket rows found');
                return;
            }

            self.debug('Found ' + $rows.length + ' ticket rows');

            // Extract ticket IDs from rows
            var ticketIds = [];
            $rows.each(function(index) {
                var $row = $(this);
                var ticketId = self.getTicketIdFromRow($row);

                self.debug('Row ' + index + ': ID=' + ticketId);

                if (ticketId) {
                    ticketIds.push({
                        id: ticketId,
                        row: $row
                    });
                }
            });

            if (ticketIds.length === 0) {
                self.debug('No ticket IDs extracted');
                return;
            }

            self.debug('Extracted ' + ticketIds.length + ' ticket IDs');

            // Query server for parent status (batch request)
            self.fetchParentStatus(ticketIds);
        },

        /**
         * Extract ticket ID from row
         */
        getTicketIdFromRow: function($row) {
            // Try to find ticket ID in data attribute
            var ticketId = $row.data('ticket-id') || $row.data('id');

            if (ticketId) {
                return ticketId;
            }

            // Try to find it in the ticket number link
            var $link = $row.find('a[href*="tickets.php?id="]').first();

            if ($link.length > 0) {
                var href = $link.attr('href');
                var match = href.match(/id=(\d+)/);

                if (match) {
                    return parseInt(match[1]);
                }
            }

            return null;
        },

        /**
         * Fetch parent status from server
         */
        fetchParentStatus: function(ticketData) {
            var self = this;

            // Build comma-separated list of ticket IDs
            var ids = ticketData.map(function(item) {
                return item.id;
            }).join(',');

            self.debug('Fetching parent status for ticket IDs:', ids);

            $.ajax({
                url: 'ajax-subticket.php?action=batch_parent_status',
                method: 'GET',
                data: {
                    ticket_ids: ids
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success && response.data) {
                        self.renderIndicators(ticketData, response.data);
                        self.debug('Successfully rendered indicators for ' + Object.keys(response.data).length + ' tickets');
                    } else {
                        console.error('[SubticketManager] Invalid response:', response);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[SubticketManager] Failed to fetch parent status:', error);
                    self.debug('XHR status:', status, 'Response:', xhr.responseText);
                }
            });
        },

        /**
         * Render parent indicators in ticket rows
         */
        renderIndicators: function(ticketData, parentStatus) {
            var self = this;

            ticketData.forEach(function(item) {
                var status = parentStatus[item.id];

                if (status && status.is_parent && status.child_count > 0) {
                    // Find the ticket number cell (second td, first is checkbox)
                    var $numberCell = item.row.find('td:has(a[href*="tickets.php"])').first();

                    // Check if indicator already exists
                    if ($numberCell.find('.parent-indicator').length > 0) {
                        return;
                    }

                    // Create indicator
                    var $indicator = $('<span class="parent-indicator" style="margin-left: 5px;" title="' + status.child_count + ' Sub-Ticket(s)">' +
                        '<i class="icon-code-fork" style="color: #1e90ff;"></i> ' +
                        '<small style="color: #666;">' + status.child_count + '</small>' +
                        '</span>');

                    // Add tooltip
                    $indicator.tooltip();

                    // Append to number cell
                    $numberCell.append($indicator);

                    self.debug('Added indicator to ticket #' + item.id + ' (' + status.child_count + ' children)');
                }
            });
        }
    };

    // Initialize on page load
    QueueIndicator.init();

    })(jQuery); // End of jQuery wrapper

})(); // End of jQuery availability check
