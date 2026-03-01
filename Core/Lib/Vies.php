<?php
/**
 * This file is part of FacturaScripts
 * Copyright (C) 2023-2024 Carlos Garcia Gomez <carlos@facturascripts.com>
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

namespace FacturaScripts\Core\Lib;

use Exception;
use FacturaScripts\Core\Cache;
use FacturaScripts\Core\Http;
use FacturaScripts\Core\Tools;
use SoapClient;

/**
 * @author Carlos Garcia Gomez      <carlos@facturascripts.com>
 * @author Daniel Fernández Giménez <hola@danielfg.es>
 */
class Vies
{
    const EU_COUNTRIES = [
        'AT', 'BE', 'BG', 'CY', 'CZ', 'DE', 'DK', 'EE', 'EL', 'ES', 'FI', 'FR', 'HR', 'HU', 'IE', 'IT', 'LT', 'LU',
        'LV', 'MT', 'NL', 'PL', 'PT', 'RO', 'SE', 'SI', 'SK',
    ];

    const VIES_URL = "https://ec.europa.eu/taxation_customs/vies/checkVatService.wsdl";

    // Resultados posibles de la validación
    const RESULT_VALID = 1;
    const RESULT_INVALID = 0;
    const RESULT_ERROR = -1;

    private static $lastError = '';
    private static $simulatedResponse = null;

    /**
     * Simula una respuesta del servicio VIES para usar en pruebas unitarias.
     * Este método solo debe utilizarse en el contexto de pruebas.
     *
     * @param int|null $response Vies::RESULT_VALID para simular un número de IVA válido,
     *                           Vies::RESULT_INVALID para simular un número de IVA inválido,
     *                           Vies::RESULT_ERROR para simular un error,
     *                           null para volver al comportamiento normal
     */
    public static function simulateViesResponse(?int $response): void
    {
        self::$simulatedResponse = $response;
    }

    public static function check(string $cifnif, string $codiso, bool $msg = true): int
    {
        // Si hay una respuesta simulada, la devolvemos inmediatamente
        if (self::$simulatedResponse !== null) {
            return self::$simulatedResponse;
        }

        // si la extensión soap no está instalada, usamos el mind API como alternativa
        if (false === extension_loaded('soap')) {
            return self::checkViaMind($cifnif, $codiso, $msg);
        }

        // si el país no es de la unión europea, devolvemos error
        if (!in_array($codiso, self::EU_COUNTRIES)) {
            static::setMessage($msg, 'country-not-in-eu', ['%codiso%' => $codiso]);
            return self::RESULT_ERROR;
        }

        // quitamos caracteres especiales del cifnif
        $cifnif = str_replace(['_', '-', '.', ',', '?', '¿', ' ', '/', '\\'], '', strtoupper(trim($cifnif)));

        // si el cifnif tiene menos de 5 caracteres, devolvemos error
        if (strlen($cifnif) < 5) {
            static::setMessage($msg, 'vat-number-is-short', ['%vat-number%' => $cifnif]);
            return self::RESULT_ERROR;
        }

        // si codiso está vacío o es diferente de 2 caracteres, devolvemos error
        if (empty($codiso) || strlen($codiso) !== 2) {
            static::setMessage($msg, 'invalid-iso-code', ['%iso-code%' => $codiso]);
            return self::RESULT_ERROR;
        }

        // si existe el codiso al principio del cifnif, lo quitamos
        if (substr($cifnif, 0, 2) === $codiso) {
            $cifnif = substr($cifnif, 2);
        }

        return static::getViesInfo($cifnif, $codiso, $msg);
    }

    public static function getLastError(): string
    {
        return self::$lastError;
    }

    private static function checkViaMind(string $cifnif, string $codiso, bool $msg): int
    {
        if (!in_array($codiso, self::EU_COUNTRIES)) {
            static::setMessage($msg, 'country-not-in-eu', ['%codiso%' => $codiso]);
            return self::RESULT_ERROR;
        }

        $cifnif = str_replace(['_', '-', '.', ',', '?', '¿', ' ', '/', '\\'], '', strtoupper(trim($cifnif)));
        if (strlen($cifnif) < 5) {
            static::setMessage($msg, 'vat-number-is-short', ['%vat-number%' => $cifnif]);
            return self::RESULT_ERROR;
        }
        if (substr($cifnif, 0, 2) === $codiso) {
            $cifnif = substr($cifnif, 2);
        }

        $mindApiUrl = Tools::config('mind_api_url', 'https://mind.solwed.es/api/v1');
        $mindApiToken = self::getMindToken($mindApiUrl);
        if (empty($mindApiToken)) {
            static::setMessage($msg, 'vies-service-unavailable');
            return self::RESULT_ERROR;
        }

        $response = Http::post($mindApiUrl . '/tools/consultar_vat_eu/execute')
            ->setHeader('Authorization', 'Bearer ' . $mindApiToken)
            ->setHeader('Content-Type', 'application/json')
            ->setTimeout(10)
            ->setBody(json_encode(['vat_number' => $codiso . $cifnif]));

        if ($response->failed()) {
            static::setMessage($msg, 'error-checking-vat-number', ['%vat-number%' => $cifnif]);
            return self::RESULT_ERROR;
        }

        $data = $response->json();
        if (($data['valid'] ?? false) === true) {
            return self::RESULT_VALID;
        }
        if (($data['valid'] ?? null) === false) {
            static::setMessage($msg, 'vat-number-not-valid', ['%vat-number%' => $cifnif]);
            return self::RESULT_INVALID;
        }

        return self::RESULT_ERROR;
    }

    private static function getMindToken(string $baseUrl): string
    {
        return Cache::remember('mind_api_token', function () use ($baseUrl) {
            $user = Tools::config('mind_api_user', '');
            $pass = Tools::config('mind_api_password', '');
            if (empty($user) || empty($pass)) {
                return '';
            }
            $resp = Http::post($baseUrl . '/auth/login')
                ->setHeader('Content-Type', 'application/json')
                ->setTimeout(5)
                ->setBody(json_encode(['email' => $user, 'password' => $pass]));
            return $resp->json()['token'] ?? '';
        });
    }

    private static function getViesInfo(string $vatNumber, string $codiso, bool $msg): int
    {
        self::$lastError = '';

        try {
            $client = new SoapClient(self::VIES_URL, ['exceptions' => true]);
            $json = json_encode(
                $client->checkVat([
                    'countryCode' => $codiso,
                    'vatNumber' => $vatNumber,
                ])
            );

            $result = json_decode($json, true);
            if (isset($result["valid"]) && $result["valid"]) {
                return self::RESULT_VALID;
            }

            static::setMessage($msg, 'vat-number-not-valid', ['%vat-number%' => $vatNumber]);
            return self::RESULT_INVALID;
        } catch (Exception $ex) {
            Tools::log('VatInfoFinder')->error($ex->getCode() . ' - ' . $ex->getMessage());
            self::$lastError = $ex->getMessage();
            if ($ex->getMessage() == 'INVALID_INPUT') {
                return self::RESULT_INVALID;
            }
        }

        // se ha producido error al comprobar el VAT number con VIES
        static::setMessage($msg, 'error-checking-vat-number', ['%vat-number%' => $vatNumber]);
        return self::RESULT_ERROR;
    }

    private static function setMessage(bool $msg, string $txt, array $context = []): void
    {
        if ($msg) {
            Tools::log()->warning($txt, $context);
        }
    }
}
