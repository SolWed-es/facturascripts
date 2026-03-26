<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2018-2025 Carlos Garcia Gomez <carlos@facturascripts.com>
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
use FacturaScripts\Core\Internal\Forja;
use FacturaScripts\Core\Internal\MindClient;
use FacturaScripts\Core\Internal\Plugin;
use FacturaScripts\Core\Internal\SolwedDemoPlugins;
use FacturaScripts\Core\Internal\SolwedGitHub;
use FacturaScripts\Core\Kernel;
use FacturaScripts\Core\Migrations;
use FacturaScripts\Core\Plugins;
use FacturaScripts\Core\Response;
use FacturaScripts\Core\Tools;
use FacturaScripts\Dinamic\Model\User;
use ZipArchive;

/**
 * Description of Updater
 *
 * @author Carlos García Gómez <carlos@facturascripts.com>
 */
class Updater extends Controller
{
    const CORE_ZIP_FOLDER = 'facturascripts';
    const SOLWED_CORE_ITEM_ID = 'solwed-core';

    /** @var array */
    public $coreUpdateWarnings = [];

    /** @var \FacturaScripts\Core\Telemetry */
    public $telemetryManager;

    /** @var array */
    public $updaterItems = [];

    public function __construct(string $className, string $uri = '')
    {
        // si no existe el archivo Empresa en Dinamic, reconstruimos
        if (!file_exists(Tools::folder('Dinamic', 'Model', 'Empresa.php'))) {
            Plugins::deploy(true, false);
        }

        parent::__construct($className, $uri);
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'admin';
        $data['title'] = 'updater';
        $data['icon'] = 'fa-solid fa-cloud-download-alt';
        return $data;
    }

    public static function getCoreVersion(): float
    {
        return Kernel::version();
    }

    public static function getUpdateItems(): array
    {
        $items = [];

        // comprobamos si se puede actualizar el core
        if (SolwedGitHub::canUpdateCore()) {
            $item = self::getUpdateItemsCore();
            if (!empty($item)) {
                $items[] = $item;
            }
        }

        // comprobamos si se puede actualizar algún plugin
        foreach (Plugins::list() as $plugin) {
            $item = self::getUpdateItemsPlugin($plugin);
            if (!empty($item)) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * @param Response $response
     * @param User $user
     * @param ControllerPermissions $permissions
     */
    public function privateCore(&$response, $user, $permissions)
    {
        parent::privateCore($response, $user, $permissions);

        $this->telemetryManager = \FacturaScripts\Core\Telemetry::init();

        // Folders writable?
        $folders = $this->notWritableFolders();
        if ($folders) {
            Tools::log()->warning('folders-not-writable', [
                '%folders%' => implode(', ', $folders)
            ]);
            return;
        }

        $action = $this->request->get('action', '');
        $this->execAction($action);
    }

    /**
     * Remove downloaded file.
     */
    private function cancelAction(): void
    {
        $fileName = 'update-' . $this->request->get('item', '') . '.zip';
        if (file_exists(Tools::folder($fileName))) {
            unlink(Tools::folder($fileName));
            Tools::log()->notice('record-deleted-correctly');
        }

        Tools::log()->notice('reloading');
        $this->redirect($this->getClassName() . '?action=post-update', 3);
    }

    private function disableBetaUpdatesAction(): void
    {
        if (false === $this->validateFormToken()) {
            return;
        }

        Tools::settingsSet('default', 'enableupdatesbeta', false);
        Tools::settingsSave();

        Tools::log()->notice('record-updated-correctly');
    }

    /**
     * Download selected update.
     */
    private function downloadAction(): void
    {
        $idItem = $this->request->get('item', '');
        $this->updaterItems = self::getUpdateItems();
        foreach ($this->updaterItems as $key => $item) {
            if ($item['id'] != $idItem) {
                continue;
            }

            if (file_exists(Tools::folder($item['filename']))) {
                unlink(Tools::folder($item['filename']));
            }

            $http = Http::get($item['url'])
                ->setHeader('User-Agent', 'FacturaScripts-SolWed/' . Kernel::version());
            if ($http->saveAs(Tools::folder($item['filename']))) {
                Tools::log()->notice('download-completed');
                $this->updaterItems[$key]['downloaded'] = true;
                break;
            }

            Tools::log()->error('download-error', [
                '%body%' => $http->body(),
                '%error%' => $http->errorMessage(),
                '%status%' => $http->status(),
            ]);
        }

        // ¿Hay que desactivar algo?
        $disable = $this->request->get('disable', '');
        foreach (explode(',', $disable) as $plugin) {
            Plugins::disable($plugin);
        }
    }

    protected function execAction(string $action): void
    {
        switch ($action) {
            case 'cancel':
                $this->cancelAction();
                return;

            case 'disable-beta':
                $this->disableBetaUpdatesAction();
                return;

            case 'download':
                $this->downloadAction();
                return;

            case 'post-update':
                $this->postUpdateAction();
                break;

            case 'update':
                $this->updateAction();
                return;
        }

        $this->updaterItems = self::getUpdateItems();
        $this->setCoreWarnings();
    }

    private static function getUpdateItemsCore(): array
    {
        $build = SolwedGitHub::getCoreBuild();
        if (empty($build) || $build['version'] <= self::getCoreVersion()) {
            return [];
        }

        $fileName = 'update-' . self::SOLWED_CORE_ITEM_ID . '.zip';
        $item = [
            'description' => Tools::trans('core-update', ['%version%' => $build['version']]),
            'downloaded'  => file_exists(Tools::folder($fileName)),
            'filename'    => $fileName,
            'id'          => self::SOLWED_CORE_ITEM_ID,
            'name'        => 'CORE',
            'stable'      => $build['stable'],
            'url'         => $build['url'],
            'version'     => $build['version'],
            'mincore'     => 0,
            'maxcore'     => 0,
        ];

        if ($build['stable']) {
            return $item;
        }

        if ($build['beta'] && Tools::settings('default', 'enableupdatesbeta', false)) {
            return $item;
        }

        return [];
    }

    private static function getUpdateItemsPlugin(Plugin $plugin): array
    {
        // 1. GitHub Releases — si tiene github= en el ini y hay versión más reciente
        if (!empty(SolwedGitHub::getPluginRepo($plugin))) {
            $item = self::getUpdateItemsPluginGitHub($plugin);
            if (!empty($item)) {
                return $item;
            }
        }

        // 2. demo.erpsolwed.es — aunque el plugin tenga github= puede estar más actualizado en demo
        $demoItem = self::getUpdateItemsPluginDemo($plugin);
        if (!empty($demoItem)) {
            return $demoItem;
        }

        // 3. Forja upstream (fallback para plugins sin fuente SolWed)
        return self::getUpdateItemsPluginForja($plugin);
    }

    private static function getUpdateItemsPluginDemo(Plugin $plugin): array
    {
        $demoMap = SolwedDemoPlugins::getPluginMap();
        if (!isset($demoMap[$plugin->name])) {
            return [];
        }

        $demoPlugin = $demoMap[$plugin->name];
        $demoVersion = (float)($demoPlugin['version'] ?? 0);
        if ($demoVersion <= (float)$plugin->version) {
            return [];
        }

        $fileName = 'update-' . $plugin->name . '.zip';
        $minCore = (float)($demoPlugin['min_version'] ?? 0);

        if ($minCore > self::getCoreVersion()) {
            return [];
        }

        return [
            'description' => Tools::trans('plugin-update', [
                '%pluginName%' => $plugin->name,
                '%version%'    => $demoVersion,
            ]),
            'downloaded' => file_exists(Tools::folder($fileName)),
            'filename'   => $fileName,
            'id'         => $plugin->name,
            'name'       => $plugin->name,
            'stable'     => true,
            'url'        => $demoPlugin['download_url'],
            'version'    => $demoVersion,
            'mincore'    => $minCore,
            'maxcore'    => 0,
        ];
    }

    private static function getUpdateItemsPluginGitHub(Plugin $plugin): array
    {
        $build = SolwedGitHub::getPluginBuild($plugin);
        if (empty($build) || $build['version'] <= $plugin->version) {
            return [];
        }

        $fileName = 'update-' . $plugin->name . '.zip';
        $item = [
            'description' => Tools::trans('plugin-update', [
                '%pluginName%' => $plugin->name,
                '%version%' => $build['version']
            ]),
            'downloaded' => file_exists(Tools::folder($fileName)),
            'filename'   => $fileName,
            'id'         => $plugin->name,
            'name'       => $plugin->name,
            'stable'     => $build['stable'],
            'url'        => $build['url'],
            'version'    => $build['version'],
            'mincore'    => 0,
            'maxcore'    => 0,
        ];

        if ($build['stable']) {
            return $item;
        }

        if ($build['beta'] && Tools::settings('default', 'enableupdatesbeta', false)) {
            return $item;
        }

        return [];
    }

    private static function getUpdateItemsPluginForja(Plugin $plugin): array
    {
        $id = $plugin->forja('idplugin', 0);
        if ($id <= 0) {
            return [];
        }

        $fileName = 'update-' . $id . '.zip';
        $coreVersion = self::getCoreVersion();

        foreach (Forja::getBuilds($id) as $build) {
            if ($build['version'] <= $plugin->version) {
                continue;
            }

            if ($build['mincore'] > $coreVersion) {
                continue;
            }

            $item = [
                'description' => Tools::trans('plugin-update', [
                    '%pluginName%' => $plugin->name,
                    '%version%' => $build['version']
                ]),
                'downloaded' => file_exists(Tools::folder($fileName)),
                'filename'   => $fileName,
                'id'         => $id,
                'name'       => $plugin->name,
                'stable'     => $build['stable'],
                'url'        => Forja::BUILDS_URL . '/' . $id . '/' . $build['version'],
                'version'    => $build['version'],
                'mincore'    => $build['mincore'],
                'maxcore'    => $build['maxcore'],
            ];

            if ($build['stable']) {
                return $item;
            }

            if ($build['beta'] && Tools::settings('default', 'enableupdatesbeta', false)) {
                return $item;
            }
        }

        return [];
    }

    private function notWritableFolders(): array
    {
        $notWritable = [];

        $foldersToCheck = ['Core', 'Dinamic', 'MyFiles', 'Plugins', 'vendor'];

        foreach ($foldersToCheck as $folderName) {
            $folderPath = Tools::folder($folderName);

            // Si no es un directorio, pasamos al siguiente
            if (!is_dir($folderPath)) {
                continue;
            }

            // Verificamos si la carpeta principal es escribible
            if (!is_writable($folderPath)) {
                $notWritable[] = $folderName;
                continue;
            }

            // Verificamos las subcarpetas
            foreach (Tools::folderScan($folderPath, true) as $subFolder) {
                $subFolderPath = Tools::folder($folderName, $subFolder);
                if (is_dir($subFolderPath) && !is_writable($subFolderPath)) {
                    $notWritable[] = $folderName . DIRECTORY_SEPARATOR . $subFolder;
                }
            }
        }

        return $notWritable;
    }

    private function postUpdateAction(): void
    {
        $plugName = $this->request->get('init', '');
        if ($plugName) {
            Plugins::deploy(true, true);
            return;
        }

        Migrations::run();
        Plugins::deploy(true, true);
    }

    private function setCoreWarnings(): void
    {
        // Los plugins SolWed no están en la Forja → sin warnings de compatibilidad
    }

    /**
     * Extract zip file and update all files.
     */
    private function updateAction(): void
    {
        $idItem = $this->request->get('item', '');
        $fileName = 'update-' . $idItem . '.zip';

        // open the zip file
        $zip = new ZipArchive();
        $zipStatus = $zip->open(Tools::folder($fileName), ZipArchive::CHECKCONS);
        if ($zipStatus !== true) {
            Tools::log()->critical('ZIP ERROR: ' . $zipStatus);
            return;
        }

        // get the name of the plugin to init after update (if the plugin is enabled)
        // and collect info for the MindClient event
        $init = '';
        $updateItem = [];
        foreach (self::getUpdateItems() as $item) {
            if ($idItem == self::SOLWED_CORE_ITEM_ID) {
                break;
            }

            if ($item['id'] == $idItem) {
                $updateItem = $item;
                if (Plugins::isEnabled($item['name'])) {
                    $init = $item['name'];
                }
                break;
            }
        }

        // capture old version before overwriting
        $oldVersion = null;
        if (!empty($updateItem)) {
            foreach (Plugins::list() as $plugin) {
                if ($plugin->name === $updateItem['name']) {
                    $oldVersion = (float)$plugin->version;
                    break;
                }
            }
        }

        // extract core/plugin zip file
        $done = ($idItem == self::SOLWED_CORE_ITEM_ID) ?
            $this->updateCore($zip, $fileName) :
            $this->updatePlugin($zip, $fileName);

        if ($done) {
            // emit event for plugin updates (not for core)
            if (!empty($updateItem) && $idItem !== self::SOLWED_CORE_ITEM_ID) {
                MindClient::emit('plugin.updated', [
                    'name' => $updateItem['name'],
                    'version' => $updateItem['version'],
                    'old_version' => $oldVersion,
                    'source' => 'updater',
                ]);
            }

            Plugins::deploy(true, false);
            Cache::clear();
            $this->setTemplate(false);
            $this->redirect($this->getClassName() . '?action=post-update&init=' . $init, 3);
        }
    }

    private function updateCore(ZipArchive $zip, string $fileName): bool
    {
        // extract zip content
        if (false === $zip->extractTo(FS_FOLDER)) {
            Tools::log()->critical('update-zip-extract-error', ['%file%' => $fileName]);
            $zip->close();
            return false;
        }

        // remove zip file
        $zip->close();
        unlink(Tools::folder($fileName));

        // update folders
        foreach (['Core', 'node_modules', 'vendor'] as $folder) {
            $origin = Tools::folder(self::CORE_ZIP_FOLDER, $folder);
            $dest = Tools::folder($folder);
            if (false === file_exists($origin)) {
                Tools::log()->critical('update-folder-not-found', ['%folder%' => $folder]);
                Tools::folderDelete(Tools::folder(self::CORE_ZIP_FOLDER));
                return false;
            }

            Tools::folderDelete($dest);
            if (false === Tools::folderCopy($origin, $dest)) {
                Tools::log()->critical('update-folder-copy-error', ['%folder%' => $folder]);
                Tools::folderDelete(Tools::folder(self::CORE_ZIP_FOLDER));
                return false;
            }
        }

        // update index.php
        $origin = Tools::folder(self::CORE_ZIP_FOLDER, 'index.php');
        if (file_exists($origin)) {
            copy($origin, Tools::folder('index.php'));
        }

        // remove zip folder
        Tools::folderDelete(Tools::folder(self::CORE_ZIP_FOLDER));
        return true;
    }

    private function updatePlugin(ZipArchive $zip, string $fileName): bool
    {
        $zip->close();

        // use plugin manager to update
        $return = Plugins::add($fileName, 'plugin.zip', true);

        // remove zip file
        unlink(Tools::folder($fileName));
        return $return;
    }
}
