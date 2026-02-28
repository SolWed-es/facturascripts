<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2024-2025
 *
 * Validador centralizado de compatibilidad de plugins
 */

namespace FacturaScripts\Plugins\SolwedPlugins\Lib;

use FacturaScripts\Core\Tools;

/**
 * Clase utilitaria para validar compatibilidad de plugins
 * Centraliza toda la lógica de validación en un solo lugar
 *
 * @package FacturaScripts\Plugins\SolwedPlugins\Lib
 */
class PluginValidator
{
    /**
     * Valida la versión de PHP requerida
     *
     * @param string $minPhp Versión mínima de PHP requerida
     * @param string $pluginName Nombre del plugin
     * @return bool True si la versión de PHP es compatible
     */
    public static function validatePhpVersion(string $minPhp, string $pluginName = ''): bool
    {
        if (empty($minPhp)) {
            return true;
        }

        if (version_compare(PHP_VERSION, $minPhp, '<')) {
            if (!empty($pluginName)) {
                Tools::log()->warning('plugin-incompatible-php', [
                    '%plugin%' => $pluginName,
                    '%required%' => $minPhp,
                    '%current%' => PHP_VERSION
                ]);
            }
            return false;
        }

        return true;
    }

    /**
     * Valida la versión de FacturaScript requerida
     *
     * @param float $minVersion Versión mínima de FacturaScript requerida
     * @param float $currentVersion Versión actual de FacturaScript
     * @param string $pluginName Nombre del plugin
     * @return bool True si la versión de FS es compatible
     */
    public static function validateFacturaScriptVersion(float $minVersion, float $currentVersion, string $pluginName = ''): bool
    {
        if ($minVersion <= 0) {
            return true;
        }

        if ($minVersion > $currentVersion) {
            if (!empty($pluginName)) {
                Tools::log()->warning('plugin-incompatible-fs-version', [
                    '%plugin%' => $pluginName,
                    '%required%' => $minVersion,
                    '%current%' => $currentVersion
                ]);
            }
            return false;
        }

        return true;
    }

    /**
     * Obtiene mensaje de compatibilidad basado en validaciones
     *
     * @param float $minVersion Versión mínima de FS requerida
     * @param float $currentFsVersion Versión actual de FS
     * @param string $minPhp Versión mínima de PHP requerida
     * @return string Mensaje de incompatibilidad (vacío si es compatible)
     */
    public static function getCompatibilityMessage(float $minVersion, float $currentFsVersion, string $minPhp = ''): string
    {
        $messages = [];

        // Validar versión de FS
        if ($minVersion > 0 && $minVersion > $currentFsVersion) {
            $messages[] = 'Requiere FacturaScripts ' . $minVersion . ' o superior (actual: ' . $currentFsVersion . ')';
        }

        // Validar versión de PHP
        if (!empty($minPhp) && version_compare(PHP_VERSION, $minPhp, '<')) {
            $messages[] = 'Requiere PHP ' . $minPhp . ' o superior (actual: ' . PHP_VERSION . ')';
        }

        return implode(' | ', $messages);
    }

    /**
     * Valida completamente un plugin y retorna información de compatibilidad
     *
     * @param array $plugin Array con datos del plugin
     * @param float $currentFsVersion Versión actual de FacturaScript
     * @return array Array con keys: 'compatible' (bool), 'message' (string)
     */
    public static function validatePlugin(array $plugin, float $currentFsVersion): array
    {
        $minVersion = isset($plugin['min_version']) ? (float)$plugin['min_version'] : 0;
        $minPhp = isset($plugin['min_php']) ? (string)$plugin['min_php'] : '';

        $fsCompatible = self::validateFacturaScriptVersion($minVersion, $currentFsVersion);
        $phpCompatible = self::validatePhpVersion($minPhp);

        return [
            'compatible' => $fsCompatible && $phpCompatible,
            'message' => self::getCompatibilityMessage($minVersion, $currentFsVersion, $minPhp)
        ];
    }

    /**
     * Verifica si un plugin está instalado y si hay actualización disponible
     *
     * @param string $pluginName Nombre del plugin
     * @param string $availableVersion Versión disponible del plugin
     * @param array $installedPlugins Array de plugins instalados O mapa indexado
     * @return array Array con keys: 'installed' (bool), 'update_available' (bool)
     */
    public static function checkInstallationStatus(string $pluginName, string $availableVersion, array $installedPlugins): array
    {
        // Si $installedPlugins es un mapa indexado (primer nivel es string keys), usar búsqueda O(1)
        if (isset($installedPlugins[$pluginName])) {
            $installed = $installedPlugins[$pluginName];
            return [
                'installed' => true,
                'update_available' => version_compare($installed->version, $availableVersion, '<')
            ];
        }

        // Fallback: búsqueda lineal O(n) para compatibilidad con array de objetos
        foreach ($installedPlugins as $installed) {
            if (is_object($installed) && $installed->name === $pluginName) {
                return [
                    'installed' => true,
                    'update_available' => version_compare($installed->version, $availableVersion, '<')
                ];
            }
        }

        return [
            'installed' => false,
            'update_available' => false
        ];
    }

    /**
     * Crea un mapa indexado de plugins instalados para búsquedas O(1)
     *
     * @param array $installedPlugins Array de plugins instalados
     * @return array Mapa indexado por nombre del plugin
     */
    public static function createInstalledPluginsMap(array $installedPlugins): array
    {
        $map = [];
        foreach ($installedPlugins as $plugin) {
            if (is_object($plugin) && isset($plugin->name)) {
                $map[$plugin->name] = $plugin;
            }
        }
        return $map;
    }
}
