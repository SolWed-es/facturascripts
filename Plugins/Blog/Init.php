<?php
namespace FacturaScripts\Plugins\Blog;

use FacturaScripts\Core\Base\DataBase;
use FacturaScripts\Core\Base\DataBase\DataBaseWhere;
use FacturaScripts\Core\Model\Role;
use FacturaScripts\Core\Model\RoleAccess;
use FacturaScripts\Core\Template\InitClass;
use FacturaScripts\Core\Tools;

/**
 * Blog Plugin Initialization
 *
 * @author Your Name
 */
final class Init extends InitClass
{
    public function init(): void
    {
        // Load extensions if needed in the future
        // Plugin initialized silently

        /**
         * Enhanced API Endpoints Available:
         *
         * Posts:
         *   GET    /ApiBlogPosts            - List all posts (supports ?_embed=1, ?search=keyword, ?page=1&per_page=10)
         *   GET    /ApiBlogPosts/{id}       - Get single post by ID
         *   GET    /ApiBlogPosts/{slug}     - Get single post by slug
         *   POST   /ApiBlogPosts            - Create new post
         *   PUT    /ApiBlogPosts/{id|slug}  - Update post
         *   DELETE /ApiBlogPosts/{id|slug}  - Delete post
         *
         * Categories:
         *   GET    /ApiBlogCategories       - List all categories
         *   GET    /ApiBlogCategories/{id|slug} - Get single category
         *   POST   /ApiBlogCategories       - Create new category
         *   PUT    /ApiBlogCategories/{id|slug} - Update category
         *   DELETE /ApiBlogCategories/{id|slug} - Delete category
         *
         * Tags:
         *   GET    /ApiBlogTags             - List all tags
         *   GET    /ApiBlogTags/{id|slug}   - Get single tag
         *   POST   /ApiBlogTags             - Create new tag
         *   PUT    /ApiBlogTags/{id|slug}   - Update tag
         *   DELETE /ApiBlogTags/{id|slug}   - Delete tag
         *
         * Features:
         * - WordPress-compatible _embed parameter
         * - Unified search across title, content, excerpt
         * - Slug-based lookup
         * - HATEOAS links in responses
         * - Pagination headers (X-WP-Total, X-WP-TotalPages)
         */
    }

    public function uninstall(): void
    {
        // Cleanup on plugin removal
    }

    public function update(): void
    {
        // Initialize models (creates tables if they don't exist)
        new Model\BlogPost();
        new Model\BlogCategory();
        new Model\BlogTag();
        new Model\BlogPostCategory();
        new Model\BlogPostTag();

        // Create role and permissions for plugin
        $this->createRoleForPlugin();

        // Create sample data on first installation
        $this->createSampleData();
    }

    /**
     * Create role and permissions for Blog plugin
     */
    private function createRoleForPlugin(): void
    {
        $dataBase = new DataBase();
        $dataBase->beginTransaction();

        try {
            // Create role if not exists
            $role = new Role();
            if (false === $role->loadFromCode('Blog')) {
                $role->codrole = $role->descripcion = 'Blog';
                if (!$role->save()) {
                    $dataBase->rollback();
                    Tools::log()->error('Error creating Blog role');
                    return;
                }
            }

            // Assign permissions to controllers
            $controllers = [
                'ListBlogPost',
                'EditBlogPost',
                'ListBlogCategory',
                'EditBlogCategory',
                'ListBlogTag',
                'EditBlogTag'
            ];

            foreach ($controllers as $controller) {
                $roleAccess = new RoleAccess();
                $where = [
                    new DataBaseWhere('codrole', 'Blog'),
                    new DataBaseWhere('pagename', $controller)
                ];

                if ($roleAccess->loadFromCode('', $where)) {
                    continue;
                }

                $roleAccess->allowdelete = true;
                $roleAccess->allowupdate = true;
                $roleAccess->codrole = 'Blog';
                $roleAccess->pagename = $controller;

                if (!$roleAccess->save()) {
                    Tools::log()->warning('Error creating permissions for: ' . $controller);
                }
            }

            $dataBase->commit();
        } catch (\Exception $e) {
            $dataBase->rollback();
            Tools::log()->error('Error in createRoleForPlugin: ' . $e->getMessage());
        }
    }

    /**
     * Create sample data for testing (only if blog is empty)
     */
    private function createSampleData(): void
    {
        // Check if we already have data
        $postModel = new Model\BlogPost();
        if ($postModel->count() > 0) {
            return; // Already has data, skip
        }

        // Create categories
        $categories = $this->createSampleCategories();

        // Create tags
        $tags = $this->createSampleTags();

        // Create posts
        $this->createSamplePosts($categories, $tags);
    }

    private function createSampleCategories(): array
    {
        $categoryData = [
            ['name' => 'Tecnología', 'description' => 'Noticias y artículos sobre tecnología'],
            ['name' => 'Tutoriales', 'description' => 'Guías y tutoriales paso a paso'],
            ['name' => 'Noticias', 'description' => 'Últimas noticias del sector'],
            ['name' => 'Opinión', 'description' => 'Artículos de opinión y análisis']
        ];

        $categories = [];
        foreach ($categoryData as $data) {
            $category = new Model\BlogCategory();
            $category->name = $data['name'];
            $category->description = $data['description'];
            if ($category->save()) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    private function createSampleTags(): array
    {
        $tagNames = ['JavaScript', 'PHP', 'React', 'WordPress', 'Tutorial', 'FacturaScripts', 'API', 'Frontend', 'Backend', 'SEO'];

        $tags = [];
        foreach ($tagNames as $name) {
            $tag = new Model\BlogTag();
            $tag->name = $name;
            if ($tag->save()) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    private function createSamplePosts(array $categories, array $tags): void
    {
        $posts = [
            [
                'title' => 'Bienvenido al Blog',
                'content' => 'Este es el primer post de ejemplo del blog. Puedes editarlo o eliminarlo cuando quieras. El contenido está escrito en texto plano, sin formato HTML. Si necesitas añadir formato rico, puedes editarlo manualmente en el editor o usar herramientas externas para convertir tu contenido.',
                'excerpt' => 'Post de bienvenida al blog',
                'categories' => [0], // Tecnología
                'tags' => [5] // FacturaScripts
            ],
            [
                'title' => 'Cómo usar este plugin',
                'content' => 'El plugin Blog te permite crear y gestionar contenido de blog directamente desde FacturaScripts. Puedes crear posts, organizarlos en categorías, añadir etiquetas y publicarlos cuando estén listos. También incluye una API REST completa para consumir el contenido desde aplicaciones externas o sitios web headless.',
                'excerpt' => 'Guía rápida de uso del plugin Blog',
                'categories' => [1], // Tutoriales
                'tags' => [4, 5] // Tutorial, FacturaScripts
            ],
            [
                'title' => 'Generación automática de SEO',
                'content' => 'Este plugin incluye una funcionalidad de generación automática de metadata SEO usando inteligencia artificial. Simplemente escribe tu contenido y haz clic en el botón Generar SEO con IA para que se complete automáticamente el título SEO, la descripción y las palabras clave. Soporta proveedores como Groq y Cohere.',
                'excerpt' => 'Aprende a usar la generación automática de SEO con IA',
                'categories' => [1], // Tutoriales
                'tags' => [4, 9] // Tutorial, SEO
            ]
        ];

        foreach ($posts as $postData) {
            $post = new Model\BlogPost();
            $post->title = $postData['title'];
            $post->content = $postData['content'];
            $post->excerpt = $postData['excerpt'];
            $post->status = 'published';
            $post->author = 'admin';
            $post->published_at = Tools::dateTime();

            if ($post->save()) {
                // Assign categories
                foreach ($postData['categories'] as $catIndex) {
                    if (isset($categories[$catIndex])) {
                        $rel = new Model\BlogPostCategory();
                        $rel->post_id = $post->id;
                        $rel->category_id = $categories[$catIndex]->id;
                        $rel->save();
                    }
                }

                // Assign tags
                foreach ($postData['tags'] as $tagIndex) {
                    if (isset($tags[$tagIndex])) {
                        $rel = new Model\BlogPostTag();
                        $rel->post_id = $post->id;
                        $rel->tag_id = $tags[$tagIndex]->id;
                        $rel->save();
                    }
                }
            }
        }
    }
}
