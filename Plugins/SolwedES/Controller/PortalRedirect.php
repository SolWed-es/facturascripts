<?php

/**
 * Plugin SolwedES — Redirección del portal PHP de FS a app.solwed.es
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Contract\ControllerInterface;
use FacturaScripts\Core\Tools;

/**
 * Redirige todas las rutas del PortalCliente PHP al portal Next.js (app.solwed.es).
 *
 * Rutas interceptadas (registradas en Init.php):
 *   /PortalCliente, /PortalLogin, /PortalAlbaran, /PortalFactura,
 *   /PortalPedido, /PortalPresupuesto, /PortalTicket, /PortalNote
 *
 * La URL de destino se lee de AppSettings (grupo 'solwed', clave 'portal_url').
 * Si no está configurada, usa https://app.solwed.es por defecto.
 */
class PortalRedirect implements ControllerInterface
{
    // path: ruta destino en app.solwed.es
    // id_param: nombre del parámetro de ID que FS pasa en la query string (null = sin ID)
    private const ROUTE_MAP = [
        'PortalLogin'       => ['path' => '/login',                    'id_param' => null],
        'PortalCliente'     => ['path' => '',                          'id_param' => null],
        'PortalFactura'     => ['path' => '/billing?tab=facturas',     'id_param' => 'idfactura'],
        'PortalPresupuesto' => ['path' => '/billing?tab=presupuestos', 'id_param' => 'idpresupuesto'],
        'PortalPedido'      => ['path' => '/billing?tab=pedidos',      'id_param' => 'idpedido'],
        'PortalAlbaran'     => ['path' => '/billing?tab=albaranes',    'id_param' => 'idalbaran'],
        'PortalTicket'      => ['path' => '/support',                  'id_param' => null],
        'PortalNote'        => ['path' => '/support',                  'id_param' => null],
    ];

    private string $url;

    public function __construct(string $className, string $url = '')
    {
        $this->url = $url;
    }

    public function getPageData(): array
    {
        return [];
    }

    public function run(): void
    {
        $controllerName = ltrim(parse_url($this->url, PHP_URL_PATH), '/');

        $destination = $this->buildDestinationUrl($controllerName, $_GET);

        header('Location: ' . $destination, true, 302);
        exit;
    }

    /**
     * Construye la URL de destino en el portal Next.js.
     * Método público para facilitar los tests unitarios.
     *
     * @param string $controllerName  Nombre del controlador FS (ej. "PortalFactura")
     * @param array  $queryParams     Parámetros de query string (ej. ['idfactura' => 42])
     */
    public function buildDestinationUrl(string $controllerName, array $queryParams = []): string
    {
        $base = rtrim(Tools::settings('solwed', 'portal_url', 'https://app.solwed.es'), '/');
        $route = self::ROUTE_MAP[$controllerName] ?? ['path' => '', 'id_param' => null];

        $destination = $base . $route['path'];

        // Añadir &{id_param}=X manteniendo el nombre nativo de FS (ej. idfactura=42)
        if ($route['id_param'] !== null) {
            $id = filter_var($queryParams[$route['id_param']] ?? null, FILTER_VALIDATE_INT);
            if ($id !== false && $id !== null) {
                $separator = str_contains($destination, '?') ? '&' : '?';
                $destination .= $separator . $route['id_param'] . '=' . $id;
            }
        }

        return $destination;
    }
}
