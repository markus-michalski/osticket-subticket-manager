# Subticket Manager Plugin for osTicket

[🇩🇪 Deutsche Version](./README-de.md)

## Overview

The Subticket Manager plugin transforms osTicket's hidden parent/child ticket infrastructure into a fully functional, user-friendly feature. While osTicket provides the database structure (`ticket_pid` field) and API methods, it lacks any user interface or workflow automation.

This plugin provides a complete solution for managing hierarchical ticket relationships with visual indicators, workflow automation, and an intuitive interface for creating and managing subtickets.

## Key Features

- ✅ **Visual Parent Indicators** - Code-fork icons in queue lists show parent tickets at a glance
- ✅ **Parent Badge** - Prominent badge below ticket number displays "Parent Ticket (X Sub-Tickets)"
- ✅ **Integrated Panel** - Manage parent/child relationships directly in ticket view
- ✅ **Link/Unlink Tickets** - Connect existing tickets or create new subtickets
- ✅ **Auto-Close Workflow** - Parent tickets automatically close when all children are closed
- ✅ **AJAX-Based** - Real-time updates without page reloads
- ✅ **No Core Modifications** - Uses osTicket's native infrastructure, no core file changes

## Key Advantages

**Why this plugin is essential:**

osTicket already has the technical foundation for parent/child tickets (database fields, API methods like `isChild()`, `getPid()`), but provides:
- ❌ No user interface to manage relationships
- ❌ No visual indicators in ticket lists
- ❌ No workflow automation
- ❌ No easy way to create or link subtickets

This plugin makes osTicket's hidden parent/child infrastructure **actually usable** for daily support operations.

## Use Cases

- **Complex Support Requests** - Break down large issues into manageable subtasks
- **Multi-Step Workflows** - Track progress across related tickets
- **Team Collaboration** - Distribute work across multiple agents with clear hierarchy
- **Project Management** - Use parent tickets as project containers
- **Feature Requests** - Split implementation into tracked subtasks
- **Bug Tracking** - Create separate tickets for testing, fixing, and documentation

## Requirements

- osTicket **1.18.x**
- PHP **7.4+** (recommended: PHP 8.1+)
- jQuery (included in osTicket)
- MariaDB **10.3+** or MySQL **5.7+**

## Installation

### Step 1: Install Plugin Files

#### Method 1: ZIP Download (Recommended)
1. Download latest release from [Releases](https://github.com/markus-michalski/osticket-plugins/releases)
2. Extract ZIP file
3. Upload `subticket-manager` folder to `/include/plugins/` on osTicket server

#### Method 2: Git Repository
```bash
cd /path/to/osticket/include/plugins
git clone https://github.com/markus-michalski/osticket-plugins.git
# Plugin will be in: osticket-plugins/subticket-manager/
```

### Step 2: Enable Plugin in osTicket
1. Login to osTicket Admin Panel
2. Navigate to: **Admin Panel → Manage → Plugins**
3. Find "Subticket Manager" in list
4. Click **Enable**
5. Plugin will automatically activate - no additional configuration needed

### Step 3: Verify Installation
1. Open any ticket
2. Check for the **Parent Ticket / Child Tickets** panel above the ticket tabs
3. Open a queue list - parent tickets should show code-fork icons (🔀)

## Usage

### Creating a Subticket

1. Open the parent ticket
2. Scroll to the **Child Tickets** section
3. Click **Create Subticket**
4. You'll be redirected to the "New Ticket" form
5. After creating the ticket, it will be automatically linked to the parent

### Linking an Existing Ticket

1. Open the ticket you want to make a child
2. Click **Link to Parent** in the **Parent Ticket** section
3. Enter the parent ticket number or ID
4. Click **Link** to confirm

### Unlinking Tickets

1. Open the child ticket
2. In the **Parent Ticket** section, click **Unlink from Parent**
3. Confirm the action

Or from the parent ticket:
1. Open the parent ticket
2. In the **Child Tickets** list, click **Unlink** next to any child
3. Confirm the action

### Visual Indicators

**Queue Lists:**
- Parent tickets display a code-fork icon (🔀) with child count
- Hover over the icon to see "X Sub-Ticket(s)"

**Ticket View:**
- Parent tickets show a prominent blue badge below the ticket number
- Badge displays "Parent Ticket (X Sub-Tickets)"

## Configuration

The plugin requires no configuration - it works out of the box using osTicket's native parent/child infrastructure.

All features are automatically enabled:
- Visual indicators in queue lists
- Parent badge in ticket view
- Link/unlink functionality
- Create subticket workflow

## Troubleshooting

### Problem: Parent indicators not showing in queue lists

**Check:**
- Clear browser cache (Ctrl+Shift+R)
- Check browser console for JavaScript errors
- Verify jQuery is loaded (osTicket default)
- Enable debug mode: `SubticketPanel.debug = true` in browser console

### Problem: "No parent ticket" shown but relationship exists

**Check:**
- Database: `SELECT ticket_pid FROM ost_ticket WHERE ticket_id = X`
- If `ticket_pid` is NULL, relationship doesn't exist in database
- Try unlinking and re-linking the tickets

### Problem: Panel not visible in ticket view

**Check:**
- Plugin is enabled in Admin Panel → Plugins
- Clear browser cache
- Check if panel is collapsed (may be hidden by CSS)
- Verify you're in the staff interface (not client portal)

### Problem: Cannot create subticket

**Check:**
- You have permission to create tickets
- Parent ticket is not closed (cannot create subtickets for closed tickets)
- Check Apache error log for PHP errors

### Problem: Badge appears multiple times

**Check:**
- This was fixed in v1.5.1 - update to latest version
- Clear browser cache after updating

## 📄 License

This Plugin is released under the GNU General Public License v2, compatible with osTicket core.

See [LICENSE](./LICENSE) for details.

## 💬 Support

For questions or issues, please create an issue on GitHub:
https://github.com/markus-michalski/osticket-plugins/issues

## 🤝 Contributing

Developed by [Markus Michalski](https://github.com/markus-michalski)

Inspired by the osTicket community's need for a user-friendly way to manage parent/child ticket relationships using osTicket's native infrastructure.

## Changelog

See [CHANGELOG.md](./CHANGELOG.md) for version history.
