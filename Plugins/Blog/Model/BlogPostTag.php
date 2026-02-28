<?php
namespace FacturaScripts\Plugins\Blog\Model;

use FacturaScripts\Core\Model\Base\ModelClass;
use FacturaScripts\Core\Model\Base\ModelTrait;
use FacturaScripts\Core\Tools;

/**
 * Blog Post-Tag Junction Model
 * Relates posts with tags (N:M relationship)
 *
 * @author Your Name
 */
class BlogPostTag extends ModelClass
{
    use ModelTrait;

    /** @var int Primary key */
    public $id;

    /** @var int Post ID */
    public $post_id;

    /** @var int Tag ID */
    public $tag_id;

    public static function primaryColumn(): string
    {
        return 'id';
    }

    public static function tableName(): string
    {
        return 'blog_posts_tags';
    }

    public function clear(): void
    {
        parent::clear();
    }

    public function test(): bool
    {
        // Validate required fields
        if (empty($this->post_id)) {
            Tools::log()->warning('blog-post-tag-post-id-required');
            return false;
        }

        if (empty($this->tag_id)) {
            Tools::log()->warning('blog-post-tag-tag-id-required');
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
     * Get the related tag
     */
    public function getTag(): ?BlogTag
    {
        $tag = new BlogTag();
        return $tag->loadFromCode($this->tag_id) ? $tag : null;
    }
}
