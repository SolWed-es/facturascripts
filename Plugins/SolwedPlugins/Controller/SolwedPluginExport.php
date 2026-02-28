<?php
/**
 * SolwedPluginExport Controller
 *
 * This controller should be deployed on your Demo ERP (Plesk instance).
 * It provides endpoints to list and download plugins as ZIP files.
 *
 * Deploy this file to: C:\...\Plugins\SolwedPlugins\Controller\SolwedPluginExport.php
 * on your Demo ERP server.
 */

namespace FacturaScripts\Plugins\SolwedPlugins\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Tools;
use ZipArchive;

/**
 * Controller to export plugins from this FacturaScripts installation
 * Allows other instances to install plugins from this Demo ERP
 */
class SolwedPluginExport extends Controller
{
    /**
     * Public access - no login required
     */
    public function publicCore(&$response)
    {
        parent::publicCore($response);

        $action = $this->request->get('action', 'list');

        switch ($action) {
            case 'list':
                $this->listPlugins();
                break;

            case 'download':
                $this->downloadPlugin();
                break;

            default:
                $this->sendJsonResponse(['error' => 'Invalid action'], 400);
                break;
        }
    }

    /**
     * List all plugins in JSON format
     */
    private function listPlugins(): void
    {
        try {
            Plugins::load();
            $allPlugins = Plugins::list(true); // Include hidden plugins

            // Build absolute base URL
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $baseUrl = $scheme . '://' . $host . $this->url();

            $pluginList = [];
            foreach ($allPlugins as $plugin) {
                $pluginList[] = [
                    'name' => $plugin->name,
                    'version' => $plugin->version,
                    'description' => $plugin->description,
                    'enabled' => $plugin->enabled,
                    'compatible' => $plugin->compatible,
                    'min_version' => $plugin->min_version,
                    'min_php' => $plugin->min_php ?? '',
                    'require' => $plugin->require,
                    'folder' => $plugin->folder,
                    'last_updated' => date('Y-m-d', filemtime(FS_FOLDER . '/Plugins/' . $plugin->name)),
                    'source' => 'demo',
                    'download_url' => $baseUrl . '?action=download&plugin=' . urlencode($plugin->name)
                ];
            }

            $this->sendJsonResponse(['plugins' => $pluginList]);
        } catch (\Exception $e) {
            Tools::log()->error('Error listing plugins', ['error' => $e->getMessage()]);
            $this->sendJsonResponse(['error' => 'Failed to list plugins'], 500);
        }
    }

    /**
     * Download a plugin as ZIP file
     */
    private function downloadPlugin(): void
    {
        $pluginName = $this->request->get('plugin', '');

        if (empty($pluginName)) {
            $this->sendJsonResponse(['error' => 'Plugin name is required'], 400);
            return;
        }

        // Validate plugin name (security: prevent directory traversal)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $pluginName)) {
            Tools::log()->warning('Invalid plugin name attempted', ['plugin' => $pluginName]);
            $this->sendJsonResponse(['error' => 'Invalid plugin name'], 400);
            return;
        }

        $pluginPath = FS_FOLDER . '/Plugins/' . $pluginName;

        // Verify plugin exists
        if (!is_dir($pluginPath)) {
            Tools::log()->warning('Plugin not found', ['plugin' => $pluginName, 'path' => $pluginPath]);
            $this->sendJsonResponse(['error' => 'Plugin not found'], 404);
            return;
        }

        try {
            // Create temporary ZIP file
            $tempDir = FS_FOLDER . '/MyFiles/tmp';
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }

            $zipFile = $tempDir . '/' . $pluginName . '_' . time() . '.zip';

            // Create ZIP archive
            $zip = new ZipArchive();
            if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                Tools::log()->error('Failed to create ZIP file', ['file' => $zipFile]);
                $this->sendJsonResponse(['error' => 'Failed to create ZIP file'], 500);
                return;
            }

            // Add all files from plugin directory to ZIP
            $this->addDirectoryToZip($zip, $pluginPath, $pluginName);
            $zip->close();

            // Verify ZIP was created and has content
            if (!file_exists($zipFile) || filesize($zipFile) === 0) {
                Tools::log()->error('ZIP file is empty or missing', ['file' => $zipFile]);
                $this->sendJsonResponse(['error' => 'Failed to create valid ZIP file'], 500);
                return;
            }

            Tools::log()->notice('Plugin ZIP created', [
                'plugin' => $pluginName,
                'size' => filesize($zipFile) . ' bytes'
            ]);

            // Send ZIP file to browser
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="' . $pluginName . '.zip"');
            header('Content-Length: ' . filesize($zipFile));
            header('Cache-Control: no-cache, must-revalidate');
            header('Pragma: no-cache');

            readfile($zipFile);

            // Clean up temporary file
            unlink($zipFile);

            // Stop further processing
            die();
        } catch (\Exception $e) {
            Tools::log()->error('Error creating plugin ZIP', [
                'plugin' => $pluginName,
                'error' => $e->getMessage()
            ]);
            $this->sendJsonResponse(['error' => 'Failed to create plugin ZIP'], 500);
        }
    }

    /**
     * Recursively add directory contents to ZIP archive
     *
     * @param ZipArchive $zip ZIP archive object
     * @param string $sourcePath Absolute path to source directory
     * @param string $zipPath Path inside ZIP archive
     */
    private function addDirectoryToZip(ZipArchive $zip, string $sourcePath, string $zipPath): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourcePath),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            // Skip directories and special files
            if ($file->isDir() || $file->getFilename() === '.' || $file->getFilename() === '..') {
                continue;
            }

            // Get real and relative path for current file
            $filePath = $file->getRealPath();
            $relativePath = $zipPath . '/' . substr($filePath, strlen($sourcePath) + 1);

            // Add file to ZIP
            $zip->addFile($filePath, $relativePath);
        }
    }

    /**
     * Send JSON response and stop execution
     *
     * @param array $data Data to send as JSON
     * @param int $statusCode HTTP status code
     */
    private function sendJsonResponse(array $data, int $statusCode = 200): void
    {
        http_response_code($statusCode);
        header('Content-Type: application/json');
        echo json_encode($data, JSON_PRETTY_PRINT);
        die();
    }

    /**
     * Get page data for menu and title
     */
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'Plugin Export API';
        $data['icon'] = 'fas fa-download';
        $data['showonmenu'] = false; // Hidden from menu
        return $data;
    }
}
