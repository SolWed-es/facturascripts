<?php
namespace FacturaScripts\Plugins\Blog\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Tools;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;

/**
 * Blog Post Model
 *
 * @author Your Name
 */
class BlogPost extends ModelClass
{
    use ModelTrait;

    /** @var int Primary key */
    public $id;

    /** @var string Post title */
    public $title;

    /** @var string URL-friendly slug */
    public $slug;

    /** @var string Full post content */
    public $content;

    /** @var string Short excerpt/summary */
    public $excerpt;

    /** @var string Post status: draft, published */
    public $status;

    /** @var string Author username */
    public $author;

    /** @var string Publication date */
    public $published_at;

    /** @var string Featured image URL or path */
    public $featured_image;

    /** @var string SEO title */
    public $meta_title;

    /** @var string SEO meta description */
    public $meta_description;

    /** @var string SEO keywords */
    public $meta_keywords;

    /** @var string Creation timestamp */
    public $created_at;

    /** @var string Last update timestamp */
    public $updated_at;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'title';
    }

    public static function tableName(): string
    {
        return 'blog_posts';
    }

    public function clear(): void
    {
        parent::clear();
        $this->status = 'draft';
        $this->created_at = Tools::dateTime();
        $this->updated_at = Tools::dateTime();
    }

    public function test(): bool
    {
        // Sanitize data
        $this->title = Tools::noHtml($this->title);
        $this->slug = $this->generateSlug();
        $this->excerpt = Tools::noHtml($this->excerpt);
        $this->meta_title = Tools::noHtml($this->meta_title);
        $this->meta_description = Tools::noHtml($this->meta_description);
        $this->meta_keywords = Tools::noHtml($this->meta_keywords);
        $this->updated_at = Tools::dateTime();

        // Validate required fields
        if (empty($this->title)) {
            Tools::log()->warning('blog-post-title-required');
            return false;
        }

        if (empty($this->slug)) {
            Tools::log()->warning('blog-post-slug-required');
            return false;
        }

        // Validate status
        if (!in_array($this->status, ['draft', 'published'])) {
            $this->status = 'draft';
        }

        return parent::test();
    }

    /**
     * Generate URL-friendly slug from title
     */
    private function generateSlug(): string
    {
        if (!empty($this->slug)) {
            return $this->slugify($this->slug);
        }

        return $this->slugify($this->title);
    }

    /**
     * Convert string to URL-friendly slug
     */
    private function slugify(string $text): string
    {
        // Replace non letter or digits by -
        $text = preg_replace('~[^\pL\d]+~u', '-', $text);

        // Transliterate
        $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);

        // Remove unwanted characters
        $text = preg_replace('~[^-\w]+~', '', $text);

        // Trim
        $text = trim($text, '-');

        // Remove duplicate -
        $text = preg_replace('~-+~', '-', $text);

        // Lowercase
        $text = strtolower($text);

        if (empty($text)) {
            return 'post-' . time();
        }

        return $text;
    }

    /**
     * Get categories for this post
     */
    public function getCategories(): array
    {
        $categories = [];
        $postCategory = new BlogPostCategory();
        $where = [new DataBaseWhere('post_id', $this->id)];

        foreach ($postCategory->all($where, [], 0, 0) as $relation) {
            $category = new BlogCategory();
            if ($category->loadFromCode($relation->category_id)) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    /**
     * Get tags for this post
     */
    public function getTags(): array
    {
        $tags = [];
        $postTag = new BlogPostTag();
        $where = [new DataBaseWhere('post_id', $this->id)];

        foreach ($postTag->all($where, [], 0, 0) as $relation) {
            $tag = new BlogTag();
            if ($tag->loadFromCode($relation->tag_id)) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /**
     * Get a post by slug (WordPress-compatible lookup)
     */
    public static function getBySlug(string $slug): ?BlogPost
    {
        $post = new self();
        $where = [new DataBaseWhere('slug', $slug)];
        $posts = $post->all($where, [], 0, 1);

        return empty($posts) ? null : $posts[0];
    }

    /**
     * Enhanced toArray method with computed fields
     */
    public function toArray(bool $all = false): array
    {
        $data = parent::toArray($all);

        // Add computed fields for API responses
        if (!empty($this->id)) {
            $data['categories'] = array_map(fn($c) => $c->id, $this->getCategories());
            $data['tags'] = array_map(fn($t) => $t->id, $this->getTags());
            $data['category_names'] = array_map(fn($c) => $c->name, $this->getCategories());
            $data['tag_names'] = array_map(fn($t) => $t->name, $this->getTags());

            // Word count and reading time
            $content = strip_tags($this->content);
            $data['word_count'] = str_word_count($content);
            $data['reading_time'] = max(1, ceil($data['word_count'] / 200)); // minutes

            // Full link URL
            $data['link'] = rtrim(Tools::config('base_url', ''), '/') . '/blog/' . $this->slug;

            // Featured image URL (convert filename to full URL)
            if (!empty($this->featured_image)) {
                $data['featured_image_url'] = FS_ROUTE . '/MyFiles/' . $this->featured_image;
            }
        }

        return $data;
    }
}
