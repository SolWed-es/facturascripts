<?php
namespace FacturaScripts\Plugins\Blog\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;

/**
 * Edit controller for Blog Tags
 *
 * @author Your Name
 */
class EditBlogTag extends EditController
{
    public function getModelClassName(): string
    {
        return 'BlogTag';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'web';
        $data['title'] = 'tag';
        $data['icon'] = 'fa-solid fa-tags';
        $data['showonmenu'] = false;
        return $data;
    }
}
