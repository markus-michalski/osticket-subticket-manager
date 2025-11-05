<?php
/**
 * Applications Index Page
 *
 * Overview of all available applications in the system.
 */

require('staff.inc.php');

// Check staff permissions
if (!$thisstaff || !$thisstaff->isStaff()) {
    Http::response(403, 'Access Denied');
    exit;
}

// Page title
$nav->setTabActive('apps');
$ost->addExtraHeader('<style>
.app-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    margin-top: 20px;
}
.app-card {
    background: #fff;
    border: 1px solid #ddd;
    border-radius: 4px;
    padding: 20px;
    transition: box-shadow 0.2s;
}
.app-card:hover {
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.app-card h3 {
    margin-top: 0;
    color: #333;
}
.app-card p {
    color: #666;
    margin: 10px 0;
}
.app-card a.btn {
    display: inline-block;
    background: #0066cc;
    color: #fff;
    padding: 8px 16px;
    text-decoration: none;
    border-radius: 3px;
    margin-top: 10px;
}
.app-card a.btn:hover {
    background: #0052a3;
}
.app-icon {
    font-size: 48px;
    color: #0066cc;
    margin-bottom: 10px;
}
</style>');

// Render with staff header
include(STAFFINC_DIR . 'header.inc.php');
?>

<div id="apps-page">
    <h1>Anwendungen</h1>
    <p style="color: #666; margin-bottom: 30px;">
        Übersicht aller verfügbaren Anwendungen für die Ticket-Verwaltung.
    </p>

    <div class="app-grid">
        <div class="app-card">
            <div class="app-icon">
                <i class="icon-sitemap"></i>
            </div>
            <h3>Ticket Hierarchies</h3>
            <p>
                Verwalten Sie Parent-Child Beziehungen zwischen Tickets.
                Erstellen Sie Subtickets und behalten Sie die Übersicht über komplexe Ticket-Strukturen.
            </p>
            <a href="subtickets.php" class="btn">
                Übersicht öffnen <i class="icon-chevron-right"></i>
            </a>
        </div>

        <!-- Weitere Anwendungen können hier ergänzt werden -->
    </div>
</div>

<?php
include(STAFFINC_DIR . 'footer.inc.php');
?>
