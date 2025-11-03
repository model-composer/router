<?php namespace Model\Router;

use Model\Cache\Cache;
use Model\Config\Config;
use Model\ProvidersFinder\Providers;
use Model\Router\Events\UrlGenerate;

class Router
{
	/** @var Route[] */
	private array $routes = [];
	private bool $routesLoaded = false;
	public ?array $activeRoute = null;
	private array $options = [];
	private ?UrlMatcher $matcher = null;
	private ?UrlGenerator $generator = null;

	public function __construct(private ?ResolverInterface $resolver = null, array $options = [])
	{
		$this->options = [
			'base_path' => '/',
			...$options,
		];
	}

	public function getRoutes(): array
	{
		if (!$this->routesLoaded) {
			$cache = Cache::getCacheAdapter();

			$this->routes = $cache->get('model.router.routes', function (\Symfony\Contracts\Cache\ItemInterface $item) {
				$item->expiresAfter(3600 * 24);

				$routes = [];

				$providers = Providers::find('RouterProvider');
				foreach ($providers as $provider) {
					$providerRoutes = $provider['provider']::getRoutes();
					foreach ($providerRoutes as $route) {
						$options = $route['options'] ?? [];
						$options['tags']['provider'] = $provider['package'];
						$this->addRoute($routes, $route['pattern'], $route['controller'], $options);
					}
				}

				$config = Config::get('router');
				foreach (($config['routes'] ?? []) as $route)
					$this->addRoute($routes, $route['pattern'], $route['controller'], $route['options'] ?? []);

				// Sort routes: more specific first
				usort($routes, function (Route $a, Route $b) {
					if (count($a->segments) !== count($b->segments))
						return count($b->segments) <=> count($a->segments); // More segments first

					foreach ($a->segments as $idx => $segmentA) {
						$segmentB = $b->segments[$idx] ?? null;
						if ($segmentB === null)
							break; // b has fewer segments

						if ($segmentA['type'] === 'static' and $segmentB['type'] !== 'static')
							return -1;
						if ($segmentA['type'] !== 'static' and $segmentB['type'] === 'static')
							return 1;
					}

					return 0;
				});

				return $routes;
			});

			$this->routesLoaded = true;
		}

		return $this->routes;
	}

	/**
	 * Add a route to the array
	 */
	private function addRoute(array &$routes, string $pattern, string $controller, array $options = []): void
	{
		if ($this->resolver and !empty($options['entity']))
			$options['entity'] = $this->resolver->parseEntity($options['entity']);

		$route = new Route($pattern, $controller, $options);
		foreach ($routes as $existingRoute) {
			if ($route->regex === $existingRoute->regex)
				throw new \Exception('Route pattern already exists: ' . $route->pattern);
		}

		$routes[] = $route;
	}

	/**
	 * Match a URL to a route
	 * Returns ['controller' => string, 'params' => array] or null
	 */
	public function match(string $url, bool $setAsActive = false): ?array
	{
		$cache = Cache::getCacheAdapter();

		$providers = Providers::find('RouterProvider');
		foreach ($providers as $provider)
			$url = $provider['provider']::preMatchUrl($url);

		$result = $cache->get('model.router.matching.' . sha1($url), function (\Symfony\Contracts\Cache\ItemInterface $item) use ($url) {
			$item->expiresAfter(3600 * 24);

			$matcher = $this->getMatcher();

			foreach ($this->getRoutes() as $route) {
				$result = $matcher->match($url, $route);
				if ($result !== null) {
					return [
						...$result,
						'pattern' => $route->pattern,
						'controller' => $route->controller,
						'entity' => $route->options['entity'] ?? null,
						'tags' => $route->options['tags'] ?? [],
						'route' => $route,
					];
				}
			}

			return null;
		});

		if ($result and $setAsActive)
			$this->activeRoute = $result;

		return $result;
	}

	/**
	 * Generate a URL for a controller with parameters
	 */
	public function generate(string $controller, int|array|null $element = null, array $tags = [], array $options = []): ?string
	{
		\Model\Events\Events::dispatch(new UrlGenerate($controller, $element, $tags));

		$cache = Cache::getCacheAdapter();

		$url = $cache->get('model.router.route.' . $controller . '.' . json_encode($element) . '.' . json_encode($tags), function (\Symfony\Contracts\Cache\ItemInterface $item) use ($controller, $element, $tags) {
			$item->expiresAfter(3600 * 24);

			$generator = $this->getGenerator();

			// Find matching routes for this controller
			$matchingRoutes = $this->getRoutesForController($controller, $tags);
			foreach ($matchingRoutes as $route) {
				$url = $generator->generate($route, $element);
				if ($url !== null)
					return $url;
			}

			return null;
		});

		if ($url === null)
			return null;

		$providers = Providers::find('RouterProvider');
		foreach ($providers as $provider)
			$url = $provider['provider']::postGenerateUrl($url, $options);

		return $this->options['base_path'] . $url;
	}

	/**
	 * Get all routes matching a controller and optional tags
	 */
	public function getRoutesForController(string $controller, array $tags = []): array
	{
		$matching = [];
		foreach ($this->getRoutes() as $route) {
			if ($route->controller === $controller and $route->matchesTags($tags))
				$matching[] = $route;
		}

		return $matching;
	}

	/**
	 * Get the URL matcher instance (lazy initialization)
	 */
	private function getMatcher(): UrlMatcher
	{
		if ($this->matcher === null)
			$this->matcher = new UrlMatcher($this->resolver);

		return $this->matcher;
	}

	/**
	 * Get the URL generator instance (lazy initialization)
	 */
	private function getGenerator(): UrlGenerator
	{
		if ($this->generator === null)
			$this->generator = new UrlGenerator($this->resolver);

		return $this->generator;
	}
}
