<?php namespace Model\Router;

class UrlMatcher
{
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

		// Quick check: segment count
		if (count($urlSegments) < count($route->segments))
			return null;
		if ($route->options['strict'] and count($urlSegments) > count($route->segments))
			return null;

		// Quick regex check
		if (!preg_match($route->regex, $url))
			return null;

		$hasDynamicDirectSegment = false;
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
				$count_dynamic = 0;
				foreach ($segment['parts'] as $part) {
					if ($part['type'] !== 'field')
						continue;

					$count_dynamic++;
					if (count($part['relationships'] ?? []) > 0) {
						// Collect relationships for later use
						$value = preg_replace('/^' . $segment['regex'] . '$/', '$' . $count_dynamic, $urlSegment);
						$relationships[] = [
							...$part,
							'value' => $value,
						];
					} else {
						$hasDynamicDirectSegment = true;
						if ($part['name'] === $this->resolver->getPrimary($route->options['entity'])) { // If there is the id field, try to extract it directly
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
		}

		if (!$id and $hasDynamicDirectSegment) {
			$filters = [];

			if (count($relationships) > 0) {
				// Build joins and filters for relationships
				foreach ($relationships as $rel) {
					$relFilters = $this->resolver->parseRelationshipForMatch($route->options['entity'], $rel);
					if ($relFilters === null)
						return null;

					$filters = $this->resolver->mergeQueryFilters($filters, $relFilters);
				}
			}

			// Collect per-segment candidate filter combinations; each segment contributes
			// one or more alternatives that will be cross-producted below.
			$segmentCandidates = [];

			foreach ($route->segments as $index => $segment) {
				if ($segment['type'] === 'static')
					continue;

				$urlSegment = $urlSegments[$index];

				$fields = [];
				$cleanedUrlSegment = explode('-', $urlSegment);
				foreach ($segment['parts'] as $part_idx => $part) {
					if (count($part['relationships'] ?? []) > 0)
						continue;

					if ($part['type'] === 'field')
						$fields[] = $part;
					elseif (count($fields) === 0)
						unset($cleanedUrlSegment[$part_idx]); // Remove initial static parts from consideration
				}

				$urlSegment = implode('-', $cleanedUrlSegment);

				if (count($fields) === 0)
					continue; // Fully relationship-driven segment — already folded into $filters

				// Strip suffix from last field if present (e.g., ".csv" from "john-doe.csv")
				$lastField = end($fields);
				if (!empty($lastField['suffix'])) {
					$suffix = preg_quote($lastField['suffix'], '/');
					$urlSegment = preg_replace('/' . $suffix . '$/', '', $urlSegment);
				}

				$words = explode('-', $urlSegment);
				if (count($words) < count($fields))
					return null;

				$combinations = $this->generateFieldCombinations($words, $fields);
				if (count($combinations) === 0)
					return null;

				$segmentCandidates[] = $combinations;
			}

			if ($this->resolver === null or !$route->options['entity'])
				return null;

			// Cross-product across segments; first combined filter set that fetches a row wins.
			foreach ($this->crossProductCandidates($segmentCandidates) as $combined) {
				$combinationFilters = $this->resolver->mergeQueryFilters(
					$filters,
					['filters' => $combined],
				);

				$row = $this->resolver->fetch($route->options['entity'], null, $combinationFilters);
				if ($row !== null) {
					$id = $row[$this->resolver->getPrimary($route->options['entity'])];
					break;
				}
			}

			if (!$id)
				return null;
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
	 * Cross-product of per-segment candidate lists. Each input list holds
	 * associative arrays of [field => value]; the generator yields merged
	 * associative arrays, one per combination across all segments.
	 */
	private function crossProductCandidates(array $lists): iterable
	{
		if (count($lists) === 0) {
			yield [];
			return;
		}

		if (count($lists) === 1) {
			yield from $lists[0];
			return;
		}

		$first = array_shift($lists);
		foreach ($first as $candidate) {
			foreach ($this->crossProductCandidates($lists) as $rest)
				yield array_merge($candidate, $rest);
		}
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
