<?php namespace Model\Router;

class Route
{
	public string $pattern;
	public string $controller;
	public array $segments = [];
	public array $options;
	public string $regex;

	public function __construct(string $pattern, string $controller, array $options = [])
	{
		$this->pattern = trim($pattern, '/');
		$this->controller = $controller;
		$this->options = array_merge([
			'entity' => null,
			'relationships' => [],
			'case_sensitive' => true,
			'lowercase' => true,
			'tags' => [],
		], $options);

		$this->parsePattern();
	}

	/**
	 * Parse the route pattern into segments
	 */
	private function parsePattern(): void
	{
		$parts = explode('/', $this->pattern);
		$regex = [];
		foreach ($parts as $part) {
			if (empty($part))
				continue;

			// Check if this is a dynamic segment
			if (str_contains($part, ':')) {
				$segment = [
					'type' => 'dynamic',
					'value' => $part,
					'parts' => [],
				];

				$regexParts = [];

				// Parse multiple fields separated by dashes (e.g., :name-:surname)
				// Handles escaped dots as well
				$part = str_replace(['\\:', '\\.'], ['-\\:', '-\\.'], $part);
				$fieldParts = explode('-', $part);

				foreach ($fieldParts as $fieldPart) {
					if (str_starts_with($fieldPart, ':')) {
						$fieldPart = substr($fieldPart, 1); // Remove leading ':'
						$parsed = $this->parseFieldPart($fieldPart);

						$segment['parts'][] = [
							'type' => 'field',
							'name' => $parsed['field'],
							'relationships' => $parsed['relationships'],
							'suffix' => $parsed['suffix'],
						];

						// Generate regex including the literal suffix
						if ($parsed['suffix'] !== '')
							$regexParts[] = '([^\/]+)' . preg_quote($parsed['suffix'], '/');
						else
							$regexParts[] = '([^\/]+)';
					} else {
						$fieldPart = str_replace(['-\\:', '-\\.'], [':', '.'], $fieldPart);

						$segment['parts'][] = [
							'type' => 'static',
							'value' => $fieldPart,
						];

						$regexParts[] = preg_quote($fieldPart, '/');
					}
				}

				$segment['regex'] = implode('-', $regexParts);
				$regex[] = $segment['regex'];
			} else {
				$segment = [
					'type' => 'static',
					'value' => $part,
					'parts' => [],
					'regex' => preg_quote($part, '/'),
				];

				$regex[] = preg_quote($part, '/');
			}

			$this->segments[] = $segment;
		}

		$this->regex = $regex ? '/^\\/?' . implode('\/', $regex) . '(\\/.*)?$/' : '/^\\/?$/';
		if ($this->options['case_sensitive'])
			$this->regex .= 'i';
	}

	/**
	 * Parse a field part handling escape sequences for literal dots
	 * Supports: :field, :rel.field, :field\.ext, :rel.field\.ext
	 */
	private function parseFieldPart(string $fieldPart): array
	{
		// Find first unescaped dot (relationship separator)
		$len = strlen($fieldPart);
		$unescapedDotPos = null;

		for ($i = 0; $i < $len; $i++) {
			if ($fieldPart[$i] === '.' && ($i === 0 || $fieldPart[$i - 1] !== '\\')) {
				$unescapedDotPos = $i;
				break;
			}
		}

		// Determine relationships
		if ($unescapedDotPos !== null) {
			$relationshipPart = substr($fieldPart, 0, $unescapedDotPos);
			$remainder = substr($fieldPart, $unescapedDotPos + 1);
			$relationships = [$relationshipPart];
		} else {
			$relationships = [];
			$remainder = $fieldPart;
		}

		// Parse literal suffix (escaped dots)
		$escapedDotPos = strpos($remainder, '\.');
		if ($escapedDotPos !== false) {
			$field = substr($remainder, 0, $escapedDotPos);
			$suffix = str_replace('\.', '.', substr($remainder, $escapedDotPos));
		} else {
			$field = $remainder;
			$suffix = '';
		}

		return [
			'relationships' => $relationships,
			'field' => $field,
			'suffix' => $suffix,
		];
	}

	/**
	 * Check if this route matches the given tags
	 */
	public function matchesTags(array $tags): bool
	{
		foreach ($tags as $key => $value) {
			if (!isset($this->options['tags'][$key]) or $this->options['tags'][$key] !== $value)
				return false;
		}
		return true;
	}
}
