<?php namespace Model\Router;

class UrlGenerator
{
	private $dbResolver = null;
	private $relationshipResolver = null;
	private array $cache = [];

	public function __construct(?callable $dbResolver = null, ?callable $relationshipResolver = null)
	{
		$this->dbResolver = $dbResolver;
		$this->relationshipResolver = $relationshipResolver;
	}

	/**
	 * Generate a URL for a given route with parameters
	 */
	public function generate(Route $route, ?int $id = null, array $params = []): ?string
	{
		$urlSegments = [];
		$relationships = [];

		foreach ($route->segments as $segment) {
			if ($segment['type'] === 'static') {
				$urlSegments[] = $segment['value'];
			} else {
				$generated = $this->generateSegment($segment, $route, $id, $params, $relationships);
				
				if ($generated === null)
					return null;

				$urlSegments[] = $generated;
			}
		}

		return '/' . implode('/', $urlSegments);
	}

	/**
	 * Generate a single URL segment from dynamic fields
	 */
	private function generateSegment(array $segment, Route $route, ?int $id, array $params, array &$relationships): ?string
	{
		$fields = $segment['fields'];
		$parts = [];

		foreach ($fields as $field) {
			$value = null;

			// Check if it's the ID field
			if ($field['name'] === $route->options['id_field'] and $id !== null) {
				$value = $id;
			}
			// Check if we have the value in params
			elseif (isset($params[$field['name']])) {
				$value = $params[$field['name']];
			}
			// Check if it's a relationship field
			elseif ($field['relationship'] !== null) {
				$value = $this->resolveRelationshipField($field, $route, $id, $params, $relationships);
			}
			// Need to fetch from database
			else {
				$value = $this->fetchFieldValue($field, $route, $id, $params);
			}

			if ($value === null)
				return null;

			// URL encode the value
			if ($route->options['lowercase'] and is_string($value))
				$value = $this->urlEncode($value);

			$parts[] = $value;
		}

		return implode('-', $parts);
	}

	/**
	 * Resolve a relationship field value
	 */
	private function resolveRelationshipField(array $field, Route $route, ?int $id, array $params, array &$relationships): ?string
	{
		$relationshipName = $field['relationship'];

		// Check if we already resolved this relationship
		if (isset($relationships[$relationshipName]))
			return $relationships[$relationshipName][$field['field']] ?? null;

		// Get the relationship table
		if ($this->relationshipResolver === null)
			return null;

		$relationshipTable = call_user_func($this->relationshipResolver, $relationshipName);
		
		if ($relationshipTable === null)
			return null;

		// First, we need to get the relationship ID from the main entity
		if ($this->dbResolver === null or $route->options['table'] === null)
			return null;

		$mainRow = $this->fetchMainRow($route, $id, $params);
		
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
		$relationshipRow = call_user_func($this->dbResolver, $relationshipTable, ['id' => $relationshipId]);
		
		if ($relationshipRow === null)
			return null;

		// Cache the relationship
		$relationships[$relationshipName] = $relationshipRow;

		return $relationshipRow[$field['field']] ?? null;
	}

	/**
	 * Fetch field value from database
	 */
	private function fetchFieldValue(array $field, Route $route, ?int $id, array $params): ?string
	{
		if ($this->dbResolver === null or $route->options['table'] === null or $id === null)
			return null;

		$row = $this->fetchMainRow($route, $id, $params);
		
		if ($row === null)
			return null;

		return $row[$field['name']] ?? null;
	}

	/**
	 * Fetch the main row from database (with caching)
	 */
	private function fetchMainRow(Route $route, ?int $id, array $params): ?array
	{
		if ($id === null)
			return null;

		$cacheKey = $route->options['table'] . '_' . $id;
		
		if (isset($this->cache[$cacheKey]))
			return $this->cache[$cacheKey];

		$row = call_user_func($this->dbResolver, $route->options['table'], [$route->options['id_field'] => $id]);
		
		if ($row !== null)
			$this->cache[$cacheKey] = $row;

		return $row;
	}

	/**
	 * URL encode a string for use in URLs
	 */
	private function urlEncode(string $value): string
	{
		// Convert to lowercase and replace spaces with dashes
		$value = mb_strtolower($value);
		$value = preg_replace('/\s+/', '-', $value);
		
		// Remove special characters except dashes and underscores
		$value = preg_replace('/[^a-z0-9а-я\p{Han}_-]/iu', '', $value);
		
		// Remove multiple consecutive dashes
		$value = preg_replace('/-+/', '-', $value);
		
		// Trim dashes from ends
		return trim($value, '-');
	}
}

