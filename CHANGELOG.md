# Changelog - subticket-manager

All notable changes to this plugin will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- The hierarchy limits from the plugin configuration are now enforced when tickets are linked: `max_depth`, `max_children`, `require_parent_open` and `allow_nested_subtickets`. Values outside their range are rejected when the configuration is saved.
- `TicketAccessGuard`: one place that answers "may this agent view / link this ticket", backed by osTicket's `Ticket::checkStaffPerm()` and the `ticket.link` role permission.

### Changed
- Linking a ticket now resolves the parent number first and checks the agent's permission on both tickets before anything is written.
- The parent-chain walk refuses a link when the chain revisits a ticket or is longer than 10 levels, instead of treating it as safe.
- `batch_parent_status` is rate limited like the other AJAX actions.
- The debug log (when `SUBTICKET_DEBUG` is on) is written to the system temp directory with mode 0600 and never through a symlink; `SUBTICKET_DEBUG_LOG` overrides the path.
- AJAX errors return a generic message; the exception detail goes to the server error log only (`Throwable`, not just `Exception`).
- CI workflow runs with `contents: read` permissions.

### Deprecated
- Nothing yet

### Removed
- AJAX action `create` and the matching JavaScript method. It bypassed `Ticket::open()` and therefore the agent's create permission and department; the UI never used it (new subtickets go through the regular new-ticket form).
- Configuration options that nothing read: `auto_close_parent`, `cascade_hold`, `cascade_assignment`, `show_children_in_queue`, `notify_on_auto_close` (#6). Auto-close is not implemented.

### Fixed
- Queue indicator loader is now inserted into `<head>` via an output buffer instead of being echoed during plugin bootstrap. The echo landed in front of `<!DOCTYPE html>`, which put the staff pages into quirks mode and positioned osTicket's dialogs (e.g. the due date editor) off-screen on long ticket pages. PJAX fragments keep receiving the loader at the top, as before.
- All queries respect osTicket's configurable table prefix (`TABLE_PREFIX` in `ost-config.php`) through the new `Table::name()` helper. Installations with a prefix other than `ost_` got "Ticket not found" on every lookup, and `isCompatible()` reported a false negative (completes #5).
- The foreign-key existence check is scoped to the current database schema and the constraint's own name.
- `.releaseinclude` lists `src/Security/TicketAccessGuard.php` and `src/Database/Table.php`, so the release package contains them.
- Uninstall cleanup works on MySQL: `DROP COLUMN IF EXISTS` and `DROP INDEX IF EXISTS` are MariaDB-only and are replaced by an existence check in PHP.
- `js/queue-indicator.js` used `arguments.callee` under `'use strict'`, which throws a `TypeError` whenever jQuery is not loaded yet, so the retry never ran.
- `composer.json` declared MIT while `LICENSE`, the README and the code headers say GPL-2.0 (#8). The manifest URL in `plugin.php` pointed to a repository that no longer exists; its description still said "(Phase 3)".
- README: the link to a German version that never existed is gone, the feature list no longer claims an auto-close workflow, the configuration section lists the options that exist, and the troubleshooting entry about closed parents refers to `require_parent_open`.

### Removed
- `scp/apps.php`. It was copied into osTicket's `scp/` directory on every enable but linked from nowhere (the admin overview is registered as `subtickets.php`). Uninstall still removes a copy left by an earlier version.
- German strings in the otherwise English admin overview page.

### Security
- The `subticket_parent` hand-off from the ticket view to the new-ticket form could be set from any page, including the client portal, never expired, and was applied with a raw UPDATE without checking who the agent was or whether the parent was accessible. The candidate is now only accepted from `scp/tickets.php?a=open`, expires after 10 minutes, and is consumed only in a staff request by an agent who may link the parent, through the same checks as a manual link.
- `action=children` returned the children of any ticket id to any agent; it now requires view access to the parent and leaves out children the agent may not view (#7).
- `link` and `unlink` checked only the current ticket; the other end of the link is now checked as well (#7).
- `canAccessDept()` was called with an integer where osTicket expects a `Dept` object, so it always returned `false` and only admins could link or unlink, and admins could do so across all departments. Replaced by `Ticket::checkStaffPerm()`.
- The ticket-view panel was rendered for the `object.view` signal of the client portal as well, showing parent and child ticket numbers and subjects to clients. The panel is now staff-only, redacts a parent the agent may not view and hides children the agent may not view.
- `action=version` disclosed absolute paths, file sizes and timestamps, the PHP version and OPcache status to every agent; it is admin-only and returns the plugin version only.
- Dev dependency `phpunit/phpunit` updated past CVE-2026-24765 (not part of the release package).

## [2.0.3] - 2026-05-26

### Changed
- add git workflow files to .gitignore
- untrack .phpunit.result.cache (already in .gitignore)

### Fixed
- resolve plugin directory dynamically instead of hardcoded path (#4)

## [2.0.2] - 2026-02-03

### Changed
- added test file to .gitignore
- add @disregard annotation and return type for IDE compatibility

### Fixed
- accept null changes from Signal::send() in model.updated handler

## [2.0.1] - 2025-12-16

### Fixed
- prevent duplicate event handlers and double AJAX requests
- allow admin users to bypass department access check
- support alphanumeric ticket numbers in parent linking

## [2.0.0] - 2025-12-16

### Changed
- improve vendor section documentation in .releaseinclude
- extract God Class into PSR-4 service architecture
- improve code quality and security

### Fixed
- remove debug logging calls causing production errors

## [1.0.2] - 2025-11-08

### Changed
- Remove console logs and reduce production log noise

## [1.0.1] - 2025-11-06

### Changed
- Add build artifacts to .gitignore

### Fixed
- Load queue indicators on all queue pages, not just tickets.php

## [1.0.0] - 2025-11-06

### Changed
- Add FAQ page reference and fix GitHub links
- Add GitHub Actions workflow for PHP testing
- Initial repository setup for Subticket Manager Plugin

### Fixed
- Update SQL query assertion to match actual implementation
- Update phpunit.xml.dist to match actual project structure
- Lock doctrine/instantiator to 1.x for PHP 7.4/8.0 support
- Make annotate() method static in QueueColumnAnnotation mock
- Add QueueColumnAnnotation mock for PHPUnit tests
- Add missing Queue mock for PHPUnit tests

[2.0.3]: https://github.com/markus-michalski/osticket-subticket-manager/releases/tag/v2.0.3
