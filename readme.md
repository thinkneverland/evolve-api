
# EvolveAPI Documentation

## Navigation
- [Core](./evolve-core-documentation)
- [API](./evolve-api-documentation)
- [UI](./evolve-UI-documentation)

## EvolveAPI (Optional Free Extension)

### Quick Links
- [Getting Started](#getting-started)
- [API Reference](#api-reference)
- [Advanced Usage](#advanced)

## Contents
1. [Getting Started](#getting-started)
    - [Requirements](#requirements)
    - [Installation](#installation)
    - [Configuration](#configuration)
2. [Model Setup](#model-setup)
    - [Basic Model](#basic-model)
    - [Advanced Features](#advanced-model)
3. [API Reference](#api-reference)
    - [Endpoints](#endpoints)
    - [Parameters](#parameters)
    - [Responses](#responses)
4. [Advanced Usage](#advanced)
    - [Hook Methods](#hooks)
    - [Events](#events)
    - [Complex Examples](#examples)

---

## Getting Started

### 1.1 Requirements

- PHP `^7.4|^8.0`
- Laravel `^8.0|^9.0`
- `thinkneverland/evolve-core`
- `darkaonline/l5-swagger ^8.0`

---

### 1.2 Installation

Install the EvolveAPI package using Composer:

```sh
composer require thinkneverland/evolve-api
```

The service provider will be automatically registered. To publish the package assets, use the following command:

```sh
php artisan vendor:publish --provider="Thinkneverland\Evolve\Api\Providers\EvolveApiServiceProvider"
```

---

### 1.3 Configuration

Update the configuration file located at `config/evolve-api.php`:

```php
return [
    'route_prefix' => 'evolve-api',    // API route prefix
    'middleware' => ['api'],           // Add custom middleware
    'avoid_duplicates' => false,       // Global duplicate prevention
];
```


## Model Setup

### 2.1 Basic Model

Set up your model with the minimum required configuration:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Thinkneverland\Evolve\Core\Traits\SortableFilterableTrait;

class Product extends Model
{
    use SortableFilterableTrait;

    protected $fillable = [
        'name',
        'price',
        'description'
    ];

    protected $filterable = [
        'name',
        'price'
    ];

    protected $sortable = [
        'name',
        'price',
        'created_at'
    ];
}
```

---

### 2.2 Advanced Features

Enhance your model with advanced features:

```php
class Product extends Model
{
    use SortableFilterableTrait;

    protected $perPage = 25;

    // Define unique fields for duplicate prevention
    public static function uniqueFields()
    {
        return ['sku', 'name'];
    }

    // Define validation rules
    public static function getValidationRules($action = 'create', $model = null)
    {
        return [
            'name' => 'required|string|max:255',
            'price' => 'required|numeric|min:0',
            'category_id' => 'required|exists:categories,id'
        ];
    }

    // Define relations
    public function category()
    {
        return $this->belongsTo(Category::class);
    }

    public function tags()
    {
        return $this->belongsToMany(Tag::class);
    }
}
```

---

## API Reference

### 3.1 Endpoints

| Method   | Endpoint                    | Description          |
|----------|-----------------------------|----------------------|
| `GET`    | `/evolve-api/{model}`       | List resources       |
| `POST`   | `/evolve-api/{model}`       | Create resource      |
| `GET`    | `/evolve-api/{model}/{id}`  | Get single resource  |
| `PUT`    | `/evolve-api/{model}/{id}`  | Update resource      |
| `DELETE` | `/evolve-api/{model}/{id}`  | Delete resource      |

---

### 3.2 Parameters

#### Filtering

```sh
# Basic filter
GET /evolve-api/products?filter[price]=100

# Operator filter
GET /evolve-api/products?filter[price][gt]=100

# Multiple filters
GET /evolve-api/products?filter[price][gt]=100&filter[category]=electronics

# Relation filter
GET /evolve-api/products?filter[category.name]=Electronics
```

#### Sorting

```sh
# Single sort
GET /evolve-api/products?sort=price

# Descending sort
GET /evolve-api/products?sort=-price

# Multiple sort
GET /evolve-api/products?sort=-price,name
```

#### Pagination

```sh
GET /evolve-api/products?page=1&per_page=25
```

---

### 3.3 Responses

Example successful response:

```json
{
    "success": true,
    "data": {
        "id": 1,
        "name": "Example Product",
        "price": 99.99,
        "created_at": "2024-11-14T10:00:00.000000Z",
        "updated_at": "2024-11-14T10:00:00.000000Z"
    },
    "message": "Resource created successfully"
}
```


## Advanced Usage

### 4.1 Hook Methods

Create a controller to implement custom hooks:

```php
namespace App\Http\Controllers\Api;

class ProductController
{
    public function beforeUpdate($request, &$data, $model)
    {
        // Modify data before update
        $data['modified_by'] = auth()->id();
    }

    public function afterUpdate($request, $model)
    {
        // Perform actions after update
        Cache::tags('products')->flush();
    }

    public function beforeDelete($request, $model)
    {
        if ($model->has_active_orders) {
            return response()->json([
                'error' => 'Cannot delete product with active orders'
            ], 422);
        }
    }
}
```

---

### 4.2 Events

- `EvolveModelCreated` - Dispatched after model creation.
- `EvolveModelUpdated` - Dispatched after model update.
- `EvolveModelDeleted` - Dispatched after model deletion.

---

### 4.3 Complex Examples

#### Create with Relations

```json
POST /evolve-api/products
{
    "name": "Premium Laptop",
    "price": 1299.99,
    "category": {
        "name": "Electronics",
        "slug": "electronics"
    },
    "tags": [
        {"name": "Featured"},
        {"name": "New Arrival"}
    ],
    "specifications": [
        {
            "name": "RAM",
            "value": "16GB"
        },
        {
            "name": "Storage",
            "value": "512GB SSD"
        }
    ]
}
```

#### Update with Duplicate Prevention

```json
PUT /evolve-api/products/1?avoid_duplicates=true
{
    "name": "Updated Product Name",
    "variants": [
        {
            "sku": "PROD-1-VAR-1",
            "price": 99.99,
            "attributes": [
                {
                    "name": "Color",
                    "value": "Blue"
                }
            ]
        }
    ]
}
```

