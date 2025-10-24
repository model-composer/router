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
		$this->pattern = $pattern;
		$this->controller = $controller;
		$this->options = array_merge([
			'model' => null,
			'table' => null,
			'id_field' => 'id',
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
		$parts = explode('/', trim($this->pattern, '/'));
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
				$fieldParts = explode('-', $part);

				foreach ($fieldParts as $fieldPart) {
					if (str_starts_with($fieldPart, ':')) {
						$fieldPart = substr($fieldPart, 1); // Remove leading ':'
						// Check for relationship notation (e.g., category.name)
						if (str_contains($fieldPart, '.')) {
							$relationships = explode('.', $fieldPart, 2);
							$field = array_pop($relationships);
							$segment['parts'][] = [
								'type' => 'field',
								'name' => $field,
								'relationships' => $relationships,
							];
						} else {
							$segment['parts'][] = [
								'type' => 'field',
								'name' => $fieldPart,
								'relationships' => [],
							];
						}

						$regexParts[] = '([^\/]+)'; // Match any non-slash characters
					} else {
						$segment['parts'][] = [
							'type' => 'static',
							'value' => $fieldPart,
						];

						$regexParts[] = preg_quote($fieldPart, '/');
					}
				}

				$regex[] = implode('-', $regexParts);
			} else {
				$segment = [
					'type' => 'static',
					'value' => $part,
					'parts' => [],
				];

				$regex[] = preg_quote($part, '/');
			}

			$this->segments[] = $segment;
		}

		$this->regex = '/^\\/' . implode('\/', $regex) . '(\\/.*)?$/' . ($this->options['case_sensitive'] ? '' : 'i');
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

	/**
	 * Get all field names used in this route
	 */
	public function getFields(): array
	{
		$fields = [];
		foreach ($this->segments as $segment) {
			if ($segment['type'] === 'dynamic') {
				foreach ($segment['fields'] as $field)
					$fields[] = $field['name'];
			}
		}
		return $fields;
	}

	/**
	 * Get all relationships used in this route
	 */
	public function getRelationships(): array
	{
		$relationships = [];
		foreach ($this->segments as $segment) {
			if ($segment['type'] === 'dynamic') {
				foreach ($segment['fields'] as $field) {
					if ($field['relationship'] !== null and !in_array($field['relationship'], $relationships))
						$relationships[] = $field['relationship'];
				}
			}
		}
		return $relationships;
	}
}
