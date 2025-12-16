/**
 * Subticket Manager - Frontend JavaScript
 *
 * Handles all user interactions in the ticket view panel:
 * - Link/unlink parent tickets
 * - Link/unlink child tickets
 * - Create subtickets
 *
 * Dependencies: jQuery 3.6 (provided by osTicket)
 *
 * @since 1.4.0
 */

(function($) {
    'use strict';

    /**
     * Subticket Panel Controller
     */
    var SubticketPanel = {
        /**
         * Flag to prevent multiple initializations
         */
        initialized: false,

        /**
         * Initialize panel - attach event handlers
         */
        init: function() {
            // Prevent multiple initializations (script may be loaded multiple times)
            if (this.initialized) {
                return;
            }
            this.initialized = true;

            // Remove any existing handlers first, then attach new ones
            // This prevents duplicate handlers if init() is called multiple times
            $(document).off('click.subticket').on('click.subticket', '.subticket-action', this.handleButtonClick.bind(this));
        },

        /**
         * Handle button clicks based on data-action attribute
         *
         * @param {Event} e Click event
         */
        handleButtonClick: function(e) {
            // Prevent default behavior and stop propagation
            e.preventDefault();
            e.stopPropagation();
            e.stopImmediatePropagation();

            var $btn = $(e.currentTarget);

            // Prevent double-clicks by checking if already processing
            if ($btn.data('processing')) {
                console.log('[Subticket] Ignoring click - already processing');
                return false;
            }

            var action = $btn.data('action');
            var ticketId = $btn.data('ticket-id');
            var childId = $btn.data('child-id');
            var $panel = $btn.closest('.subticket-panel');
            var csrfToken = $panel.data('csrf-token');

            console.log('[Subticket] Button click:', action, 'ticketId:', ticketId);

            // Dispatch to appropriate handler
            switch(action) {
                case 'link-parent':
                    this.showLinkParentDialog(ticketId, csrfToken);
                    break;

                case 'unlink-parent':
                    this.unlinkParent(ticketId, csrfToken, $panel);
                    break;

                case 'unlink-child':
                    this.unlinkChild(childId, csrfToken, $panel);
                    break;

                case 'create-child':
                    this.showCreateSubticketDialog(ticketId, csrfToken);
                    break;
            }

            return false;
        },

        /**
         * Show dialog to link current ticket to a parent
         *
         * @param {number} ticketId Current ticket ID
         * @param {string} csrfToken CSRF token
         */
        showLinkParentDialog: function(ticketId, csrfToken) {
            var parentId = prompt('Enter parent ticket number or ID:');

            if (!parentId) {
                return; // User cancelled
            }

            // Validate input - max 10 digits to prevent overflow
            parentId = parentId.trim();
            if (!/^\d{1,10}$/.test(parentId)) {
                alert('Please enter a valid ticket number (max 10 digits)');
                return;
            }

            // Call API
            this.linkToParent(ticketId, parentId, csrfToken);
        },

        /**
         * Flag to prevent concurrent AJAX requests
         */
        isLinking: false,

        /**
         * Link current ticket to parent via AJAX
         *
         * @param {number} childId Current ticket ID (becomes child)
         * @param {number} parentId Parent ticket ID
         * @param {string} csrfToken CSRF token
         */
        linkToParent: function(childId, parentId, csrfToken) {
            var self = this;

            // Prevent concurrent requests
            if (this.isLinking) {
                console.log('[Subticket] Link request already in progress, ignoring');
                return;
            }
            this.isLinking = true;

            console.log('[Subticket] linkToParent called:', childId, '->', parentId);

            // SECURITY: Use filter() to prevent selector injection
            var $panel = $('.subticket-panel').filter(function() {
                return $(this).data('ticket-id') == childId;
            });

            this.showLoading($panel, 'Linking to parent...');

            $.ajax({
                url: 'ajax-subticket.php?action=link',
                method: 'POST',
                data: {
                    child_id: childId,
                    parent_id: parentId,
                    csrf_token: csrfToken
                },
                dataType: 'json',
                success: function(response) {
                    console.log('[Subticket] Link response:', response);
                    self.isLinking = false;
                    self.hideLoading($panel);

                    if (response.success) {
                        self.showSuccess('Successfully linked to parent ticket #' + parentId);
                        self.reloadPanel(childId);
                    } else {
                        self.showError(response.message || 'Failed to link to parent');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('[Subticket] Link error:', status, error);
                    self.isLinking = false;
                    self.hideLoading($panel);
                    self.showError('Server error: ' + error);
                }
            });
        },

        /**
         * Unlink current ticket from its parent
         *
         * @param {number} ticketId Current ticket ID
         * @param {string} csrfToken CSRF token
         * @param {jQuery} $panel Panel element
         */
        unlinkParent: function(ticketId, csrfToken, $panel) {
            if (!confirm('Remove parent relationship?')) {
                return;
            }

            this.showLoading($panel, 'Unlinking from parent...');

            $.ajax({
                url: 'ajax-subticket.php?action=unlink',
                method: 'POST',
                data: {
                    child_id: ticketId,
                    csrf_token: csrfToken
                },
                dataType: 'json',
                success: function(response) {
                    this.hideLoading($panel);

                    if (response.success) {
                        this.showSuccess('Successfully unlinked from parent');
                        this.reloadPanel(ticketId);
                    } else {
                        this.showError(response.message || 'Failed to unlink from parent');
                    }
                }.bind(this),
                error: function(xhr, status, error) {
                    this.hideLoading($panel);
                    this.showError('Server error: ' + error);
                }.bind(this)
            });
        },

        /**
         * Unlink child ticket
         *
         * @param {number} childId Child ticket ID to unlink
         * @param {string} csrfToken CSRF token
         * @param {jQuery} $panel Panel element
         */
        unlinkChild: function(childId, csrfToken, $panel) {
            if (!confirm('Remove this child ticket?')) {
                return;
            }

            var currentTicketId = $panel.data('ticket-id');

            this.showLoading($panel, 'Unlinking child ticket...');

            $.ajax({
                url: 'ajax-subticket.php?action=unlink',
                method: 'POST',
                data: {
                    child_id: childId,
                    csrf_token: csrfToken
                },
                dataType: 'json',
                success: function(response) {
                    this.hideLoading($panel);

                    if (response.success) {
                        this.showSuccess('Successfully unlinked child ticket #' + childId);
                        this.reloadPanel(currentTicketId);
                    } else {
                        this.showError(response.message || 'Failed to unlink child ticket');
                    }
                }.bind(this),
                error: function(xhr, status, error) {
                    this.hideLoading($panel);
                    this.showError('Server error: ' + error);
                }.bind(this)
            });
        },

        /**
         * Show dialog to create subticket
         *
         * @param {number} parentId Parent ticket ID
         * @param {string} csrfToken CSRF token
         */
        showCreateSubticketDialog: function(parentId, csrfToken) {
            // Redirect to "Open New Ticket" page with parent ID in URL
            // The plugin will auto-link the new ticket to this parent after creation
            var url = 'tickets.php?a=open&subticket_parent=' + parentId;

            window.location.href = url;
        },

        /**
         * Create new subticket via AJAX
         *
         * @param {number} parentId Parent ticket ID
         * @param {string} subject Subject line
         * @param {number} deptId Department ID
         * @param {string} message Message/description
         * @param {string} csrfToken CSRF token
         */
        createSubticket: function(parentId, subject, deptId, message, csrfToken) {
            var self = this;

            // Find panel
            var $panel = $('.subticket-panel').filter(function() {
                return $(this).data('ticket-id') == parentId;
            });

            this.showLoading($panel, 'Creating subticket...');

            $.ajax({
                url: 'ajax-subticket.php?action=create',
                method: 'POST',
                data: {
                    parent_id: parentId,
                    subject: subject,
                    dept_id: deptId,
                    message: message,
                    csrf_token: csrfToken
                },
                dataType: 'json',
                success: function(response) {
                    self.hideLoading($panel);

                    if (response.success) {
                        self.showSuccess('Subticket created successfully: #' + response.ticket_number);

                        // Reload panel to show new child
                        self.reloadPanel(parentId);
                    } else {
                        self.showError(response.message || 'Failed to create subticket');
                    }
                },
                error: function(xhr, status, error) {
                    self.hideLoading($panel);
                    self.showError('Server error: ' + error);
                }
            });
        },

        /**
         * Reload panel content via AJAX
         *
         * @param {number} ticketId Ticket ID
         */
        reloadPanel: function(ticketId) {
            // Simple page reload for MVP
            // TODO: Implement AJAX panel refresh
            setTimeout(function() {
                window.location.reload();
            }, 1000);
        },

        /**
         * Show loading overlay on panel
         *
         * @param {jQuery} $panel Panel element
         * @param {string} message Loading message
         */
        showLoading: function($panel, message) {
            // Add loading class
            $panel.addClass('subticket-loading');

            // Create overlay if not exists
            // SECURITY: Use jQuery DOM methods to prevent XSS
            if (!$panel.find('.subticket-overlay').length) {
                var $overlay = $('<div class="subticket-overlay">')
                    .append($('<div class="subticket-spinner">'))
                    .append($('<div class="subticket-loading-text">').text(message));

                $panel.append($overlay);
            }

            // Disable all buttons
            $panel.find('.subticket-action').prop('disabled', true);
        },

        /**
         * Hide loading overlay
         *
         * @param {jQuery} $panel Panel element
         */
        hideLoading: function($panel) {
            $panel.removeClass('subticket-loading');
            $panel.find('.subticket-overlay').remove();
            $panel.find('.subticket-action').prop('disabled', false);
        },

        /**
         * Show success message
         *
         * @param {string} message Success message
         */
        showSuccess: function(message) {
            // Use osTicket's notification system if available
            if (typeof displayMessage === 'function') {
                displayMessage(message, 'notice');
            } else {
                alert('✓ ' + message);
            }
        },

        /**
         * Show error message
         *
         * @param {string} message Error message
         */
        showError: function(message) {
            // Use osTicket's notification system if available
            if (typeof displayMessage === 'function') {
                displayMessage(message, 'error');
            } else {
                alert('✗ Error: ' + message);
            }

            // Always log errors (even in production)
            if (console && console.error) {
                console.error('[Subticket] Error: ' + message);
            }
        }
    };

    /**
     * Move panel to correct position in DOM
     *
     * Moves the panel from its default position (inside tabs)
     * to above the tab navigation bar.
     */
    function repositionPanel() {
        var $panels = $('.subticket-panel');

        if (!$panels.length) {
            return; // Panel not found
        }

        // If there are multiple panels, remove all but the first
        if ($panels.length > 1) {
            $panels.slice(1).remove();
        }

        var $panel = $panels.first();

        // Try different selectors for tab navigation (where we want to insert BEFORE)
        // osTicket uses <ul class="nav nav-tabs"> for tab navigation
        var selectors = [
            'ul.nav.nav-tabs',          // Standard Bootstrap tabs
            '.nav-tabs',                // Alternative
            '#ticket ul.tabs',          // Nested variant
            '.ticket-tabs',             // Class variant
            'ul.tabs'                   // Generic tabs
        ];

        var $target = null;
        for (var i = 0; i < selectors.length; i++) {
            $target = $(selectors[i]).first();  // Only get FIRST matching element
            if ($target.length) {
                break;
            }
        }

        if ($target && $target.length) {
            // Move panel BEFORE the FIRST tab navigation (so it appears above tabs)
            $panel.insertBefore($target);
        }
    }

    /**
     * Move parent ticket badge below ticket number
     *
     * Extracts the badge from the panel and places it right after
     * the ticket number heading.
     */
    function repositionParentBadge() {
        var $badges = $('.parent-ticket-badge');

        if (!$badges.length) {
            return; // No badge found (not a parent ticket)
        }

        // Try different selectors for ticket number heading
        var selectors = [
            'h2:contains("Ticket #")',      // Standard heading
            '.ticket-number',                // Alternative
            '#ticket h2',                    // Nested variant
            'h2'                             // Generic fallback
        ];

        var $ticketHeading = null;
        for (var i = 0; i < selectors.length; i++) {
            $ticketHeading = $(selectors[i]).first();
            if ($ticketHeading.length) {
                break;
            }
        }

        if ($ticketHeading && $ticketHeading.length) {
            // Remove ALL badges first
            $badges.remove();

            // Clone the first badge and insert after ticket heading
            var $clonedBadge = $badges.first().clone();
            $clonedBadge.insertAfter($ticketHeading);

            // Add some styling adjustments for the new position
            $clonedBadge.css({
                'margin-top': '10px',
                'margin-bottom': '10px'
            });
        }
    }

    /**
     * Initialize when DOM is ready
     */
    $(document).ready(function() {
        SubticketPanel.init();

        // Reposition panel after a short delay to ensure DOM is fully loaded
        setTimeout(function() {
            repositionPanel();
            repositionParentBadge();
        }, 100);
    });

    // Expose for debugging
    window.SubticketPanel = SubticketPanel;

})(jQuery);
