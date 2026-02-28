<?php
namespace FacturaScripts\Plugins\Blog\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Tools;

/**
 * Blog Post-Category Junction Model
 * Relates posts with categories (N:M relationship)
 *
 * @author Your Name
 */
class BlogPostCategory extends ModelClass
{
    use ModelTrait;

    /** @var int Primary key */
    public $id;

    /** @var int Post ID */
    public $post_id;

    /** @var int Category ID */
    public $category_id;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'blog_posts_categories';
    }

    public function clear(): void
    {
        parent::clear();
    }

    public function test(): bool
    {
        // Validate required fields
        if (empty($this->post_id)) {
            Tools::log()->warning('blog-post-category-post-id-required');
            return false;
        }

        if (empty($this->category_id)) {
            Tools::log()->warning('blog-post-category-category-id-required');
            return false;
        }

        return parent::test();
    }

    /**
     * Get the related post
     */
    public function getPost(): ?BlogPost
    {
        $post = new BlogPost();
        return $post->loadFromCode($this->post_id) ? $post : null;
    }

    /**
     * Get the related category
     */
    public function getCategory(): ?BlogCategory
    {
        $category = new BlogCategory();
        return $category->loadFromCode($this->category_id) ? $category : null;
    }
}
