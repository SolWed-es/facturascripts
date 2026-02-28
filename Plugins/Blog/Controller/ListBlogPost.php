<?php
namespace FacturaScripts\Plugins\Blog\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

/**
 * List controller for Blog Posts
 *
 * @author Your Name
 */
class ListBlogPost extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'web';
        $data['title'] = 'blog';
        $data['icon'] = 'fa-solid fa-blog';
        $data['showonmenu'] = true;
        $data['menuorder'] = 10;
        return $data;
    }

    protected function createViews(): void
    {
        $this->createViewBlogPosts();
    }

    protected function createViewBlogPosts(string $viewName = 'ListBlogPost'): void
    {
        $this->addView($viewName, 'BlogPost', 'blog-posts', 'fa-solid fa-blog');
        $this->addSearchFields($viewName, ['title', 'slug', 'content', 'excerpt']);
        $this->addOrderBy($viewName, ['id'], 'id', 2);
        $this->addOrderBy($viewName, ['title'], 'title');
        $this->addOrderBy($viewName, ['created_at'], 'created-at');
        $this->addOrderBy($viewName, ['published_at'], 'published-at');

        // Filters
        $this->addFilterSelect($viewName, 'status', 'status', 'status', [
            ['code' => 'draft', 'description' => 'draft'],
            ['code' => 'published', 'description' => 'published']
        ]);

        $this->addFilterAutocomplete($viewName, 'author', 'author', 'author', 'User', 'nick');
    }
}
