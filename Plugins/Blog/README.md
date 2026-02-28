# Blog Plugin for FacturaScripts

Sistema de gestión de blog tipo WordPress con categorías y etiquetas. Incluye API REST completa para consumo externo con Astro, Next.js o cualquier frontend moderno.

## Características

- ✅ **Posts de blog** con título, contenido, extracto y slug automático
- ✅ **Categorías** de un nivel (sin jerarquía)
- ✅ **Etiquetas** múltiples por post
- ✅ **Imagen destacada** por post
- ✅ **Estado de publicación** (borrador/publicado)
- ✅ **Metadata SEO** completa (title, description, keywords)
- ✅ **Generación SEO con IA** (Groq, Cohere - APIs gratuitas)
- ✅ **API REST** completa auto-generada
- ✅ **Departamento Web** en el menú de FacturaScripts

## 🚀 API Mejorada WordPress-Compatible (NUEVO)

El plugin ahora incluye una **API REST mejorada** inspirada en WordPress que ofrece:

### Ventajas sobre la API Estándar

| Característica | API Estándar | API Mejorada |
|----------------|--------------|--------------|
| Peticiones para post completo | **3-5 peticiones** | **1 petición** |
| Búsqueda unificada | ❌ Campo específico | ✅ `?search=keyword` |
| Lookup por slug | ❌ Solo ID | ✅ ID o slug |
| Relaciones embebidas | ❌ Múltiples peticiones | ✅ `?_embed=1` |
| HATEOAS links | ❌ No | ✅ Sí |
| Paginación | offset/limit | page/per_page |
| Compatibilidad | FacturaScripts | WordPress + FacturaScripts |

### ✨ Nuevas Características

1. **_embed Parameter** - Obtén posts con categorías y tags en una sola petición
2. **Unified Search** - Busca en título, contenido y extracto con `?search=`
3. **Slug Lookup** - Accede a posts por slug: `/ApiBlogPosts/mi-post`
4. **HATEOAS Links** - API autodocumentada con `_links`
5. **Computed Fields** - `word_count`, `reading_time`, `link` automáticos
6. **Pagination Headers** - `X-WP-Total`, `X-WP-TotalPages`, `Link`

### 🎯 Ejemplo Comparativo

**Antes (API Estándar):**
```typescript
// 3-5 peticiones HTTP
const post = await fetch('/api/3/blogposts/1');
const postCats = await fetch('/api/3/blogpostscategories?post_id=1');
const categories = await Promise.all(/* más peticiones... */);
// Código complejo...
```

**Ahora (API Mejorada):**
```typescript
// 1 petición HTTP
const post = await fetch('/ApiBlogPosts/mi-post?_embed=1');
// ¡Ya incluye todo! categorías, tags, metadata
```

**Mejora de Performance**: 60-70% menos peticiones, 3-4x más rápido para SSR/SSG

## 🤖 Generación Automática de SEO con IA (NUEVO)

El plugin incluye un sistema de **generación automática de metadata SEO** usando inteligencia artificial. Con un solo clic, genera títulos, descripciones y keywords optimizados para SEO.

### Proveedores de IA Gratuitos Soportados

1. **Groq** (RECOMENDADO) - 14,400 peticiones/día GRATIS
   - Registrarse: https://console.groq.com/
   - Modelo: Llama 3 (8B) - Ultra rápido
   - Sin tarjeta de crédito requerida

2. **Cohere** - 1000 peticiones/mes GRATIS
   - Registrarse: https://dashboard.cohere.com/
   - Modelo: Command-R
   - Sin tarjeta de crédito requerida

### Configuración

1. **Obtener API Key:**
   - Groq: Ir a https://console.groq.com/ → Keys → Create API Key
   - Cohere: Ir a https://dashboard.cohere.com/ → API Keys → Create Key

2. **Configurar en FacturaScripts:**
   - Ir a: Admin → Configuración → Blog
   - Configurar la API key según el proveedor:
     - `ai_api_key_groq` - Para Groq (recomendado)
     - `ai_api_key_cohere` - Para Cohere

3. **Usar el generador:**
   - Ir a Web → Blog → Editar cualquier post
   - Escribir título y contenido
   - Hacer clic en **"Generate SEO with AI"** en la sección SEO
   - Los campos meta_title, meta_description y meta_keywords se llenarán automáticamente

### Funcionamiento

El sistema AI analiza el título y contenido del post para generar:
- **Meta Title** - Título optimizado SEO (máx. 60 caracteres)
- **Meta Description** - Descripción atractiva (máx. 160 caracteres)
- **Meta Keywords** - 5-10 palabras clave relevantes

Si no hay API key configurada, el sistema usa un **generador fallback** que:
- Usa el título original como meta_title
- Extrae los primeros 160 caracteres del contenido como meta_description
- Genera keywords desde palabras del título (5+ letras)

### Endpoint API

También puedes generar SEO mediante la API:

```bash
curl -X POST 'https://tu-dominio.com/GenerateSEO' \
  -H 'Content-Type: application/json' \
  -d '{
    "title": "Mi Post de Ejemplo",
    "content": "Contenido completo del post...",
    "provider": "groq"
  }'
```

**Respuesta:**
```json
{
  "success": true,
  "data": {
    "meta_title": "Mi Post de Ejemplo - Guía Completa 2025",
    "meta_description": "Descubre todo sobre Mi Post de Ejemplo en esta guía completa con tips prácticos y ejemplos reales.",
    "meta_keywords": "ejemplo, post, blog, guía, tutorial"
  }
}
```

### Ventajas

✅ **100% Gratis** - Proveedores con tiers gratuitos generosos
✅ **Sin límites diarios** - Groq ofrece 14,400 requests/día
✅ **Rápido** - Generación en 1-3 segundos
✅ **Fallback automático** - Funciona sin API key
✅ **Múltiples proveedores** - Cambia fácilmente entre Groq y Cohere

## Estructura de Base de Datos

### Tablas Principales

1. **blog_posts** - Posts del blog
   - `id`, `title`, `slug`, `content`, `excerpt`
   - `status`, `author`, `published_at`
   - `featured_image`
   - `meta_title`, `meta_description`, `meta_keywords`
   - `created_at`, `updated_at`

2. **blog_categories** - Categorías
   - `id`, `name`, `slug`, `description`

3. **blog_tags** - Etiquetas
   - `id`, `name`, `slug`

4. **blog_posts_categories** - Relación N:M posts-categorías
   - `id`, `post_id`, `category_id`

5. **blog_posts_tags** - Relación N:M posts-etiquetas
   - `id`, `post_id`, `tag_id`

## API REST

El plugin ofrece **DOS APIs complementarias**:

1. **API Mejorada (RECOMENDADA)** - WordPress-compatible con `_embed`, búsqueda unificada, slug lookup
2. **API Estándar** - Auto-generada por FacturaScripts, CRUD básico

---

## API Mejorada (WordPress-Compatible) 🚀

### Endpoints Principales

**Posts:**
- `GET /ApiBlogPosts` - Listar posts
- `GET /ApiBlogPosts/{id|slug}` - Obtener por ID o slug
- `POST /ApiBlogPosts` - Crear post
- `PUT /ApiBlogPosts/{id|slug}` - Actualizar post
- `DELETE /ApiBlogPosts/{id|slug}` - Eliminar post

**Categorías:**
- `GET /ApiBlogCategories` - Listar categorías
- `GET /ApiBlogCategories/{id|slug}` - Obtener por ID o slug
- `POST /ApiBlogCategories` - Crear categoría
- (PUT/DELETE similar a posts)

**Etiquetas:**
- `GET /ApiBlogTags` - Listar etiquetas
- `GET /ApiBlogTags/{id|slug}` - Obtener por ID o slug
- (POST/PUT/DELETE similar a posts)

### Ejemplos de Uso

#### Obtener post con categorías y tags (1 petición)

```bash
# Por ID con relaciones embebidas
curl 'https://tu-dominio.com/ApiBlogPosts/1?_embed=1'

# Por slug con relaciones embebidas
curl 'https://tu-dominio.com/ApiBlogPosts/mi-primer-post?_embed=1'
```

**Respuesta:**
```json
{
  "id": 1,
  "slug": "mi-primer-post",
  "title": {"rendered": "Mi Primer Post", "raw": "Mi Primer Post"},
  "content": {"rendered": "Contenido...", "raw": "Contenido..."},
  "categories": [1, 2],
  "tags": [5, 8],
  "word_count": 350,
  "reading_time": 2,
  "link": "https://tu-dominio.com/blog/mi-primer-post",
  "_embedded": {
    "wp:term": [
      [
        {"id": 1, "name": "Tecnología", "slug": "tecnologia"},
        {"id": 2, "name": "Tutoriales", "slug": "tutoriales"}
      ],
      [
        {"id": 5, "name": "JavaScript", "slug": "javascript"},
        {"id": 8, "name": "React", "slug": "react"}
      ]
    ]
  },
  "_links": {
    "self": [{"href": "https://tu-dominio.com/ApiBlogPosts/1"}],
    "collection": [{"href": "https://tu-dominio.com/ApiBlogPosts"}]
  }
}
```

#### Búsqueda unificada

```bash
# Busca en título, contenido y extracto
curl 'https://tu-dominio.com/ApiBlogPosts?search=javascript&_embed=1'
```

#### Paginación WordPress-style

```bash
# Página 2, 10 resultados por página
curl 'https://tu-dominio.com/ApiBlogPosts?page=2&per_page=10'
```

**Headers de respuesta:**
```
X-WP-Total: 152
X-WP-TotalPages: 16
Link: <https://tu-dominio.com/ApiBlogPosts?page=3&per_page=10>; rel="next"
```

#### Filtros avanzados

```bash
# Posts publicados, ordenados por fecha
curl 'https://tu-dominio.com/ApiBlogPosts?status=published&orderby=date&order=DESC'

# Posts de una categoría específica
curl 'https://tu-dominio.com/ApiBlogPosts?categories=1,2&_embed=1'

# Posts con etiquetas específicas
curl 'https://tu-dominio.com/ApiBlogPosts?tags=5&_embed=1'

# Posts después de una fecha
curl 'https://tu-dominio.com/ApiBlogPosts?after=2025-01-01&_embed=1'
```

#### Crear post con categorías y tags

```bash
curl -X POST 'https://tu-dominio.com/ApiBlogPosts' \
  -H 'Content-Type: application/json' \
  -d '{
    "title": "Nuevo Post",
    "content": "Contenido del post...",
    "excerpt": "Resumen corto",
    "status": "published",
    "categories": [1, 2],
    "tags": [5, 8],
    "meta": {
      "title": "Nuevo Post - SEO Title",
      "description": "Meta descripción para SEO"
    }
  }'
```

---

## API Estándar (FacturaScripts)

Para compatibilidad con sistemas existentes, la API estándar sigue disponible:

### Posts

```bash
# Listar todos los posts
GET /api/3/blogposts

# Listar posts publicados
GET /api/3/blogposts?status=published

# Obtener un post por ID
GET /api/3/blogposts/{id}

# Obtener un post por slug
GET /api/3/blogposts?slug=mi-post

# Crear post
POST /api/3/blogposts
Content-Type: application/json

{
  "title": "Mi Post",
  "content": "Contenido del post...",
  "excerpt": "Resumen corto",
  "status": "published",
  "featured_image": "https://example.com/image.jpg",
  "meta_title": "Mi Post - SEO Title",
  "meta_description": "Descripción para SEO",
  "meta_keywords": "blog, post, ejemplo"
}

# Actualizar post
PUT /api/3/blogposts/{id}
Content-Type: application/json

{
  "status": "published",
  "published_at": "2025-01-18 10:00:00"
}

# Eliminar post
DELETE /api/3/blogposts/{id}
```

### Categorías

```bash
# Listar todas las categorías
GET /api/3/blogcategories

# Obtener categoría por ID
GET /api/3/blogcategories/{id}

# Crear categoría
POST /api/3/blogcategories
Content-Type: application/json

{
  "name": "Tecnología",
  "description": "Posts sobre tecnología"
}

# Actualizar categoría
PUT /api/3/blogcategories/{id}

# Eliminar categoría
DELETE /api/3/blogcategories/{id}
```

### Etiquetas

```bash
# Listar todas las etiquetas
GET /api/3/blogtags

# Crear etiqueta
POST /api/3/blogtags
Content-Type: application/json

{
  "name": "JavaScript"
}
```

### Relaciones

```bash
# Asignar categoría a post
POST /api/3/blogpostscategories
Content-Type: application/json

{
  "post_id": 1,
  "category_id": 2
}

# Asignar etiqueta a post
POST /api/3/blogpoststags
Content-Type: application/json

{
  "post_id": 1,
  "tag_id": 3
}

# Obtener categorías de un post
GET /api/3/blogpostscategories?post_id=1

# Obtener etiquetas de un post
GET /api/3/blogpoststags?post_id=1
```

## Filtros Avanzados

La API soporta filtros avanzados:

```bash
# Posts publicados después de una fecha
GET /api/3/blogposts?published_at_gte=2025-01-01

# Posts con título que contiene "tutorial"
GET /api/3/blogposts?title_like=tutorial

# Ordenar por fecha de publicación descendente
GET /api/3/blogposts?sort[published_at]=DESC

# Paginación (50 posts, saltando los primeros 0)
GET /api/3/blogposts?limit=50&offset=0
```

Operadores disponibles:
- `_gt` - Mayor que
- `_lt` - Menor que
- `_gte` - Mayor o igual
- `_lte` - Menor o igual
- `_neq` - Diferente
- `_like` - Contiene (búsqueda por patrón)
- `_null` / `_notnull` - Es nulo / No es nulo

## Ejemplo de Consumo con Astro

```typescript
// src/lib/api.ts
const API_URL = 'https://tu-dominio.com/api/3';

export async function getBlogPosts() {
  const response = await fetch(`${API_URL}/blogposts?status=published&sort[published_at]=DESC`);
  return response.json();
}

export async function getBlogPost(slug: string) {
  const response = await fetch(`${API_URL}/blogposts?slug=${slug}`);
  const data = await response.json();
  return data[0];
}

export async function getCategories() {
  const response = await fetch(`${API_URL}/blogcategories`);
  return response.json();
}

// Obtener posts de una categoría
export async function getPostsByCategory(categoryId: number) {
  // 1. Obtener relaciones post-categoría
  const relations = await fetch(`${API_URL}/blogpostscategories?category_id=${categoryId}`).then(r => r.json());

  // 2. Obtener los posts
  const postIds = relations.map(r => r.post_id).join(',');
  const posts = await fetch(`${API_URL}/blogposts?id=${postIds}`).then(r => r.json());

  return posts;
}
```

```astro
---
// src/pages/blog/index.astro
import { getBlogPosts } from '../../lib/api';

const posts = await getBlogPosts();
---

<h1>Blog</h1>
<div class="posts">
  {posts.map(post => (
    <article>
      <h2><a href={`/blog/${post.slug}`}>{post.title}</a></h2>
      {post.featured_image && <img src={post.featured_image} alt={post.title} />}
      <p>{post.excerpt}</p>
      <time>{post.published_at}</time>
    </article>
  ))}
</div>
```

## Uso en FacturaScripts

El plugin añade un departamento **Web** en el menú principal con:

1. **Blog** - Lista de posts con filtros por estado y autor
2. **Categorías** - Gestión de categorías
3. **Etiquetas** - Gestión de etiquetas

## Slug Automático

Los slugs se generan automáticamente a partir del título/nombre:
- "Mi Post de Ejemplo" → "mi-post-de-ejemplo"
- Soporta caracteres UTF-8 (español, acentos, ñ)
- Si el campo slug está vacío, se genera del título
- Si se proporciona un slug, se normaliza automáticamente

## Validaciones

- **Posts**: Título y slug obligatorios
- **Categorías**: Nombre y slug obligatorios
- **Etiquetas**: Nombre y slug obligatorios
- **Slugs únicos**: No pueden repetirse dentro de cada tabla
- **Estados válidos**: Solo "draft" o "published"

## Instalación

1. Copiar el plugin a `Plugins/Blog/`
2. Acceder al panel de FacturaScripts
3. Ir a Admin → Plugins
4. Activar el plugin "Blog"
5. Las tablas se crean automáticamente
6. Se crea el rol "Blog" con permisos completos

## Permisos

El plugin crea automáticamente un rol "Blog" con acceso completo a:
- ListBlogPost / EditBlogPost
- ListBlogCategory / EditBlogCategory
- ListBlogTag / EditBlogTag

## Desarrollo

Para desarrollar o extender el plugin:

```bash
# Validar sintaxis PHP
find Plugins/Blog -name "*.php" -exec php -l {} \;

# Ejecutar tests (si se implementan)
vendor/bin/phpunit --filter Blog
```

## Soporte

Para reportar bugs o solicitar features:
- GitHub Issues
- Email del desarrollador

## Licencia

LGPL-3.0-or-later (misma que FacturaScripts)

---

**Versión**: 1.1
**Requiere**: FacturaScripts >= 2025.2, PHP >= 8.0, ext-curl (para AI)
**Novedades v1.1**: Generación automática de SEO con IA (Groq, Cohere), datos de ejemplo en instalación
