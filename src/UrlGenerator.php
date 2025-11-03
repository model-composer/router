<?php namespace Model\Router;

class UrlGenerator
{
	private array $cache = [];

	public function __construct(private ?ResolverInterface $resolver = null)
	{
	}

	/**
	 * Generate a URL for a given route with parameters
	 */
	public function generate(Route $route, int|array|null $element = null): ?string
	{
		$urlSegments = [];
		$relationships = [];
		$main_row = null;

		foreach ($route->segments as $segment) {
			if ($segment['type'] === 'static') {
				$urlSegments[] = $segment['value'];
			} else {
				$generated = $this->generateSegment($segment, $route, $element, $relationships, $main_row);
				if ($generated === null)
					return null;

				$urlSegments[] = $generated;
			}
		}

		$url = implode('/', $urlSegments);

		if ($relationships) {
			if (!$main_row)
				return null;

			// Resolve relationships
			foreach ($relationships as $idx => $relationship) {
				$resolved = $this->resolver->resolveRelationshipForGeneration($route->options['entity'], $main_row, $relationship);
				$url = str_replace('//rel' . $idx . '//', $this->urlEncode($route, $resolved), $url);
			}
		}

		return $url;
	}

	/**
	 * Generate a single URL segment from dynamic fields
	 */
	private function generateSegment(array $segment, Route $route, int|array|null $element, array &$relationships, array|int|null &$main_row): ?string
	{
		$parts = [];

		foreach ($segment['parts'] as $part) {
			$value = null;
			$encode = true;

			if ($part['type'] === 'static') {
				$value = $part['value'];
			} else if ($part['name'] === $this->resolver->getPrimary($route->options['entity']) and $element !== null) {
				// Check if it's the ID field
				$value = is_numeric($element) ? (string)$element : ($element[$part['name']] ?? null);
				if ($main_row === null)
					$value = $element;
			} elseif (is_array($element) and isset($element[$part['name']])) {
				// Check if we have the value in params
				$value = $element[$part['name']];
			} elseif ($part['relationships']) {
				// If it's a relationship field, add it to the relationships to resolve
				$value = '//rel' . count($relationships) . '//'; // Placeholder
				$encode = false;
				$relationships[] = $part;
			} elseif ($element !== null) {
				// Need to fetch from database
				$row = is_array($element) ? $element : $this->fetchMainRow($route, $element);
				if ($row === null)
					return null;

				if ($main_row === null or is_numeric($main_row))
					$main_row = $row;

				$value = $row[$part['name']] ?? null;
			}

			if ($value === null)
				return null;

			$parts[] = $encode ? $this->urlEncode($route, (string)$value) : (string)$value;
		}

		return implode('-', $parts);
	}

	/**
	 * Fetch the main row from database (with caching)
	 */
	private function fetchMainRow(Route $route, int $id): ?array
	{
		if ($this->resolver === null or !$route->options['entity'])
			return null;

		$cacheKey = json_encode($route->options['entity']) . '_' . $id;
		if (isset($this->cache[$cacheKey]))
			return $this->cache[$cacheKey];

		$row = $this->resolver->fetch($route->options['entity'], $id);
		if ($row !== null)
			$this->cache[$cacheKey] = $row;

		return $row;
	}

	/**
	 * URL encode a string for use in URLs
	 */
	private function urlEncode(Route $route, string $value): string
	{
		// Convert to lowercase
		if ($route->options['lowercase'])
			$value = mb_strtolower($value);

		// Replace whitespace with dashes
		$value = preg_replace('/\s+/', '-', $value);

		// Remove special characters except dashes and underscores
		$value = preg_replace('/[^a-z0-9а-я\p{Han}_-]/iu', '', $value);

		// Remove multiple consecutive dashes
		$value = preg_replace('/-+/', '-', $value);

		// Trim dashes from ends
		return trim($value, '-');
	}
}
