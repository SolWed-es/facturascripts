<?php
namespace FacturaScripts\Plugins\Blog\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

/**
 * List controller for Blog Categories
 *
 * @author Your Name
 */
class ListBlogCategory extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'web';
        $data['title'] = 'categories';
        $data['icon'] = 'fa-solid fa-folder';
        $data['showonmenu'] = true;
        $data['menuorder'] = 20;
        return $data;
    }

    protected function createViews(): void
    {
        $this->createViewBlogCategories();
    }

    protected function createViewBlogCategories(string $viewName = 'ListBlogCategory'): void
    {
        $this->addView($viewName, 'BlogCategory', 'categories', 'fa-solid fa-folder');
        $this->addSearchFields($viewName, ['name', 'slug', 'description']);
        $this->addOrderBy($viewName, ['id'], 'id', 2);
        $this->addOrderBy($viewName, ['name'], 'name');
    }
}
