<?php namespace Model\Router;

use Model\Cache\Cache;
use Model\ProvidersFinder\Providers;

class Router
{
	/** @var Route[] */
	private array $routes = [];
	private bool $routesLoaded = false;

	private ?UrlMatcher $matcher = null;
	private ?UrlGenerator $generator = null;

	public function __construct(private ?ResolverInterface $resolver = null)
	{
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
					foreach ($providerRoutes as $route)
						$this->addRoute(new Route($route['pattern'], $route['controller'], $route['options'] ?? []));
				}

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
	 * Add a route internally, checking for duplicates
	 */
	private function addRoute(Route $route): void
	{
		foreach ($this->routes as $existingRoute) {
			if ($route->regex === $existingRoute->regex)
				throw new \Exception('Route pattern already exists: ' . $route->pattern);
		}

		$this->routes[] = $route;
	}

	/**
	 * Match a URL to a route
	 * Returns ['controller' => string, 'params' => array] or null
	 */
	public function match(string $url): ?array
	{
		$matcher = $this->getMatcher();

		foreach ($this->routes as $route) {
			$result = $matcher->match($url, $route);
			if ($result !== null)
				return $result;
		}

		return null;
	}

	/**
	 * Generate a URL for a controller with parameters
	 */
	public function generate(string $controller, int|array|null $element = null, array $tags = []): ?string
	{
		$generator = $this->getGenerator();

		// Find matching routes for this controller
		$matchingRoutes = $this->getRoutesForController($controller, $tags);

		foreach ($matchingRoutes as $route) {
			$url = $generator->generate($route, $element);
			if ($url !== null)
				return $url;
		}

		return null;
	}

	/**
	 * Get all routes matching a controller and optional tags
	 */
	public function getRoutesForController(string $controller, array $tags = []): array
	{
		$matching = [];
		foreach ($this->routes as $route) {
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

