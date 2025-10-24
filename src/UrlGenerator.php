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
	public function generate(Route $route, int|array|null $element = null, string $base_path = '/'): ?string
	{
		$urlSegments = [];
		$relationships = [];

		foreach ($route->segments as $segment) {
			if ($segment['type'] === 'static') {
				$urlSegments[] = $segment['value'];
			} else {
				$generated = $this->generateSegment($segment, $route, $element, $relationships);
				if ($generated === null)
					return null;

				$urlSegments[] = $generated;
			}
		}

		return $base_path . implode('/', $urlSegments);
	}

	/**
	 * Generate a single URL segment from dynamic fields
	 */
	private function generateSegment(array $segment, Route $route, int|array|null $element, array &$relationships): ?string
	{
		$parts = [];

		foreach ($segment['parts'] as $part) {
			$value = null;

			if ($part['type'] === 'static') {
				$value = $part['value'];
			} else if ($part['name'] === $route->options['id_field'] and $element !== null) {
				// Check if it's the ID field
				$value = is_numeric($element) ? (string)$element : ($element[$part['name']] ?? null);
			} elseif (is_array($element) and isset($element[$part['name']])) {
				// Check if we have the value in params
				$value = $element[$part['name']];
			} elseif ($part['relationships']) {
				// Check if it's a relationship field
				continue; // TODO
			} else {
				// Need to fetch from database
				$value = $this->fetchFieldValue($part, $route, $element);
			}

			if ($value === null)
				return null;

			$parts[] = $this->urlEncode($route, (string)$value);
		}

		return implode('-', $parts);
	}

	/**
	 * Resolve a relationship field value
	 */
	private function resolveRelationshipField(array $field, Route $route, int|array|null $element, array &$relationships): ?string
	{
		$relationshipName = $field['relationship'];

		// Check if we already resolved this relationship
		if (isset($relationships[$relationshipName]))
			return $relationships[$relationshipName][$field['name']] ?? null;

		// Get the relationship table
		if ($this->resolver === null)
			return null;

		$relationshipTable = $this->resolver->resolveRelationship($relationshipName);

		if ($relationshipTable === null)
			return null;

		// First, we need to get the relationship ID from the main entity
		if ($this->resolver === null or $route->options['table'] === null)
			return null;

		$mainRow = $this->fetchMainRow($route, $element);

		if ($mainRow === null)
			return null;

		// Get the foreign key field name (assume it's relationship name + _id)
		$foreignKeyField = $relationshipName . '_id';
		if (!isset($mainRow[$foreignKeyField]))
			$foreignKeyField = $relationshipName; // Try without _id suffix

		if (!isset($mainRow[$foreignKeyField]))
			return null;

		$relationshipId = $mainRow[$foreignKeyField];

		// Fetch the relationship row
		$relationshipRow = $this->resolver->select($relationshipTable, ['id' => $relationshipId]);

		if ($relationshipRow === null)
			return null;

		// Cache the relationship
		$relationships[$relationshipName] = $relationshipRow;

		return $relationshipRow[$field['name']] ?? null;
	}

	/**
	 * Fetch field value from database
	 */
	private function fetchFieldValue(array $field, Route $route, int|array|null $element): ?string
	{
		if ($element === null)
			return null;

		$row = is_array($element) ? $element : $this->fetchMainRow($route, $element);
		if ($row === null)
			return null;

		return $row[$field['name']] ?? null;
	}

	/**
	 * Fetch the main row from database (with caching)
	 */
	private function fetchMainRow(Route $route, int $id): ?array
	{
		if ($this->resolver === null or $route->options['table'] === null)
			return null;

		$cacheKey = $route->options['table'] . '_' . $id;
		if (isset($this->cache[$cacheKey]))
			return $this->cache[$cacheKey];

		$row = $this->resolver->select($route->options['table'], [$route->options['id_field'] => $id]);
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

