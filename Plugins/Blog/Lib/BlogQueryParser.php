<?php
namespace FacturaScripts\Plugins\Blog\Lib;

use Symfony\Component\HttpFoundation\Request;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

/**
 * WordPress-inspired Query Parameter Parser
 * Parses WordPress-style query parameters and converts them to FacturaScripts format
 *
 * @author Your Name
 */
class BlogQueryParser
{
    /**
     * Parse request query parameters
     */
    public static function parse(Request $request): array
    {
        $params = [];

        // Pagination - WordPress style
        $params['page'] = $request->query->getInt('page', 1);
        $params['per_page'] = $request->query->getInt('per_page', 10);
        $params['offset'] = ($params['page'] - 1) * $params['per_page'];
        $params['limit'] = $params['per_page'];

        // Also support FacturaScripts native pagination
        if ($request->query->has('offset')) {
            $params['offset'] = $request->query->getInt('offset', 0);
        }
        if ($request->query->has('limit')) {
            $params['limit'] = $request->query->getInt('limit', 10);
        }

        // Search - unified search across multiple fields
        $params['search'] = $request->query->get('search', '');

        // Filters
        $params['status'] = $request->query->get('status', '');
        $params['author'] = $request->query->get('author', '');
        $params['categories'] = $request->query->get('categories', '');
        $params['tags'] = $request->query->get('tags', '');
        $params['slug'] = $request->query->get('slug', '');

        // Date filters - WordPress style
        $params['before'] = $request->query->get('before', '');
        $params['after'] = $request->query->get('after', '');

        // Sorting - WordPress style
        $params['orderby'] = $request->query->get('orderby', 'date');
        $params['order'] = strtoupper($request->query->get('order', 'DESC'));

        // Embedding
        $params['_embed'] = $request->query->getBoolean('_embed', false);
        $params['_fields'] = $request->query->get('_fields', '');

        return $params;
    }

    /**
     * Build WHERE conditions from parsed parameters
     */
    public static function buildWhere(array $params): array
    {
        $where = [];

        // Unified search across title, content, excerpt
        if (!empty($params['search'])) {
            $search = $params['search'];
            $where[] = new DataBaseWhere('title', "%{$search}%", 'LIKE', 'OR');
            $where[] = new DataBaseWhere('content', "%{$search}%", 'LIKE', 'OR');
            $where[] = new DataBaseWhere('excerpt', "%{$search}%", 'LIKE', 'OR');
        }

        // Status filter
        if (!empty($params['status'])) {
            $statuses = explode(',', $params['status']);
            if (count($statuses) === 1) {
                $where[] = new DataBaseWhere('status', $statuses[0]);
            } else {
                // Multiple statuses
                foreach ($statuses as $idx => $status) {
                    $where[] = new DataBaseWhere('status', $status, '=', $idx === 0 ? 'OR' : 'OR');
                }
            }
        }

        // Author filter
        if (!empty($params['author'])) {
            $where[] = new DataBaseWhere('author', $params['author']);
        }

        // Slug filter
        if (!empty($params['slug'])) {
            $where[] = new DataBaseWhere('slug', $params['slug']);
        }

        // Date filters
        if (!empty($params['before'])) {
            $where[] = new DataBaseWhere('published_at', $params['before'], '<=');
        }

        if (!empty($params['after'])) {
            $where[] = new DataBaseWhere('published_at', $params['after'], '>=');
        }

        return $where;
    }

    /**
     * Build ORDER BY clause from parsed parameters
     */
    public static function buildOrder(array $params): array
    {
        $orderField = self::mapOrderField($params['orderby']);
        $orderDirection = in_array($params['order'], ['ASC', 'DESC']) ? $params['order'] : 'DESC';

        return [$orderField => $orderDirection];
    }

    /**
     * Map WordPress-style order field names to database fields
     */
    private static function mapOrderField(string $orderby): string
    {
        $mapping = [
            'date' => 'published_at',
            'modified' => 'updated_at',
            'title' => 'title',
            'author' => 'author',
            'id' => 'id',
            'slug' => 'slug'
        ];

        return $mapping[$orderby] ?? 'published_at';
    }

    /**
     * Parse category filter and return category IDs
     */
    public static function parseCategoryFilter(string $categories): array
    {
        if (empty($categories)) {
            return [];
        }

        // Support both comma-separated IDs and single ID
        return array_map('intval', explode(',', $categories));
    }

    /**
     * Parse tag filter and return tag IDs
     */
    public static function parseTagFilter(string $tags): array
    {
        if (empty($tags)) {
            return [];
        }

        // Support both comma-separated IDs and single ID
        return array_map('intval', explode(',', $tags));
    }

    /**
     * Filter response fields based on _fields parameter
     */
    public static function filterFields(array $data, string $fields): array
    {
        if (empty($fields)) {
            return $data;
        }

        $allowedFields = explode(',', $fields);
        $filtered = [];

        foreach ($allowedFields as $field) {
            $field = trim($field);
            if (isset($data[$field])) {
                $filtered[$field] = $data[$field];
            }
        }

        return $filtered;
    }
}
