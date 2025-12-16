<?php

declare(strict_types=1);

namespace SubticketManager\UI;

/**
 * PanelRenderer - Renders the subticket UI panel in ticket view
 *
 * Handles:
 * - HTML panel rendering
 * - CSS injection
 * - JavaScript loading
 *
 * @package SubticketManager
 */
final class PanelRenderer
{
    private string $pluginUrl;

    public function __construct(string $pluginUrl)
    {
        $this->pluginUrl = rtrim($pluginUrl, '/');
    }

    /**
     * Render the complete subticket panel
     *
     * @param int $ticketId Current ticket ID
     * @param array|null $parent Parent ticket data
     * @param array<int, array> $children Array of child tickets
     * @param string $csrfToken CSRF token for AJAX
     * @return string Complete HTML output
     */
    public function render(int $ticketId, ?array $parent, array $children, string $csrfToken): string
    {
        $html = $this->renderPanel($ticketId, $parent, $children, $csrfToken);
        $html .= $this->getCss();
        $html .= $this->getJavaScript();

        return $html;
    }

    /**
     * Render the panel HTML structure
     */
    private function renderPanel(int $ticketId, ?array $parent, array $children, string $csrfToken): string
    {
        $html = '<div class="subticket-panel section-break" data-ticket-id="' . $ticketId . '" data-csrf-token="' . htmlspecialchars($csrfToken) . '">';

        // Parent ticket badge
        if (!empty($children)) {
            $html .= $this->renderParentBadge(count($children));
        }

        // Parent section
        $html .= $this->renderParentSection($ticketId, $parent);

        // Children section
        $html .= $this->renderChildrenSection($ticketId, $children);

        $html .= '</div>';

        return $html;
    }

    /**
     * Render the parent ticket badge
     */
    private function renderParentBadge(int $childCount): string
    {
        $plural = $childCount > 1 ? 's' : '';

        return <<<HTML
<div class="parent-ticket-badge" style="background: #e8f4f8; border-left: 4px solid #1e90ff; padding: 12px 15px; margin-bottom: 15px; border-radius: 4px;">
    <i class="icon-code-fork" style="font-size: 18px; color: #1e90ff; margin-right: 8px;"></i>
    <strong style="color: #1e90ff; font-size: 14px;">Parent Ticket</strong>
    <span style="color: #666; font-size: 13px;">($childCount Sub-Ticket$plural)</span>
</div>
HTML;
    }

    /**
     * Render the parent section
     */
    private function renderParentSection(int $ticketId, ?array $parent): string
    {
        $html = '<div class="subticket-section parent-section">';
        $html .= '<h3>Parent Ticket</h3>';

        if ($parent) {
            $parentId = (int)$parent['ticket_id'];
            $number = htmlspecialchars($parent['number']);
            $subject = htmlspecialchars($parent['subject'] ?? '');
            $status = htmlspecialchars($parent['status'] ?? 'Unknown');

            $html .= <<<HTML
<div class="parent-info">
    <a href="tickets.php?id=$parentId" class="ticket-link">
        <strong>#$number:</strong> $subject
    </a>
    <span class="status-label">($status)</span>
    <br>
    <button type="button" data-action="unlink-parent" data-ticket-id="$ticketId" class="button subticket-action">Unlink from Parent</button>
</div>
HTML;
        } else {
            $html .= '<p class="no-data">No parent ticket</p>';
            $html .= '<button type="button" data-action="link-parent" data-ticket-id="' . $ticketId . '" class="button subticket-action">Link to Parent</button>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Render the children section
     */
    private function renderChildrenSection(int $ticketId, array $children): string
    {
        $html = '<div class="subticket-section children-section">';
        $html .= '<h3>Child Tickets</h3>';

        if (!empty($children)) {
            $html .= '<ul class="children-list">';
            foreach ($children as $child) {
                $html .= $this->renderChildItem($ticketId, $child);
            }
            $html .= '</ul>';
        } else {
            $html .= '<p class="no-data">No child tickets</p>';
        }

        $html .= '<button type="button" data-action="create-child" data-ticket-id="' . $ticketId . '" class="button button-primary subticket-action">Create Subticket</button>';
        $html .= '</div>';

        return $html;
    }

    /**
     * Render a single child item
     */
    private function renderChildItem(int $parentTicketId, array $child): string
    {
        $childId = (int)$child['id'];
        $number = htmlspecialchars($child['number']);
        $subject = htmlspecialchars($child['subject'] ?? '');
        $status = htmlspecialchars($child['status'] ?? 'Unknown');

        return <<<HTML
<li class="child-item">
    <a href="tickets.php?id=$childId" class="ticket-link">
        <strong>#$number:</strong> $subject
    </a>
    <span class="status-label">($status)</span>
    <button type="button" data-action="unlink-child" data-child-id="$childId" data-ticket-id="$parentTicketId" class="button button-sm subticket-action">Unlink</button>
</li>
HTML;
    }

    /**
     * Get inline CSS for the panel
     */
    public function getCss(): string
    {
        return <<<'CSS'
<style>
/* Subticket Panel Loading Overlay */
.subticket-panel {
    position: relative;
    padding: 15px;
    background: #f9f9f9;
    border: 1px solid #ddd;
    border-radius: 4px;
    margin-bottom: 20px;
}

.subticket-panel.subticket-loading {
    pointer-events: none;
    opacity: 0.6;
}

.subticket-overlay {
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(255, 255, 255, 0.9);
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    z-index: 1000;
    border-radius: 4px;
}

.subticket-spinner {
    width: 40px;
    height: 40px;
    border: 4px solid #f3f3f3;
    border-top: 4px solid #3498db;
    border-radius: 50%;
    animation: subticket-spin 1s linear infinite;
}

@keyframes subticket-spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}

.subticket-loading-text {
    margin-top: 15px;
    font-size: 14px;
    color: #555;
    font-weight: 500;
}

/* Section Styling */
.subticket-section {
    margin-bottom: 20px;
}

.subticket-section:last-child {
    margin-bottom: 0;
}

.subticket-section h3 {
    margin: 0 0 10px 0;
    font-size: 16px;
    color: #333;
    border-bottom: 1px solid #ddd;
    padding-bottom: 5px;
}

/* Parent/Children Info */
.parent-info,
.children-list {
    margin: 10px 0;
}

.children-list {
    list-style: none;
    padding: 0;
    margin: 10px 0;
}

.child-item {
    padding: 8px 10px;
    background: transparent;
    border: 1px solid #ddd;
    border-radius: 3px;
    margin-bottom: 8px;
}

.child-item:last-child {
    margin-bottom: 0;
}

.ticket-link {
    color: #0066cc;
    text-decoration: none;
}

.ticket-link:hover {
    text-decoration: underline;
}

.status-label {
    color: #666;
    font-size: 13px;
}

.no-data {
    color: #999;
    font-style: italic;
    margin: 10px 0;
}

/* Button Styling */
.subticket-action {
    margin-top: 10px;
    margin-right: 5px;
}

.subticket-action:disabled {
    cursor: not-allowed;
    opacity: 0.5;
}
</style>
CSS;
    }

    /**
     * Get JavaScript for loading the panel script
     */
    public function getJavaScript(): string
    {
        $jsUrl = $this->pluginUrl . '/js/subticket-panel.js';

        return <<<JS
<script>
// Load subticket panel JavaScript (with jQuery wait)
(function loadPanelScript() {
    'use strict';

    if (typeof jQuery === 'undefined') {
        setTimeout(loadPanelScript, 100);
        return;
    }

    var script = document.createElement('script');
    script.src = '$jsUrl';
    script.async = false;
    script.onerror = function() {
        console.error('[Subticket] Failed to load subticket-panel.js');
    };
    document.head.appendChild(script);
})();
</script>
JS;
    }

    /**
     * Get JavaScript for queue indicator
     */
    public function getQueueIndicatorJavaScript(): string
    {
        $jsUrl = $this->pluginUrl . '/js/queue-indicator.js';

        return <<<JS
<script>
// Load queue indicator JavaScript (with jQuery wait)
(function loadQueueIndicator() {
    'use strict';

    if (typeof jQuery === 'undefined') {
        setTimeout(loadQueueIndicator, 100);
        return;
    }

    var script = document.createElement('script');
    script.src = '$jsUrl';
    script.async = false;
    script.onerror = function() {
        console.error('[SubticketManager] Failed to load queue-indicator.js');
    };
    document.head.appendChild(script);
})();
</script>
JS;
    }
}
