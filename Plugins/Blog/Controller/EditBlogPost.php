<?php
namespace FacturaScripts\Plugins\Blog\Controller;

use FacturaScripts\Core\Lib\ExtendedController\EditController;
use FacturaScripts\Core\Lib\ExtendedController\BaseView;
use FacturaScripts\Core\Lib\AssetManager;
use FacturaScripts\Plugins\Blog\Lib\AIService;

/**
 * Edit controller for Blog Posts
 *
 * @author Your Name
 */
class EditBlogPost extends EditController
{
    public function getModelClassName(): string
    {
        return 'BlogPost';
    }

    public function getPageData(): array
    {
        $data = parent::getPageData();
        $data['menu'] = 'web';
        $data['title'] = 'blog-post';
        $data['icon'] = 'fa-solid fa-blog';
        $data['showonmenu'] = false;
        return $data;
    }

    protected function createViews()
    {
        parent::createViews();

        // Load custom JavaScript for SEO generation
        AssetManager::add('js', FS_ROUTE . '/Plugins/Blog/Assets/JS/EditBlogPost.js');

        // Add SEO generation button (appears next to Save/Delete)
        $this->addButton($this->getMainViewName(), [
            'action' => 'generateSEO()',
            'icon' => 'fas fa-magic',
            'label' => 'Generar SEO con IA',
            'color' => 'info',
            'type' => 'js'
        ]);
    }

    /**
     * Execute previous actions
     *
     * @param string $action
     * @return bool
     */
    protected function execPreviousAction($action)
    {
        switch ($action) {
            case 'generate-seo':
                $this->setTemplate(false);
                $this->generateSEOAction();
                return false;
        }

        return parent::execPreviousAction($action);
    }

    /**
     * Generate SEO metadata using AI
     */
    private function generateSEOAction(): void
    {
        $this->response->headers->set('Content-Type', 'application/json');

        if ($this->request->getMethod() !== 'POST') {
            $this->response->setContent(json_encode([
                'success' => false,
                'message' => 'Only POST method is allowed'
            ]));
            $this->response->setStatusCode(405);
            return;
        }

        try {
            $data = json_decode($this->request->getContent(), true);

            if (empty($data['title']) || empty($data['content'])) {
                $this->response->setContent(json_encode([
                    'success' => false,
                    'message' => 'Title and content are required'
                ]));
                $this->response->setStatusCode(400);
                return;
            }

            $title = $data['title'];
            $content = $data['content'];
            $provider = $data['provider'] ?? 'groq';

            // Validate provider
            $availableProviders = AIService::getAvailableProviders();
            if (!in_array($provider, $availableProviders)) {
                $this->response->setContent(json_encode([
                    'success' => false,
                    'message' => "Provider '{$provider}' not available"
                ]));
                $this->response->setStatusCode(400);
                return;
            }

            // Generate SEO
            $result = AIService::generateSEO($title, $content, $provider);

            if (isset($result['error'])) {
                $this->response->setContent(json_encode([
                    'success' => false,
                    'message' => $result['error'],
                    'fallback' => true,
                    'data' => [
                        'meta_title' => $result['meta_title'],
                        'meta_description' => $result['meta_description'],
                        'meta_keywords' => $result['meta_keywords']
                    ]
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                return;
            }

            $this->response->setContent(json_encode([
                'success' => true,
                'data' => [
                    'meta_title' => $result['meta_title'],
                    'meta_description' => $result['meta_description'],
                    'meta_keywords' => $result['meta_keywords']
                ]
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        } catch (\Exception $e) {
            $this->response->setContent(json_encode([
                'success' => false,
                'message' => $e->getMessage()
            ]));
            $this->response->setStatusCode(500);
        }
    }

    /**
     * Load view data procedure
     *
     * @param string $viewName
     * @param BaseView $view
     */
    protected function loadData($viewName, $view)
    {
        parent::loadData($viewName, $view);
    }
}
