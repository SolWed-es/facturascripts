<?php
/**
 * Plugin SolwedES - API Controller for Products with Images
 * Returns products with their associated image URLs for the portal
 *
 * Endpoint: /api/3/productos-con-imagenes
 *
 * @author    Solwed Desarrollo
 * @copyright 2025 Solwed
 */

namespace FacturaScripts\Plugins\SolwedES\Controller;

use FacturaScripts\Core\Template\ApiController;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Where;
use FacturaScripts\Dinamic\Lib\MyFilesToken;
use FacturaScripts\Dinamic\Model\Producto;

/**
 * API Controller for products with images
 */
class ApiProductosConImagenes extends ApiController
{
    protected function runResource(): void
    {
        $limit = (int) $this->request->get('limit', 2000);
        $offset = (int) $this->request->get('offset', 0);
        $busqueda = $this->request->get('busqueda', '');
        $id = (int) $this->request->get('id', 0);

        // If ID is provided, return single product
        if ($id > 0) {
            $this->getProductById($id);
            return;
        }

        // Otherwise return list
        $this->listProducts($limit, $offset, $busqueda);
    }

    /**
     * Get single product by ID
     */
    private function getProductById(int $id): void
    {
        $product = new Producto();
        if (!$product->loadFromCode($id)) {
            $this->response->setStatusCode(404);
            $this->response->setContent(json_encode(['error' => 'Producto no encontrado']));
            return;
        }

        $this->response->setContent(json_encode($this->formatProduct($product)));
    }

    /**
     * List products with images
     */
    private function listProducts(int $limit, int $offset, string $busqueda): void
    {
        $where = [
            Where::column('publico', true),
            Where::column('bloqueado', false),
            Where::column('sevende', true),
        ];

        if (!empty($busqueda)) {
            $where[] = Where::column('referencia|descripcion', '%' . $busqueda . '%', 'LIKE');
        }

        $products = Producto::all($where, ['referencia' => 'ASC'], $offset, $limit);
        $result = [];

        foreach ($products as $product) {
            $result[] = $this->formatProduct($product);
        }

        $this->response->setContent(json_encode($result));
    }

    /**
     * Format product data with images
     */
    private function formatProduct(Producto $product): array
    {
        $images = $this->getProductImages($product);

        return [
            'idproducto' => $product->idproducto,
            'referencia' => $product->referencia,
            'descripcion' => $product->descripcion,
            'precio' => $product->precio,
            'codfamilia' => $product->codfamilia,
            'codfabricante' => $product->codfabricante,
            'stockfis' => $product->stockfis,
            'nostock' => $product->nostock,
            'publico' => $product->publico,
            'bloqueado' => $product->bloqueado,
            'observaciones' => $product->observaciones,
            'imagenes' => $images,
            'imagen_principal' => !empty($images) ? $images[0] : null,
        ];
    }

    /**
     * Get product images from AttachedFileRelation
     */
    private function getProductImages(Producto $product): array
    {
        $images = [];
        $baseUrl = rtrim(Tools::settings('default', 'site_url', ''), '/');

        // Use the built-in getImages method from Producto model
        $attachedImages = $product->getImages();

        foreach ($attachedImages as $image) {
            // Get thumbnail (500x500)
            $thumbnailPath = $image->getThumbnail(500, 500);
            if (empty($thumbnailPath)) {
                continue;
            }

            // Get full image URL
            $file = $image->getFile();
            if (empty($file)) {
                continue;
            }

            $downloadPath = $file->url('download');
            if (empty($downloadPath)) {
                continue;
            }

            // Generate secure token for file access
            $token = MyFilesToken::get($thumbnailPath, false);
            $tokenFull = MyFilesToken::get($downloadPath, false);

            $images[] = [
                'thumbnail' => $baseUrl . '/' . ltrim($thumbnailPath, '/') . '?myft=' . $token,
                'full' => $baseUrl . '/' . ltrim($downloadPath, '/') . '?myft=' . $tokenFull,
                'filename' => $file->filename,
                'referencia' => $image->referencia ?? null,
            ];
        }

        return $images;
    }
}
