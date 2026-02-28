<?php
namespace FacturaScripts\Plugins\Blog\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Plugins\Blog\Model\BlogTag;
use FacturaScripts\Plugins\Blog\Lib\BlogApiResponse;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enhanced Blog Tags API Controller
 *
 * @author Your Name
 */
class ApiBlogTags extends Controller
{
    public function publicCore(&$response): void
    {
        parent::publicCore($response);

        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Access-Control-Allow-Origin', '*');

        try {
            $this->processRequest($response);
        } catch (\Exception $e) {
            $this->sendError($response, $e->getMessage(), Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function processRequest(Response &$response): void
    {
        $urlSegments = explode('/', trim($this->request->getPathInfo(), '/'));
        $resourceId = $urlSegments[count($urlSegments) - 1] ?? null;
        $isSingleResource = !empty($resourceId) && $resourceId !== 'tags';

        switch ($this->request->getMethod()) {
            case 'GET':
                $isSingleResource ? $this->getSingle($response, $resourceId) : $this->getCollection($response);
                break;
            case 'POST':
                $this->create($response);
                break;
            case 'PUT':
            case 'PATCH':
                $isSingleResource ? $this->update($response, $resourceId) : $this->sendError($response, 'ID required', Response::HTTP_BAD_REQUEST);
                break;
            case 'DELETE':
                $isSingleResource ? $this->delete($response, $resourceId) : $this->sendError($response, 'ID required', Response::HTTP_BAD_REQUEST);
                break;
            default:
                $this->sendError($response, 'Method not allowed', Response::HTTP_METHOD_NOT_ALLOWED);
        }
    }

    private function getCollection(Response &$response): void
    {
        $model = new BlogTag();
        $search = $this->request->query->get('search', '');
        $where = [];

        if ($search) {
            $where[] = new DataBaseWhere('name', "%{$search}%", 'LIKE', 'OR');
            $where[] = new DataBaseWhere('slug', "%{$search}%", 'LIKE', 'OR');
        }

        $tags = $model->all($where, ['name' => 'ASC']);
        $data = array_map(fn($tag) => BlogApiResponse::formatTag($tag), $tags);

        $response->setContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $response->setStatusCode(Response::HTTP_OK);
    }

    private function getSingle(Response &$response, string $identifier): void
    {
        $tag = is_numeric($identifier) ? $this->loadById((int) $identifier) : $this->loadBySlug($identifier);

        if (!$tag) {
            $this->sendError($response, 'Tag not found', Response::HTTP_NOT_FOUND);
            return;
        }

        $data = BlogApiResponse::formatTag($tag);
        $response->setContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $response->setStatusCode(Response::HTTP_OK);
    }

    private function create(Response &$response): void
    {
        $data = json_decode($this->request->getContent(), true);

        if (empty($data) || empty($data['name'])) {
            $this->sendError($response, 'Name is required', Response::HTTP_BAD_REQUEST);
            return;
        }

        $tag = new BlogTag();
        if (isset($data['name'])) $tag->name = $data['name'];
        if (isset($data['slug'])) $tag->slug = $data['slug'];

        if (!$tag->save()) {
            $this->sendError($response, 'Failed to create tag', Response::HTTP_INTERNAL_SERVER_ERROR);
            return;
        }

        $responseData = BlogApiResponse::formatTag($tag);
        $response->setContent(json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $response->setStatusCode(Response::HTTP_CREATED);
    }

    private function update(Response &$response, string $identifier): void
    {
        $tag = is_numeric($identifier) ? $this->loadById((int) $identifier) : $this->loadBySlug($identifier);

        if (!$tag) {
            $this->sendError($response, 'Tag not found', Response::HTTP_NOT_FOUND);
            return;
        }

        $data = json_decode($this->request->getContent(), true);

        if (isset($data['name'])) $tag->name = $data['name'];
        if (isset($data['slug'])) $tag->slug = $data['slug'];

        if (!$tag->save()) {
            $this->sendError($response, 'Failed to update tag', Response::HTTP_INTERNAL_SERVER_ERROR);
            return;
        }

        $responseData = BlogApiResponse::formatTag($tag);
        $response->setContent(json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $response->setStatusCode(Response::HTTP_OK);
    }

    private function delete(Response &$response, string $identifier): void
    {
        $tag = is_numeric($identifier) ? $this->loadById((int) $identifier) : $this->loadBySlug($identifier);

        if (!$tag) {
            $this->sendError($response, 'Tag not found', Response::HTTP_NOT_FOUND);
            return;
        }

        if (!$tag->delete()) {
            $this->sendError($response, 'Failed to delete tag', Response::HTTP_INTERNAL_SERVER_ERROR);
            return;
        }

        $response->setContent(json_encode(['deleted' => true]));
        $response->setStatusCode(Response::HTTP_OK);
    }

    private function loadById(int $id): ?BlogTag
    {
        $tag = new BlogTag();
        return $tag->loadFromCode($id) ? $tag : null;
    }

    private function loadBySlug(string $slug): ?BlogTag
    {
        $tag = new BlogTag();
        $where = [new DataBaseWhere('slug', $slug)];
        $tags = $tag->all($where, [], 0, 1);
        return empty($tags) ? null : $tags[0];
    }

    private function sendError(Response &$response, string $message, int $statusCode): void
    {
        $error = ['code' => 'rest_tag_error', 'message' => $message, 'data' => ['status' => $statusCode]];
        $response->setContent(json_encode($error, JSON_PRETTY_PRINT));
        $response->setStatusCode($statusCode);
    }
}
