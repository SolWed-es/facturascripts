<?php
namespace FacturaScripts\Plugins\Blog\Lib;

use FacturaScripts\Plugins\Blog\Model\BlogPost;
use FacturaScripts\Plugins\Blog\Model\BlogCategory;
use FacturaScripts\Plugins\Blog\Model\BlogTag;
use FacturaScripts\Core\Tools;

/**
 * WordPress-inspired API Response Formatter
 * Formats blog resources in a structured, consistent way
 *
 * @author Your Name
 */
class BlogApiResponse
{
    /**
     * Format a blog post for API response
     */
    public static function formatPost(BlogPost $post, bool $embed = false): array
    {
        $data = [
            'id' => $post->id,
            'date' => $post->published_at ?: $post->created_at,
            'modified' => $post->updated_at,
            'slug' => $post->slug,
            'status' => $post->status,
            'link' => self::getPostUrl($post->slug),
            'title' => [
                'rendered' => $post->title,
                'raw' => $post->title
            ],
            'content' => [
                'rendered' => $post->content,
                'raw' => $post->content
            ],
            'excerpt' => [
                'rendered' => $post->excerpt,
                'raw' => $post->excerpt
            ],
            'author' => $post->author,
            'featured_media' => $post->featured_image, // Filename only
            'featured_image' => $post->featured_image, // Filename (WordPress compat)
            'featured_image_url' => !empty($post->featured_image) ? FS_ROUTE . '/MyFiles/' . $post->featured_image : null, // Full URL
            'categories' => array_map(fn($c) => $c->id, $post->getCategories()),
            'tags' => array_map(fn($t) => $t->id, $post->getTags()),
            'meta' => [
                'title' => $post->meta_title,
                'description' => $post->meta_description,
                'keywords' => $post->meta_keywords
            ],
            // Computed fields
            'word_count' => self::getWordCount($post->content),
            'reading_time' => self::getReadingTime($post->content),
        ];

        // Add embedded resources if requested
        if ($embed) {
            $data['_embedded'] = self::embedResources($post);
        }

        // Add HATEOAS links
        $data['_links'] = self::generatePostLinks($post);

        return $data;
    }

    /**
     * Format a category for API response
     */
    public static function formatCategory(BlogCategory $category): array
    {
        return [
            'id' => $category->id,
            'name' => $category->name,
            'slug' => $category->slug,
            'description' => $category->description,
            'link' => self::getCategoryUrl($category->slug),
            '_links' => self::generateCategoryLinks($category)
        ];
    }

    /**
     * Format a tag for API response
     */
    public static function formatTag(BlogTag $tag): array
    {
        return [
            'id' => $tag->id,
            'name' => $tag->name,
            'slug' => $tag->slug,
            'link' => self::getTagUrl($tag->slug),
            '_links' => self::generateTagLinks($tag)
        ];
    }

    /**
     * Embed related resources (categories, tags) in post response
     */
    private static function embedResources(BlogPost $post): array
    {
        $categories = array_map(
            fn($c) => self::formatCategory($c),
            $post->getCategories()
        );

        $tags = array_map(
            fn($t) => self::formatTag($t),
            $post->getTags()
        );

        return [
            'wp:term' => [
                $categories,
                $tags
            ]
        ];
    }

    /**
     * Generate HATEOAS links for a post
     */
    private static function generatePostLinks(BlogPost $post): array
    {
        $baseUrl = self::getBaseUrl();

        return [
            'self' => [
                ['href' => "{$baseUrl}/api/3/blog/posts/{$post->id}"]
            ],
            'collection' => [
                ['href' => "{$baseUrl}/api/3/blog/posts"]
            ],
            'about' => [
                ['href' => "{$baseUrl}/blog/{$post->slug}"]
            ],
            'wp:term' => [
                [
                    'taxonomy' => 'category',
                    'embeddable' => true,
                    'href' => "{$baseUrl}/api/3/blog/categories?post={$post->id}"
                ],
                [
                    'taxonomy' => 'tag',
                    'embeddable' => true,
                    'href' => "{$baseUrl}/api/3/blog/tags?post={$post->id}"
                ]
            ]
        ];
    }

    /**
     * Generate HATEOAS links for a category
     */
    private static function generateCategoryLinks(BlogCategory $category): array
    {
        $baseUrl = self::getBaseUrl();

        return [
            'self' => [
                ['href' => "{$baseUrl}/api/3/blog/categories/{$category->id}"]
            ],
            'collection' => [
                ['href' => "{$baseUrl}/api/3/blog/categories"]
            ]
        ];
    }

    /**
     * Generate HATEOAS links for a tag
     */
    private static function generateTagLinks(BlogTag $tag): array
    {
        $baseUrl = self::getBaseUrl();

        return [
            'self' => [
                ['href' => "{$baseUrl}/api/3/blog/tags/{$tag->id}"]
            ],
            'collection' => [
                ['href' => "{$baseUrl}/api/3/blog/tags"]
            ]
        ];
    }

    /**
     * Calculate word count from HTML content
     */
    private static function getWordCount(string $content): int
    {
        $text = strip_tags($content);
        return str_word_count($text);
    }

    /**
     * Calculate estimated reading time in minutes
     */
    private static function getReadingTime(string $content): int
    {
        $wordCount = self::getWordCount($content);
        return max(1, ceil($wordCount / 200)); // 200 words per minute
    }

    /**
     * Get base URL from config
     */
    private static function getBaseUrl(): string
    {
        return rtrim(Tools::config('base_url', ''), '/');
    }

    /**
     * Get full URL for a post
     */
    private static function getPostUrl(string $slug): string
    {
        return self::getBaseUrl() . '/blog/' . $slug;
    }

    /**
     * Get full URL for a category
     */
    private static function getCategoryUrl(string $slug): string
    {
        return self::getBaseUrl() . '/blog/category/' . $slug;
    }

    /**
     * Get full URL for a tag
     */
    private static function getTagUrl(string $slug): string
    {
        return self::getBaseUrl() . '/blog/tag/' . $slug;
    }
}
