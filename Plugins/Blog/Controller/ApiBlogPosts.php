<?php
namespace FacturaScripts\Plugins\Blog\Controller;

use FacturaScripts\Core\Base\Controller;
use FacturaScripts\Plugins\Blog\Model\BlogPost;
use FacturaScripts\Plugins\Blog\Model\BlogPostCategory;
use FacturaScripts\Plugins\Blog\Model\BlogPostTag;
use FacturaScripts\Plugins\Blog\Lib\BlogApiResponse;
use FacturaScripts\Plugins\Blog\Lib\BlogQueryParser;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enhanced Blog Posts API Controller
 * WordPress-inspired REST API with _embed support, unified search, and slug lookup
 *
 * @author Your Name
 */
class ApiBlogPosts extends Controller
{
    public function publicCore(&$response): void
    {
        parent::publicCore($response);

        // Set response headers
        $response->headers->set('Content-Type', 'application/json');
        $response->headers->set('Access-Control-Allow-Origin', '*');
        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Content-Type, Authorization');

        // Handle OPTIONS preflight request
        if ($this->request->getMethod() === 'OPTIONS') {
            $response->setStatusCode(Response::HTTP_OK);
            return;
        }

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

        // Determine if we're accessing a single resource or collection
        $isSingleResource = !empty($resourceId) && $resourceId !== 'posts';

        switch ($this->request->getMethod()) {
            case 'GET':
                if ($isSingleResource) {
                    $this->getSingle($response, $resourceId);
                } else {
                    $this->getCollection($response);
                }
                break;

            case 'POST':
                $this->create($response);
                break;

            case 'PUT':
            case 'PATCH':
                if ($isSingleResource) {
                    $this->update($response, $resourceId);
                } else {
                    $this->sendError($response, 'Resource ID required for update', Response::HTTP_BAD_REQUEST);
                }
                break;

            case 'DELETE':
                if ($isSingleResource) {
                    $this->delete($response, $resourceId);
                } else {
                    $this->sendError($response, 'Resource ID required for delete', Response::HTTP_BAD_REQUEST);
                }
                break;

            default:
                $this->sendError($response, 'Method not allowed', Response::HTTP_METHOD_NOT_ALLOWED);
        }
    }

    private function getCollection(Response &$response): void
    {
        // Parse query parameters
        $params = BlogQueryParser::parse($this->request);

        // Build WHERE conditions
        $where = BlogQueryParser::buildWhere($params);

        // Filter by categories if specified
        if (!empty($params['categories'])) {
            $categoryIds = BlogQueryParser::parseCategoryFilter($params['categories']);
            $where = $this->filterByCategories($where, $categoryIds);
        }

        // Filter by tags if specified
        if (!empty($params['tags'])) {
            $tagIds = BlogQueryParser::parseTagFilter($params['tags']);
            $where = $this->filterByTags($where, $tagIds);
        }

        // Build ORDER clause
        $order = BlogQueryParser::buildOrder($params);

        // Get posts
        $model = new BlogPost();
        $posts = $model->all($where, $order, $params['offset'], $params['limit']);

        // Get total count for pagination headers
        $total = $model->count($where);

        // Format response
        $data = [];
        foreach ($posts as $post) {
            $postData = BlogApiResponse::formatPost($post, $params['_embed']);

            // Filter fields if requested
            if (!empty($params['_fields'])) {
                $postData = BlogQueryParser::filterFields($postData, $params['_fields']);
            }

            $data[] = $postData;
        }

        // Add pagination headers
        $this->addPaginationHeaders($response, $total, $params);

        // Send response
        $response->setContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $response->setStatusCode(Response::HTTP_OK);
    }

    private function getSingle(Response &$response, string $identifier): void
    {
        // Support both ID and slug lookup
        $post = is_numeric($identifier)
            ? $this->loadPostById((int) $identifier)
            : BlogPost::getBySlug($identifier);

        if (!$post) {
            $this->sendError($response, 'Post not found', Response::HTTP_NOT_FOUND);
            return;
        }

        // Check if embed is requested
        $embed = $this->request->query->getBoolean('_embed', false);

        // Format response
        $data = BlogApiResponse::formatPost($post, $embed);

        // Filter fields if requested
        $fields = $this->request->query->get('_fields', '');
        if (!empty($fields)) {
            $data = BlogQueryParser::filterFields($data, $fields);
        }

        $response->setContent(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $response->setStatusCode(Response::HTTP_OK);
    }

    private function create(Response &$response): void
    {
        $data = json_decode($this->request->getContent(), true);

        if (empty($data)) {
            $this->sendError($response, 'Invalid JSON data', Response::HTTP_BAD_REQUEST);
            return;
        }

        $post = new BlogPost();
        $this->fillModelFromData($post, $data);

        if (!$post->save()) {
            $this->sendError($response, 'Failed to create post', Response::HTTP_INTERNAL_SERVER_ERROR);
            return;
        }

        // Handle categories and tags
        if (isset($data['categories'])) {
            $this->updatePostCategories($post, $data['categories']);
        }

        if (isset($data['tags'])) {
            $this->updatePostTags($post, $data['tags']);
        }

        $responseData = BlogApiResponse::formatPost($post, true);
        $response->setContent(json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $response->setStatusCode(Response::HTTP_CREATED);
    }

    private function update(Response &$response, string $identifier): void
    {
        $post = is_numeric($identifier)
            ? $this->loadPostById((int) $identifier)
            : BlogPost::getBySlug($identifier);

        if (!$post) {
            $this->sendError($response, 'Post not found', Response::HTTP_NOT_FOUND);
            return;
        }

        $data = json_decode($this->request->getContent(), true);

        if (empty($data)) {
            $this->sendError($response, 'Invalid JSON data', Response::HTTP_BAD_REQUEST);
            return;
        }

        $this->fillModelFromData($post, $data);

        if (!$post->save()) {
            $this->sendError($response, 'Failed to update post', Response::HTTP_INTERNAL_SERVER_ERROR);
            return;
        }

        // Handle categories and tags
        if (isset($data['categories'])) {
            $this->updatePostCategories($post, $data['categories']);
        }

        if (isset($data['tags'])) {
            $this->updatePostTags($post, $data['tags']);
        }

        $responseData = BlogApiResponse::formatPost($post, true);
        $response->setContent(json_encode($responseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $response->setStatusCode(Response::HTTP_OK);
    }

    private function delete(Response &$response, string $identifier): void
    {
        $post = is_numeric($identifier)
            ? $this->loadPostById((int) $identifier)
            : BlogPost::getBySlug($identifier);

        if (!$post) {
            $this->sendError($response, 'Post not found', Response::HTTP_NOT_FOUND);
            return;
        }

        if (!$post->delete()) {
            $this->sendError($response, 'Failed to delete post', Response::HTTP_INTERNAL_SERVER_ERROR);
            return;
        }

        $response->setContent(json_encode(['deleted' => true, 'previous' => BlogApiResponse::formatPost($post, false)]));
        $response->setStatusCode(Response::HTTP_OK);
    }

    private function loadPostById(int $id): ?BlogPost
    {
        $post = new BlogPost();
        return $post->loadFromCode($id) ? $post : null;
    }

    private function fillModelFromData(BlogPost $post, array $data): void
    {
        if (isset($data['title'])) $post->title = $data['title'];
        if (isset($data['slug'])) $post->slug = $data['slug'];
        if (isset($data['content'])) $post->content = $data['content'];
        if (isset($data['excerpt'])) $post->excerpt = $data['excerpt'];
        if (isset($data['status'])) $post->status = $data['status'];
        if (isset($data['author'])) $post->author = $data['author'];
        if (isset($data['published_at'])) $post->published_at = $data['published_at'];
        if (isset($data['featured_media'])) $post->featured_image = $data['featured_media'];
        if (isset($data['featured_image'])) $post->featured_image = $data['featured_image'];
        if (isset($data['meta']['title'])) $post->meta_title = $data['meta']['title'];
        if (isset($data['meta']['description'])) $post->meta_description = $data['meta']['description'];
        if (isset($data['meta']['keywords'])) $post->meta_keywords = $data['meta']['keywords'];
    }

    private function updatePostCategories(BlogPost $post, array $categoryIds): void
    {
        // Remove existing categories
        $postCategory = new BlogPostCategory();
        $where = [new DataBaseWhere('post_id', $post->id)];
        foreach ($postCategory->all($where) as $relation) {
            $relation->delete();
        }

        // Add new categories
        foreach ($categoryIds as $categoryId) {
            $relation = new BlogPostCategory();
            $relation->post_id = $post->id;
            $relation->category_id = $categoryId;
            $relation->save();
        }
    }

    private function updatePostTags(BlogPost $post, array $tagIds): void
    {
        // Remove existing tags
        $postTag = new BlogPostTag();
        $where = [new DataBaseWhere('post_id', $post->id)];
        foreach ($postTag->all($where) as $relation) {
            $relation->delete();
        }

        // Add new tags
        foreach ($tagIds as $tagId) {
            $relation = new BlogPostTag();
            $relation->post_id = $post->id;
            $relation->tag_id = $tagId;
            $relation->save();
        }
    }

    private function filterByCategories(array $where, array $categoryIds): array
    {
        // Get post IDs that belong to these categories
        $postCategory = new BlogPostCategory();
        $postIds = [];

        foreach ($categoryIds as $categoryId) {
            $relations = $postCategory->all([new DataBaseWhere('category_id', $categoryId)]);
            foreach ($relations as $relation) {
                $postIds[] = $relation->post_id;
            }
        }

        if (empty($postIds)) {
            // No posts found for these categories, return impossible condition
            $where[] = new DataBaseWhere('id', 0);
        } else {
            // Filter by post IDs
            $postIds = array_unique($postIds);
            $where[] = new DataBaseWhere('id', implode(',', $postIds), 'IN');
        }

        return $where;
    }

    private function filterByTags(array $where, array $tagIds): array
    {
        // Get post IDs that have these tags
        $postTag = new BlogPostTag();
        $postIds = [];

        foreach ($tagIds as $tagId) {
            $relations = $postTag->all([new DataBaseWhere('tag_id', $tagId)]);
            foreach ($relations as $relation) {
                $postIds[] = $relation->post_id;
            }
        }

        if (empty($postIds)) {
            // No posts found for these tags, return impossible condition
            $where[] = new DataBaseWhere('id', 0);
        } else {
            // Filter by post IDs
            $postIds = array_unique($postIds);
            $where[] = new DataBaseWhere('id', implode(',', $postIds), 'IN');
        }

        return $where;
    }

    private function addPaginationHeaders(Response &$response, int $total, array $params): void
    {
        $response->headers->set('X-WP-Total', (string) $total);
        $response->headers->set('X-WP-TotalPages', (string) ceil($total / $params['per_page']));

        // Add Link header for pagination
        $baseUrl = rtrim($this->request->getSchemeAndHttpHost() . $this->request->getBaseUrl(), '/');
        $links = [];

        if ($params['page'] > 1) {
            $prevPage = $params['page'] - 1;
            $links[] = "<{$baseUrl}/api/3/blog/posts?page={$prevPage}&per_page={$params['per_page']}>; rel=\"prev\"";
        }

        if ($params['page'] < ceil($total / $params['per_page'])) {
            $nextPage = $params['page'] + 1;
            $links[] = "<{$baseUrl}/api/3/blog/posts?page={$nextPage}&per_page={$params['per_page']}>; rel=\"next\"";
        }

        if (!empty($links)) {
            $response->headers->set('Link', implode(', ', $links));
        }
    }

    private function sendError(Response &$response, string $message, int $statusCode): void
    {
        $error = [
            'code' => 'rest_blog_error',
            'message' => $message,
            'data' => ['status' => $statusCode]
        ];

        $response->setContent(json_encode($error, JSON_PRETTY_PRINT));
        $response->setStatusCode($statusCode);
    }
}
