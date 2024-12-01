
# Evolve API Documentation

## Overview
A RESTful API generator for Laravel Evolve models with built-in optimization, validation, and documentation.

## Table of Contents
1. [Installation & Setup](#installation--setup)
2. [Basic Usage](#basic-usage)
3. [API Endpoints](#api-endpoints)
    - [Listing Resources](#listing-resources)
    - [Retrieving Resources](#retrieving-resources)
    - [Creating Resources](#creating-resources)
    - [Updating Resources](#updating-resources)
    - [Deleting Resources](#deleting-resources)
4. [Query Parameters](#query-parameters)
    - [Filtering](#filtering)
    - [Sorting](#sorting)
    - [Including Relations](#including-relations)
    - [Pagination](#pagination)
    - [Selecting Fields](#selecting-fields)
5. [Response Format](#response-format)
    - [Success Responses](#success-responses)
    - [Error Responses](#error-responses)
    - [Metadata](#metadata)
6. [Advanced Usage](#advanced-usage)
    - [Custom Endpoints](#custom-endpoints)
    - [Request/Response Hooks](#requestresponse-hooks)
    - [Custom Validation](#custom-validation)
    - [Authorization](#authorization)

---

## Installation & Setup

First, install the package via Composer:

```bash
composer require thinkneverland/evolve-api
```

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Thinkneverland\Evolve\Api\EvolveApiServiceProvider"
```

Configure your API settings in `config/evolve-api.php`:

```php
return [
    'route_prefix' => 'api',
    'responses' => [
        'include_meta' => true,
        'wrap_response' => true,
    ],
    'docs' => [
        'enabled' => true,
        'route' => 'docs',
        'title' => 'API Documentation',
    ],
];
```

---

## Basic Usage

Make your models API-accessible by implementing the interface and using the trait:

```php
use Thinkneverland\Evolve\Core\Traits\Evolvable;
use Thinkneverland\Evolve\Core\Contracts\EvolveModelInterface;

class Post extends Model implements EvolveModelInterface
{
    use Evolvable;

    protected $fillable = ['title', 'content', 'status'];

    public function validationRules(string $action): array
    {
        return [
            'create' => [
                'title' => 'required|string|max:255',
                'content' => 'required|string',
                'status' => 'in:draft,published',
            ],
            'update' => [
                'title' => 'string|max:255',
                'content' => 'string',
                'status' => 'in:draft,published',
            ]
        ][$action] ?? [];
    }
}
```

---

## API Endpoints

### Listing Resources

Get a paginated list of resources:

```bash
GET /api/posts
```

Optional query parameters:

- `?page=1` - Page number.
- `?per_page=15` - Items per page.
- `?sort=-created_at` - Sort by creation date (descending).
- `?filter[status]=published` - Filter by status.

---

### Retrieving Resources

Get a single resource by ID:

```bash
GET /api/posts/1
```

Optional parameters:

- `?include=author,comments` - Include related resources.
- `?fields=id,title,content` - Select specific fields.

---

### Creating Resources

Create a new resource:

```bash
POST /api/posts
Content-Type: application/json

{
    "title": "New Post",
    "content": "This is the content",
    "status": "draft",
    "category_id": 1,
    "tags": [1, 2, 3]
}
```

---

### Updating Resources

Update an existing resource:

```bash
PUT /api/posts/2
Content-Type: application/json

{
    "title": "Updated Title",
    "status": "published"
}
```

---

### Deleting Resources

Delete a resource:

```bash
DELETE /api/posts/2
```

---

## Query Parameters

### Filtering

```bash
GET /api/posts?filter[status]=published
```

### Sorting

```bash
GET /api/posts?sort=-created_at
```

### Including Relations

```bash
GET /api/posts?include=author,comments
```

### Pagination

```bash
GET /api/posts?page=2&per_page=15
```

### Selecting Fields

```bash
GET /api/posts?fields=id,title,created_at
```

---

## Response Format

### Success Responses

```json
{
    "data": {},
    "meta": {
        "message": "Operation successful"
    }
}
```

### Error Responses

```json
{
    "error": {
        "type": "ValidationError",
        "message": "The given data was invalid.",
        "details": {
            "title": ["The title field is required."]
        }
    }
}
```

### Metadata

```json
{
    "meta": {
        "current_page": 1,
        "total_pages": 10,
        "api_version": "1.0"
    }
}
```

## Advanced Usage

### Custom Endpoints

Add custom endpoints by extending the base controller:

```php
use Thinkneverland\Evolve\Api\Http\Controllers\EvolveApiController;

class PostController extends EvolveApiController
{
   public function publish($id)
   {
       $post = Post::findOrFail($id);

       $this->authorize('publish', $post);

       $post->update(['status' => 'published']);

       return $this->respondWithResource($post, [
           'message' => 'Post published successfully'
       ]);
   }

   public function featured()
   {
       $posts = Post::featured()
           ->withOptimizedQueries()
           ->paginate();

       return $this->respondWithCollection($posts);
   }
}

// Register custom routes
Route::put('/api/posts/{id}/publish', [PostController::class, 'publish']);
Route::get('/api/posts/featured', [PostController::class, 'featured']);
```

### Request/Response Hooks

Customize request handling and response formatting:

```php
class PostController extends EvolveApiController
{
   protected function beforeCreate(Request $request, array $data)
   {
       $data['user_id'] = auth()->id();
       $data['slug'] = Str::slug($data['title']);
       return $data;
   }

   protected function afterUpdate(Request $request, $model)
   {
       Cache::tags('posts')->flush();
       event(new PostUpdated($model));
   }

   protected function transformResource($model)
   {
       $data = parent::transformResource($model);
       $data['read_time'] = $this->calculateReadTime($data['content']);
       return $data;
   }

   protected function addResponseMetadata($response)
   {
       return array_merge(parent::addResponseMetadata($response), [
           'last_updated' => Cache::get('posts.last_updated'),
           'total_posts' => Post::count()
       ]);
   }
}
```

### Custom Validation

Define custom validation logic per model:

```php
class Post extends Model implements EvolveModelInterface
{
   use Evolvable;

   public function validationRules(string $action): array
   {
       $rules = [
           'create' => [
               'title' => ['required', 'string', 'max:255', 'unique:posts'],
               'content' => ['required', 'string', 'min:100'],
               'status' => ['in:draft,published'],
           ],
           'update' => [
               'title' => ['string', 'max:255', Rule::unique('posts')->ignore($this->id)],
               'content' => ['string', 'min:100'],
               'status' => ['in:draft,published'],
           ]
       ];

       return $rules[$action] ?? [];
   }

   public function validationMessages(): array
   {
       return [
           'content.min' => 'Posts must be at least 100 characters long.',
       ];
   }
}
```

### Authorization

Implement fine-grained authorization policies:

```php
class PostPolicy
{
   public function viewAny(User $user): bool
   {
       return true;
   }

   public function view(User $user, Post $post): bool
   {
       return $post->status === 'published' || $user->id === $post->user_id;
   }

   public function create(User $user): bool
   {
       return $user->hasVerifiedEmail();
   }

   public function update(User $user, Post $post): bool
   {
       return $user->id === $post->user_id || $user->isAdmin();
   }

   public function delete(User $user, Post $post): bool
   {
       return $user->id === $post->user_id || $user->isAdmin();
   }

   public function publish(User $user, Post $post): bool
   {
       return $user->id === $post->user_id &&
              $user->hasVerifiedEmail() &&
              !$user->isBanned();
   }
}
```

These policies are automatically enforced by the API endpoints.
