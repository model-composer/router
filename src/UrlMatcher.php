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

		// First pass: look for exact id, if present, match static segments and collect relationships
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
				// Dynamic segment
				foreach ($segment['parts'] as $part) {
					if ($part['type'] !== 'field')
						continue;

					if (count($part['relationships'] ?? []) > 0) {
						// Collect relationships for later use
						$relationships[] = $part;
					} elseif ($part['name'] === $this->resolver->getPrimary($route->options['entity'])) { // If there is the id field, try to extract it directly
						// Look for id
						$id_check = $this->extractId($urlSegment, $segment, $part['name']);
						if ($id_check) {
							$id = $id_check;
							break 2; // ID found, skip further processing
						}
					}
				}
			}
		}

		if (!$id) {
			$joins = [];
			$filters = [];

			if (count($relationships) > 0) {
				// Build joins and filters for relationships
				foreach ($relationships as $rel) {
					$relInfo = $this->resolver->parseRelationshipForMatch($rel);
					if ($relInfo === null)
						return null;

					if ($relInfo['joins'] ?? [])
						$joins = [...$joins, ...$relInfo['joins']];
					if ($relInfo['filters'] ?? [])
						$filters = [...$filters, ...$relInfo['filters']];
				}
			}

			foreach ($route->segments as $index => $segment) {
				if ($segment['type'] === 'static')
					continue;

				// Dynamic segment

				$urlSegment = $urlSegments[$index];

				$fields = [];
				foreach ($segment['parts'] as $part) {
					if ($part['type'] === 'field')
						$fields[] = $part;
				}

				if (count($fields) === 0)
					return null;

				if (count($fields) === 1)
					$id = $this->extractSingleField($urlSegment, $fields[0], $route, $filters, $joins);
				else
					$id = $this->extractMultipleFields($urlSegment, $fields, $route, $filters, $joins);

				if (!$id)
					return null;
			}
		}

		return [
			'id' => $id,
		];
	}

	/**
	 * Extract ID field from URL segment
	 */
	private function extractId(string $urlSegment, array $segment, string $id_field): ?int
	{
		$regex = str_replace(':' . $id_field, '([0-9]+)', $segment['value']);
		$regex = preg_replace('/:[a-z0-9_]+/i', '.*', $regex);
		$id = preg_replace('/^' . $regex . '$/i', '$1', $urlSegment);
		return $id ? (int)$id : null;
	}

	/**
	 * Extract a single field from URL segment
	 */
	private function extractSingleField(string $urlSegment, array $field, Route $route, array $filters, array $joins): ?int
	{
		if ($this->resolver === null or !$route->options['entity'])
			return null;

		// Build where clause
		$filters[$field['name']] = ['LIKE', $this->parseSingleSegmentForQuery($urlSegment)];


		$row = $this->resolver->fetch($route->options['entity'], null, $filters, $joins);
		if ($row === null)
			return null;

		return $row[$this->resolver->getPrimary($route->options['entity'])];
	}

	/**
	 * Extract multiple fields from a single URL segment (e.g., john-doe)
	 */
	private function extractMultipleFields(string $urlSegment, array $fields, Route $route, array $filters, array $joins): ?int
	{
		if ($this->resolver === null or !$route->options['entity'])
			return null;

		$words = explode('-', $urlSegment);
		if (count($words) < count($fields))
			return null;

		// Generate all possible combinations
		$combinations = $this->generateFieldCombinations($words, $fields);

		// Try each combination
		foreach ($combinations as $combination) {
			$combination_filters = [...$filters];
			foreach ($combination as $fieldName => $value) {
				// Use LIKE for partial matching
				$combination_filters[$fieldName] = ['LIKE', '%' . $value . '%'];
			}

			$row = $this->resolver->fetch($route->options['entity'], null, $combination_filters, $joins);
			if ($row !== null)
				return $row[$this->resolver->getPrimary($route->options['entity'])];
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

	private function parseSingleSegmentForQuery(string $segment): string
	{
		return implode('%', explode('-', $segment));
	}
}
