<?php

declare(strict_types=1);

namespace SubticketManager\Asset;

/**
 * AssetDeployer - Deploys plugin files to osTicket directories
 *
 * Handles deployment of:
 * - Admin pages to /scp/ directory
 * - AJAX handlers to /scp/ directory
 * - Version tracking for auto-deployment
 *
 * @package SubticketManager
 */
final class AssetDeployer
{
    private string $pluginDir;
    private string $scpDir;
    private string $versionFile;

    public function __construct(string $pluginDir, string $scpDir)
    {
        $this->pluginDir = rtrim($pluginDir, '/');
        $this->scpDir = rtrim($scpDir, '/');
        $this->versionFile = $this->scpDir . '/.subticket-deployed-version';
    }

    /**
     * Deploy all plugin files
     *
     * @param array<string> $errors Error messages (by reference)
     * @return bool True if all deployments successful
     */
    public function deployAll(array &$errors): bool
    {
        $results = [
            $this->deployFile('scp-files/subtickets.php', 'subtickets.php', $errors),
            $this->deployFile('scp-files/ajax-subticket.php', 'ajax-subticket.php', $errors),
            $this->deployFile('scp-files/apps.php', 'apps.php', $errors),
        ];

        return !in_array(false, $results, true);
    }

    /**
     * Deploy a single file from plugin to scp/ directory
     *
     * @param string $sourceRelative Source path relative to plugin dir
     * @param string $targetFilename Target filename in scp/
     * @param array<string> $errors Error messages (by reference)
     * @return bool True on success
     */
    public function deployFile(string $sourceRelative, string $targetFilename, array &$errors): bool
    {
        $source = $this->pluginDir . '/' . $sourceRelative;
        $target = $this->scpDir . '/' . $targetFilename;

        $this->log('Deploying file', "Source: $source | Target: $target");

        // Validate source exists
        if (!file_exists($source)) {
            $error = "Source file not found: $source";
            $this->log('ERROR: Source not found', $error);
            $errors[] = $error;
            return false;
        }

        // Validate target directory
        if (!is_dir($this->scpDir)) {
            $error = "Target directory not found: {$this->scpDir}";
            $this->log('ERROR: Directory not found', $error);
            $errors[] = $error;
            return false;
        }

        if (!is_writable($this->scpDir)) {
            $error = "Target directory not writable: {$this->scpDir}";
            $this->log('ERROR: Directory not writable', $error);
            $errors[] = $error;
            return false;
        }

        // Copy file (overwrite if exists)
        if (!@copy($source, $target)) {
            $error = "Failed to deploy file to scp/$targetFilename";
            $this->log('ERROR: Copy failed', $error);
            $errors[] = $error;
            return false;
        }

        // Verify copy
        if (!file_exists($target)) {
            $this->log('ERROR: Verification failed', 'Target file does not exist after copy');
            return false;
        }

        $this->log('File deployed', sprintf(
            'Source: %d bytes | Target: %d bytes',
            filesize($source),
            filesize($target)
        ));

        return true;
    }

    /**
     * Check if deployment is needed based on version
     *
     * @param string $currentVersion Current plugin version
     * @return bool True if deployment is needed
     */
    public function isDeploymentNeeded(string $currentVersion): bool
    {
        if (!file_exists($this->versionFile)) {
            return true;
        }

        $deployedVersion = trim(file_get_contents($this->versionFile));
        return $deployedVersion !== $currentVersion;
    }

    /**
     * Update deployed version marker
     *
     * @param string $version Version to store
     * @return bool True on success
     */
    public function updateVersionMarker(string $version): bool
    {
        return file_put_contents($this->versionFile, $version) !== false;
    }

    /**
     * Remove all deployed files
     *
     * Called during plugin uninstall
     *
     * @return bool True if all files removed
     */
    public function removeAll(): bool
    {
        $files = [
            $this->scpDir . '/subtickets.php',
            $this->scpDir . '/ajax-subticket.php',
            $this->scpDir . '/apps.php',
            $this->versionFile,
        ];

        $success = true;
        foreach ($files as $file) {
            if (file_exists($file)) {
                if (@unlink($file)) {
                    $this->log('File removed', $file);
                } else {
                    $this->log('Failed to remove file', $file);
                    $success = false;
                }
            }
        }

        return $success;
    }

    /**
     * Log message (uses global subticket_log if available)
     */
    private function log(string $title, string $message = ''): void
    {
        if (function_exists('subticket_log')) {
            subticket_log($title, $message);
        }
    }
}
