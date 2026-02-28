<?php
/**
 * Dashboard controller override for SolwedTheme
 * Disables update checks to avoid HTTP delays
 */

namespace FacturaScripts\Plugins\SolwedTheme\Controller;

use FacturaScripts\Core\Response;
use FacturaScripts\Dinamic\Model\User;

class Dashboard extends \FacturaScripts\Core\Controller\Dashboard
{
    /**
     * Runs the controller's private logic.
     * Override to disable update checks
     *
     * @param Response $response
     * @param User $user
     * @param ControllerPermissions $permissions
     */
    public function privateCore(&$response, $user, $permissions): void
    {
        parent::privateCore($response, $user, $permissions);

        // Skip the update check to avoid HTTP delays
        $this->updated = true;
    }
}
