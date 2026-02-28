<?php
namespace FacturaScripts\Plugins\Blog\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;

/**
 * Edit controller for Blog Categories
 *
 * @author Your Name
 */
class EditBlogCategory extends EditController
{
    public function getModelClassName(): string
    {
        return 'BlogCategory';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'web';
        $data['title'] = 'category';
        $data['icon'] = 'fa-solid fa-folder';
        $data['showonmenu'] = false;
        return $data;
    }
}
