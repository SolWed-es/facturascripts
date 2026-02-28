<?php
namespace FacturaScripts\Plugins\Blog\Controller;

use FacturaScripts\Core\Lib\ExtendedController\ListController;

/**
 * List controller for Blog Tags
 *
 * @author Your Name
 */
class ListBlogTag extends ListController
{
    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'web';
        $data['title'] = 'tags';
        $data['icon'] = 'fa-solid fa-tags';
        $data['showonmenu'] = true;
        $data['menuorder'] = 30;
        return $data;
    }

    protected function createViews(): void
    {
        $this->createViewBlogTags();
    }

    protected function createViewBlogTags(string $viewName = 'ListBlogTag'): void
    {
        $this->addView($viewName, 'BlogTag', 'tags', 'fa-solid fa-tags');
        $this->addSearchFields($viewName, ['name', 'slug']);
        $this->addOrderBy($viewName, ['id'], 'id', 2);
        $this->addOrderBy($viewName, ['name'], 'name');
    }
}
