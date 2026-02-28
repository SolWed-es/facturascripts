<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2024-2025
 *
 * Override de la verificación de compatibilidad de plugins
 * Permite plugins con min_version >= 2024 en lugar de >= 2025
 */

namespace FacturaScripts\Plugins\SolwedPlugins\Lib;

use ReflectionClass;

/**
 * Sobreescribe el método checkCompatibility de la clase Plugin del Core
 * para permitir plugins con min_version >= 2024
 */
class PluginCompatibilityOverride
{
    /**
     * Aplica el override a una instancia de Plugin
     * Permite plugins con min_version >= 2024
     *
     * @param object $plugin - Instancia de FacturaScripts\Core\Internal\Plugin
     * @return bool - true si el plugin es compatible
     */
    public static function applyOverride($plugin): bool
    {
        try {
            $reflection = new ReflectionClass($plugin);

            // Acceder a propiedades privadas
            $minVersionProp = $reflection->getProperty('min_version');
            $minVersionProp->setAccessible(true);
            $minVersion = $minVersionProp->getValue($plugin);

            $minPhpProp = $reflection->getProperty('min_php');
            $minPhpProp->setAccessible(true);
            $minPhp = $minPhpProp->getValue($plugin);

            $nameProp = $reflection->getProperty('name');
            $nameProp->setAccessible(true);
            $name = $nameProp->getValue($plugin);

            // Realizar validación personalizada
            return self::validatePluginCompatibility($minVersion, $minPhp, $name, $plugin, $reflection);
        } catch (\Exception $e) {
            return true;
        }
    }

    /**
     * Valida la compatibilidad con reglas personalizadas
     */
    private static function validatePluginCompatibility($minVersion, $minPhp, $name, $plugin, $reflection): bool
    {
        // Validar versión de PHP
        if (version_compare(PHP_VERSION, (string)$minPhp, '<')) {
            return self::setPluginIncompatible($plugin, $reflection, false);
        }

        // Validar versión mínima de FS requerida
        $fsVersion = self::getFacturaScriptsVersion();
        if ($fsVersion < $minVersion) {
            return self::setPluginIncompatible($plugin, $reflection, false);
        }

        // OVERRIDE: Permitir min_version >= 2024 (no 2025)
        if ($minVersion < 2024) {
            return self::setPluginIncompatible($plugin, $reflection, false);
        }

        // Plugin es compatible
        self::setPluginCompatible($plugin, $reflection);
        return true;
    }

    /**
     * Marca un plugin como compatible
     */
    private static function setPluginCompatible($plugin, $reflection): void
    {
        $compatibleProp = $reflection->getProperty('compatible');
        $compatibleProp->setAccessible(true);
        $compatibleProp->setValue($plugin, true);

        $descriptionProp = $reflection->getProperty('compatibilityDescription');
        $descriptionProp->setAccessible(true);
        $descriptionProp->setValue($plugin, '');
    }

    /**
     * Marca un plugin como incompatible
     */
    private static function setPluginIncompatible($plugin, $reflection, $setDescription = true): bool
    {
        $compatibleProp = $reflection->getProperty('compatible');
        $compatibleProp->setAccessible(true);
        $compatibleProp->setValue($plugin, false);

        if ($setDescription) {
            $descriptionProp = $reflection->getProperty('compatibilityDescription');
            $descriptionProp->setAccessible(true);
            $descriptionProp->setValue($plugin, '');
        }

        return false;
    }

    /**
     * Obtiene la versión de FacturaScripts
     */
    private static function getFacturaScriptsVersion(): float
    {
        if (class_exists('FacturaScripts\Core\Kernel')) {
            return \FacturaScripts\Core\Kernel::version();
        }
        return 2025.43;
    }
}
