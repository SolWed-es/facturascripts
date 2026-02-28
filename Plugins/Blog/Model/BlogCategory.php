<?php
namespace FacturaScripts\Plugins\Blog\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Tools;

/**
 * Blog Category Model
 *
 * @author Your Name
 */
class BlogCategory extends ModelClass
{
    use ModelTrait;

    /** @var int Primary key */
    public $id;

    /** @var string Category name */
    public $name;

    /** @var string URL-friendly slug */
    public $slug;

    /** @var string Category description */
    public $description;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public function primaryDescriptionColumn(): string
    {
        return 'name';
    }

    public static function tableName(): string
    {
        return 'blog_categories';
    }

    public function clear(): void
    {
        parent::clear();
    }

    public function test(): bool
    {
        // Sanitize data
        $this->name = Tools::noHtml($this->name);
        $this->slug = $this->generateSlug();
        $this->description = Tools::noHtml($this->description);

        // Validate required fields
        if (empty($this->name)) {
            Tools::log()->warning('blog-category-name-required');
            return false;
        }

        if (empty($this->slug)) {
            Tools::log()->warning('blog-category-slug-required');
            return false;
        }

        return parent::test();
    }

    /**
     * Generate URL-friendly slug from name
     */
    private function generateSlug(): string
    {
        if (!empty($this->slug)) {
            return $this->slugify($this->slug);
        }

        return $this->slugify($this->name);
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
            return 'category-' . time();
        }

        return $text;
    }

    /**
     * Get posts count for this category
     */
    public function getPostsCount(): int
    {
        $postCategory = new BlogPostCategory();
        return $postCategory->count(['category_id' => $this->id]);
    }
}
