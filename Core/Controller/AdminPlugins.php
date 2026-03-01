<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2017-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>.
 */

namespace FacturaScripts\Core\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Core\Base\ControllerPermissions;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Http;
use FacturaScripts\Core\Internal\SolwedDemoPlugins;
use FacturaScripts\Core\Internal\SolwedGitHubPlugins;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\UploadedFile;
use FacturaScripts\Dinamic\Model\User;

/**
 * AdminPlugins.
 *
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
class AdminPlugins extends Controller
{
    /** @var array */
    public $pluginList = [];

    /** @var bool */
    public $registered = false;

    /** @var array */
    public $remotePluginList = [];

    /** @var array */
    public $solwedPluginList = [];

    /** @var bool */
    public $updated = false;

    public function getMaxFileUpload(): float
    {
        return UploadedFile::getMaxFilesize() / 1024 / 1024;
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'plugins';
        $data['icon'] = 'fa-solid fa-plug';
        return $data;
    }

    /**
     * Runs the controller's private logic.
     *
     * @param Response $response
     * @param User $user
     * @param ControllerPermissions $permissions
     */
    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $action = $this->request->inputOrQuery('action', '');
        switch ($action) {
            case 'disable':
                $this->disablePluginAction();
                break;

            case 'enable':
                $this->enablePluginAction();
                break;

            case 'rebuild':
                $this->rebuildAction();
                break;

            case 'remove':
                $this->removePluginAction();
                break;

            case 'github-install':
                $this->githubInstallAction();
                break;

            case 'upload':
                $this->uploadPluginAction();
                break;

            default:
                $this->extractPluginsZipFiles();
                $this->disableIncompatiblePlugins();
                if (FS_DEBUG) {
                    // On debug mode, always deploy the contents of Dinamic.
                    Plugins::deploy(true, true);
                    Cache::clear();
                }
                break;
        }

        // cargamos la lista de plugins
        $this->pluginList = Plugins::list();
        $this->loadRemotePluginList();
        $this->loadSolwedPlugins();

        // las actualizaciones se gestionan en el Updater
        $this->registered = true;
        $this->updated = true;
    }

    private function disablePluginAction(): void
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return;
        } elseif (false === $this->validateFormToken()) {
            return;
        }

        $pluginName = $this->request->queryOrInput('plugin', '');
        Plugins::disable($pluginName);
        Cache::clear();
    }

    private function disableIncompatiblePlugins(): void
    {
        $disabled = [];
        foreach (Plugins::list() as $plugin) {
            // si el plugin no está activo, continuamos
            if (false === $plugin->enabled) {
                continue;
            }

            // si el plugin es compatible, continuamos
            if ($plugin->compatible) {
                continue;
            }

            // desactivamos el plugin incompatible
            if (Plugins::disable($plugin->name, false)) {
                $disabled[] = $plugin->name;
            }
        }

        // si hemos desactivado algún plugin, informamos al usuario
        if (count($disabled) > 0) {
            Tools::log()->warning('plugins-disabled-incompatible', [
                '%plugins%' => implode(', ', $disabled)
            ]);
        }
    }

    private function enablePluginAction(): void
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-modify');
            return;
        } elseif (false === $this->validateFormToken()) {
            return;
        }

        $pluginName = $this->request->queryOrInput('plugin', '');
        Plugins::enable($pluginName);
        Cache::clear();
    }

    private function extractPluginsZipFiles(): void
    {
        $ok = false;
        foreach (Tools::folderScan(Plugins::folder()) as $zipFileName) {
            // si el archivo no es un zip, lo ignoramos
            if (pathinfo($zipFileName, PATHINFO_EXTENSION) !== 'zip') {
                continue;
            }

            // instalamos el plugin
            $zipPath = Plugins::folder() . DIRECTORY_SEPARATOR . $zipFileName;
            if (Plugins::add($zipPath, $zipFileName)) {
                $ok = true;
                unlink($zipPath);
            }
        }

        if ($ok) {
            Tools::log()->notice('reloading');
            $this->redirect($this->url(), 3);
        }
    }

    private function loadRemotePluginList(): void
    {
        if (Tools::config('disable_add_plugins', false)) {
            return;
        }

        $installedMap = [];
        foreach ($this->pluginList as $plugin) {
            $installedMap[$plugin->name] = $plugin->version;
        }

        foreach (SolwedDemoPlugins::getPluginMap() as $item) {
            if (!isset($installedMap[$item['name']])) {
                // no instalado → mostrar botón Instalar
                $this->remotePluginList[] = $item;
            } elseif ((float)($item['version'] ?? 0) > (float)$installedMap[$item['name']]) {
                // versión más reciente disponible → mostrar botón Actualizar
                $item['needs_update'] = true;
                $item['installed_version'] = $installedMap[$item['name']];
                $this->remotePluginList[] = $item;
            }
        }
    }

    private function rebuildAction(): void
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-update');
            return;
        } elseif (false === $this->validateFormToken()) {
            return;
        }

        Plugins::deploy(true, true);
        Cache::clear();
        Tools::log()->notice('rebuild-completed');
    }

    private function removePluginAction(): void
    {
        if (false === $this->permissions->allowDelete) {
            Tools::log()->warning('not-allowed-delete');
            return;
        } elseif (false === $this->validateFormToken()) {
            return;
        }

        $pluginName = $this->request->queryOrInput('plugin', '');
        Plugins::remove($pluginName);
        Cache::clear();
    }

    private function githubInstallAction(): void
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-update');
            return;
        } elseif (false === $this->validateFormToken()) {
            return;
        }

        $pluginName = $this->request->queryOrInput('plugin', '');
        if (empty($pluginName)) {
            Tools::log()->error('plugin-name-required');
            return;
        }

        // buscar en ambas fuentes; GitHub tiene prioridad sobre demo
        $allMap = array_merge(SolwedDemoPlugins::getPluginMap(), SolwedGitHubPlugins::getPluginMap());
        if (!isset($allMap[$pluginName])) {
            Tools::log()->error('plugin-not-found-source', ['%plugin%' => $pluginName, '%source%' => 'SolWed']);
            return;
        }

        $pluginInfo = $allMap[$pluginName];
        $version = $pluginInfo['version'] ?? '0';
        $downloadUrl = $pluginInfo['download_url'] ?? SolwedGitHubPlugins::getDownloadUrl($pluginName, $version);

        $tmpFile = Tools::folder('MyFiles/Tmp') . DIRECTORY_SEPARATOR . $pluginName . '.zip';
        if (!is_dir(dirname($tmpFile))) {
            mkdir(dirname($tmpFile), 0755, true);
        }

        $response = Http::get($downloadUrl)
            ->setTimeout(45)
            ->setHeader('User-Agent', 'FacturaScripts-SolWed/1.0')
            ->setHeader('Accept', 'application/octet-stream');

        if ($response->failed() || empty($response->body())) {
            Tools::log()->error('download-failed', ['%url%' => $downloadUrl]);
            return;
        }

        file_put_contents($tmpFile, $response->body());

        if (Plugins::add($tmpFile, $pluginName . '.zip')) {
            if (false === Plugins::enable($pluginName)) {
                Tools::log()->warning('plugin-enable-failed', ['%plugin%' => $pluginName]);
            } else {
                Tools::log()->notice('reloading');
                $this->redirect($this->url(), 3);
            }
        }

        if (file_exists($tmpFile)) {
            unlink($tmpFile);
        }

        Cache::clear();
    }

    private function loadSolwedPlugins(): void
    {
        if (Tools::config('disable_add_plugins', false)) {
            return;
        }

        $installedMap = [];
        foreach ($this->pluginList as $plugin) {
            $installedMap[$plugin->name] = $plugin->version;
        }

        foreach (SolwedGitHubPlugins::getPluginMap() as $plugin) {
            if (!isset($installedMap[$plugin['name']])) {
                // no instalado → mostrar botón Instalar
                $this->solwedPluginList[] = $plugin;
            } elseif ((float)($plugin['version'] ?? 0) > (float)$installedMap[$plugin['name']]) {
                // versión más reciente disponible → mostrar botón Actualizar
                $plugin['needs_update'] = true;
                $plugin['installed_version'] = $installedMap[$plugin['name']];
                $this->solwedPluginList[] = $plugin;
            }
        }
    }

    private function uploadPluginAction(): void
    {
        if (false === $this->permissions->allowUpdate) {
            Tools::log()->warning('not-allowed-update');
            return;
        } elseif (false === $this->validateFormToken()) {
            return;
        }

        $ok = true;
        $uploadFiles = $this->request->files->getArray('plugin');
        foreach ($uploadFiles as $uploadFile) {
            if (false === $uploadFile->isValid()) {
                Tools::log()->error($uploadFile->getErrorMessage());
                continue;
            }

            if ($uploadFile->getMimeType() !== 'application/zip') {
                Tools::log()->error('file-not-supported');
                continue;
            }

            if (false === Plugins::add($uploadFile->getPathname(), $uploadFile->getClientOriginalName())) {
                $ok = false;
            }

            unlink($uploadFile->getPathname());
        }

        Cache::clear();

        if ($ok) {
            Tools::log()->notice('reloading');
            $this->redirect($this->url(), 3);
        }
    }
}
