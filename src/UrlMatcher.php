<?php namespace Model\Router;

class UrlMatcher
{
	private string $acceptableCharacters = 'a-zа-я0-9_\p{Han}-';

	public function __construct(private ?ResolverInterface $resolver = null)
	{
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

		// Quick regex check
		if (!preg_match($route->regex, $url))
			return null;

		$relationships = [];
		$id = null;

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
				$id = $this->extractFromSegment($urlSegment, $segment, $route, $relationships);
				if ($id === null)
					return null;
			}
		}

		return [
			'controller' => $route->controller,
			'id' => $id,
		];
	}

	/**
	 * Extract parameters from a dynamic URL segment
	 */
	private function extractFromSegment(string $urlSegment, array $segment, Route $route, array &$relationships): ?int
	{
		$parts = $segment['parts'];

		$fields = [];
		foreach ($parts as $part) {
			if ($part['type'] === 'field') {
				if (!$part['relationships'] and $part['name'] === $route->options['id_field']) // If there is the id field, extract it directly
					return $this->extractId($urlSegment, $part, $route);

				$fields[] = $part;
			}
		}

		// TODO: handle relationships

		// Multiple fields in one segment - try combinations
		if (count($fields) > 1)
			return $this->extractMultipleFields($urlSegment, $fields, $route, $relationships);

		// Single field - look up in database
		return $this->extractSingleField($urlSegment, $fields[0], $route, $relationships);
	}

	/**
	 * Extract a single field from URL segment
	 */
	private function extractSingleField(string $urlSegment, array $field, Route $route, array $relationships): ?int
	{
		if ($this->resolver === null or $route->options['table'] === null)
			return null;

		// Build where clause
		$where = [$field['name'] => $urlSegment];

		// Add parent relationship constraints
		foreach ($relationships as $rel)
			$where = array_merge($where, $rel);

		$row = $this->resolver->select($route->options['table'], $where);
		if ($row === null)
			return null;

		return $row[$route->options['id_field']];
	}

	/**
	 * Extract ID field from URL segment
	 */
	private function extractId(string $urlSegment, array $field, Route $route): ?int
	{
		$pattern = explode('-', $route->pattern);
		$words = explode('-', $urlSegment);
		foreach ($pattern as $idx => $part) {
			if (ltrim($part, ':') === $field['name'] and isset($words[$idx]) and is_numeric($words[$idx]))
				return $words[$idx];
		}

		return null;
	}

	/**
	 * Extract multiple fields from a single URL segment (e.g., john-doe)
	 */
	private function extractMultipleFields(string $urlSegment, array $fields, Route $route, array $relationships): ?int
	{
		if ($this->resolver === null or $route->options['table'] === null)
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

			$row = $this->resolver->select($route->options['table'], $where);
			if ($row !== null)
				return $row[$route->options['id_field']];
		}

		return null;
	}

	/**
	 * Generate all possible field combinations for multi-field segments
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

