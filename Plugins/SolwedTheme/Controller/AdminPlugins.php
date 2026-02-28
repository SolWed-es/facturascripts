<?php
/**
 * AdminPlugins controller override for SolwedTheme
 * Disables update checks to avoid HTTP delays
 */

namespace FacturaScripts\Plugins\SolwedTheme\Controller;

use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Telemetry;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\UploadedFile;
use FacturaScripts\Dinamic\Model\User;

class AdminPlugins extends \FacturaScripts\Core\Controller\AdminPlugins
{
    public function getMaxFileUpload(): float
    {
        return UploadedFile::getMaxFilesize() / 1024 / 1024;
    }

    /**
     * Runs the controller's private logic.
     * Override to disable update checks
     *
     * @param Response $response
     * @param User $user
     * @param ControllerPermissions $permissions
     */
    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        // Skip the update check to avoid HTTP delays
        $this->updated = true;
    }
}
