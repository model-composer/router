<?php namespace Model\Router;

class Router
{
	/** @var Route[] */
	private array $routes = [];

	private ?UrlMatcher $matcher = null;
	private ?UrlGenerator $generator = null;

	public function __construct(private ?ResolverInterface $resolver = null)
	{
	}

	/**
	 * Add a single route
	 */
	public function addRoute(string $pattern, string $controller, array $options = []): self
	{
		$this->routes[] = new Route($pattern, $controller, $options);
		return $this;
	}

	/**
	 * Add multiple routes from configuration array
	 * Format: [
	 *   ['pattern' => '/pages/:name', 'controller' => 'PageController', 'options' => [...]],
	 *   ...
	 * ]
	 */
	public function addRoutes(array $routes): self
	{
		foreach ($routes as $route) {
			$pattern = $route['pattern'] ?? '';
			$controller = $route['controller'] ?? '';
			$options = $route['options'] ?? [];

			if (!empty($pattern) and !empty($controller))
				$this->addRoute($pattern, $controller, $options);
		}
		return $this;
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
	public function generate(string $controller, ?int $id = null, array $params = [], array $tags = []): ?string
	{
		$generator = $this->getGenerator();

		// Find matching routes for this controller
		$matchingRoutes = $this->getRoutesForController($controller, $tags);

		foreach ($matchingRoutes as $route) {
			$url = $generator->generate($route, $id, $params);
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

	/**
	 * Get all registered routes
	 */
	public function getRoutes(): array
	{
		return $this->routes;
	}

	/**
	 * Clear all routes
	 */
	public function clearRoutes(): self
	{
		$this->routes = [];
		return $this;
	}
}

