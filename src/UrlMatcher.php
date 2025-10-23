<?php namespace Model\Router;

class UrlMatcher
{
	private string $acceptableCharacters = 'a-zа-я0-9_\p{Han}-';
	private $dbResolver = null;
	private $relationshipResolver = null;

	public function __construct(?callable $dbResolver = null, ?callable $relationshipResolver = null)
	{
		$this->dbResolver = $dbResolver;
		$this->relationshipResolver = $relationshipResolver;
	}

	/**
	 * Try to match a URL against a route
	 * Returns array with controller and extracted parameters, or null if no match
	 */
	public function match(string $url, Route $route): ?array
	{
		$urlSegments = explode('/', trim($url, '/'));
		
		// Quick check: segment count must be greater or equal
		if (count($urlSegments) < count($route->segments))
			return null;

		$params = [];
		$relationships = [];

		// Match each segment
		foreach ($route->segments as $index => $segment) {
			$urlSegment = $urlSegments[$index];

			if ($segment['type'] === 'static') {
				// Static segment must match exactly
				$matches = $route->options['case_sensitive'] 
					? $urlSegment === $segment['value']
					: strcasecmp($urlSegment, $segment['value']) === 0;
				
				if (!$matches)
					return null;
			} else {
				// Dynamic segment - extract parameters
				$extracted = $this->extractFromSegment($urlSegment, $segment, $route, $relationships);
				if ($extracted === null)
					return null;

				$params = array_merge($params, $extracted);
			}
		}

		return [
			'controller' => $route->controller,
			'params' => $params,
		];
	}

	/**
	 * Extract parameters from a dynamic URL segment
	 */
	private function extractFromSegment(string $urlSegment, array $segment, Route $route, array &$relationships): ?array
	{
		$fields = $segment['fields'];
		
		// Check if this is a simple numeric ID
		// TODO: if there is the id, quick extraction
		if (count($fields) === 1 and $fields[0]['name'] === $route->options['id_field'] and is_numeric($urlSegment))
			return [$fields[0]['name'] => (int)$urlSegment];

		// Check for relationship fields
		$hasRelationships = false;
		foreach ($fields as $field) {
			if ($field['relationships'] !== null) {
				$hasRelationships = true;
				break;
			}
		}

		// If we have relationships, resolve them first
		if ($hasRelationships)
			return $this->extractWithRelationships($urlSegment, $fields, $route, $relationships);

		// Multiple fields in one segment - try combinations
		if (count($fields) > 1)
			return $this->extractMultipleFields($urlSegment, $fields, $route, $relationships);

		// Single field - look up in database
		return $this->extractSingleField($urlSegment, $fields[0], $route, $relationships);
	}

	/**
	 * Extract a single field from URL segment
	 */
	private function extractSingleField(string $urlSegment, array $field, Route $route, array $relationships): ?array
	{
		if ($this->dbResolver === null or $route->options['table'] === null)
			return null;

		// Build where clause
		$where = [$field['name'] => $urlSegment];
		
		// Add parent relationship constraints
		foreach ($relationships as $rel)
			$where = array_merge($where, $rel);

		$row = call_user_func($this->dbResolver, $route->options['table'], $where);
		
		if ($row === null)
			return null;

		return [$route->options['id_field'] => $row[$route->options['id_field']]];
	}

	/**
	 * Extract multiple fields from a single URL segment (e.g., john-doe)
	 */
	private function extractMultipleFields(string $urlSegment, array $fields, Route $route, array $relationships): ?array
	{
		if ($this->dbResolver === null or $route->options['table'] === null)
			return null;

		$words = explode('-', $urlSegment);
		
		if (count($words) < count($fields))
			return null;

		// Generate all possible combinations
		$combinations = $this->generateFieldCombinations($words, $fields);

		// Try each combination
		foreach ($combinations as $combination) {
			$where = [];
			foreach ($combination as $fieldName => $value) {
				// Use LIKE for partial matching
				$where[] = [$fieldName, 'LIKE', '%' . $value . '%'];
			}

			// Add parent relationship constraints
			foreach ($relationships as $rel)
				$where = array_merge($where, $rel);

			$row = call_user_func($this->dbResolver, $route->options['table'], $where);
			
			if ($row !== null)
				return [$route->options['id_field'] => $row[$route->options['id_field']]];
		}

		return null;
	}

	/**
	 * Extract fields when relationships are involved
	 */
	private function extractWithRelationships(string $urlSegment, array $fields, Route $route, array &$relationships): ?array
	{
		// For now, relationship fields are treated as simple lookups
		// The actual relationship resolution happens during URL generation
		// Here we just extract the value and store it
		
		if (count($fields) === 1) {
			$field = $fields[0];
			
			if ($field['relationships'] !== null) {
				// Look up the relationship
				if ($this->relationshipResolver === null)
					return null;

				$relationshipTable = call_user_func($this->relationshipResolver, $field['relationships']);
				
				if ($relationshipTable === null)
					return null;

				if ($this->dbResolver === null)
					return null;

				$row = call_user_func($this->dbResolver, $relationshipTable, [$field['field'] => $urlSegment]);
				
				if ($row === null)
					return null;

				// Store this relationship for child lookups
				$relationships[$field['relationships']] = $row;

				return [$field['relationships'] => $row];
			}
		}

		// Multiple fields with relationships - not yet implemented
		return null;
	}

	/**
	 * Generate all possible field combinations for multi-field segments
	 * Similar to the original createCombination and possibleCombinations methods
	 */
	private function generateFieldCombinations(array $words, array $fields): array
	{
		$fieldNames = array_map(fn($f) => $f['name'], $fields);
		
		$numFields = count($fieldNames);
		$numWords = count($words);

		// Shortcut: if words match fields exactly
		if ($numWords === $numFields) {
			$combination = [];
			foreach ($words as $i => $word)
				$combination[$fieldNames[$i]] = $word;
			return [$combination];
		}

		// Generate combination patterns
		$patterns = $this->createCombinationPatterns($numWords, $numFields);
		
		$combinations = [];
		foreach ($patterns as $pattern) {
			$wordsTemp = $words;
			$combination = [];
			
			foreach ($pattern as $fieldIdx => $wordCount) {
				$fieldWords = [];
				for ($i = 0; $i < $wordCount; $i++)
					$fieldWords[] = array_shift($wordsTemp);
				
				$combination[$fieldNames[$fieldIdx]] = implode('%', $fieldWords);
			}
			
			$combinations[] = $combination;
		}

		return $combinations;
	}

	/**
	 * Create combination patterns for distributing words among fields
	 */
	private function createCombinationPatterns(int $words, int $fields): array
	{
		if ($fields === 1)
			return [[$words]];

		$patterns = [];
		for ($i = 1; $i <= $words - $fields + 1; $i++) {
			$remaining = $words - $i;
			$subPatterns = $this->createCombinationPatterns($remaining, $fields - 1);
			
			foreach ($subPatterns as $subPattern)
				$patterns[] = array_merge([$i], $subPattern);
		}

		return $patterns;
	}
}

