# Modern Router

A standalone, framework-agnostic PHP router with bidirectional routing support, multi-field URL segments, and relationship resolution.

## Features

- **Modern route syntax** with `:parameter` notation
- **Multi-field segments**: `/users/:name-:surname`
- **Relationship fields**: `/products/:category.name/:id-:name`
- **Bidirectional routing**: Parse URLs and generate URLs
- **Database integration** via dependency injection
- **Framework-agnostic**: Works as a standalone package

## Installation

The router is designed as a standalone package. You can use install it via Composer:

```bash
composer require model/router
```

## Basic Usage

### 1. Create Router providers

```php
use \Model\Router\AbstractRouterProvider;

class RouterProvider extends AbstractRouterProvider {
    public function getRoutes(): void {
        return [
            [
                'pattern' => '/pages/:name',
                'controller' => 'PageController',
                'options' => [
                    'table' => 'pages',
                    'id_field' => 'id',
                ],
            ],
            [
                'pattern' => '/users/:name-:surname',
                'controller' => 'UserController',
                'options' => [
                    'table' => 'users',
                    'id_field' => 'id',
                ],
            ],
        ];
    }
}

### 2. Create Router

```php
use Model\Router\Router;

// If your route do not use database lookups
$router = new Router();

// If they use database lookups, provide a Resolver instance
$resolver = new YourDatabaseResolver(); // Implement the Resolver interface
$router = new Router($resolver);
```

### 3. Match Incoming URLs

```php
$url = '/pages/about-us';
$result = $router->match($url);

if ($result) {
	$controller = $result['controller']; // 'PageController'
	$params = $result['params']; // ['id' => 5] (from database lookup)
}
```

### 4. Generate URLs

```php
// Generate URL with ID
$url = $router->generate('PageController', 5);
// Result: /pages/about-us

// Generate URL with explicit parameters
$url = $router->generate('UserController', null, [
	'name' => 'John',
	'surname' => 'Doe',
]);
// Result: /users/john-doe
```

## Route Syntax

### Simple Parameters

```php
$router->addRoute('/pages/:name', 'PageController', [
	'table' => 'pages',
]);
```

URL: `/pages/about-us` → Looks up page with `name = 'about-us'`

### Numeric IDs

```php
$router->addRoute('/pages/:id', 'PageController', [
	'table' => 'pages',
	'id_field' => 'id',
]);
```

URL: `/pages/123` → Directly matches ID 123

### Multiple Fields in One Segment

```php
$router->addRoute('/users/:name-:surname', 'UserController', [
	'table' => 'users',
]);
```

URL: `/users/john-doe-smith` → Tries combinations:
- `name='john'` AND `surname='doe-smith'`
- `name='john-doe'` AND `surname='smith'`

### Relationship Fields

```php
$router->addRoute('/products/:category.name/:id-:name', 'ProductController', [
	'table' => 'products',
	'id_field' => 'id',
]);
```

URL: `/products/electronics/123-laptop` → Looks up:
1. Category with `name = 'electronics'`
2. Product with `id = 123` AND `name = 'laptop'`

## Route Options

### Available Options

- `table` (string): Database table for lookups
- `id_field` (string): Primary key field name (default: 'id')
- `relationships` (array): Relationship configuration
- `case_sensitive` (bool): Case-sensitive matching (default: false)
- `tags` (array): Additional metadata for route filtering
- `lowercase` (bool): Convert generated URLs to lowercase (default: true)

### Example with All Options

```php
$router->addRoute('/blog/:category.name/:id-:slug', 'BlogController', [
	'table' => 'blog_posts',
	'id_field' => 'id',
	'relationships' => [
		'category' => [
			'table' => 'blog_categories',
			'foreign_key' => 'category_id',
		],
	],
	'case_sensitive' => false,
	'tags' => [
		'lang' => 'en',
		'type' => 'public',
	],
	'lowercase' => true,
]);
```

## Adding Multiple Routes

Use `addRoutes()` to add multiple routes from a configuration array:

```php
$router->addRoutes([
	[
		'pattern' => '/pages/:name',
		'controller' => 'PageController',
		'options' => ['table' => 'pages'],
	],
	[
		'pattern' => '/users/:id',
		'controller' => 'UserController',
		'options' => ['table' => 'users'],
	],
]);
```

## URL Generation with Tags

Generate URLs for specific route variants using tags:

```php
// Add routes with tags
$router->addRoute('/pages/:name', 'PageController', [
	'table' => 'pages',
	'tags' => ['lang' => 'en'],
]);

$router->addRoute('/pagine/:name', 'PageController', [
	'table' => 'pages',
	'tags' => ['lang' => 'it'],
]);

// Generate for specific language
$url = $router->generate('PageController', 5, [], ['lang' => 'en']);
// Result: /pages/about-us

$url = $router->generate('PageController', 5, [], ['lang' => 'it']);
// Result: /pagine/chi-siamo
```

## Advanced Features

### Custom URL Encoding

The router automatically converts field values to URL-friendly format:
- Converts to lowercase
- Replaces spaces with dashes
- Removes special characters
- Supports Unicode characters (Cyrillic, Chinese, etc.)

### Caching

The UrlGenerator caches database lookups during URL generation to minimize queries.

### Combination Algorithm

For multi-field segments, the router generates all possible word distributions and tries each until finding a match. This is the same algorithm as the original router.

## Requirements

- PHP 8.1 or higher
- No external dependencies

## License

MIT


