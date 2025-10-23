<?php namespace Model\Router;

class Route
{
	public string $pattern;
	public string $controller;
	public array $segments = [];
	public array $options;

	public function __construct(string $pattern, string $controller, array $options = [])
	{
		$this->pattern = $pattern;
		$this->controller = $controller;
		$this->options = array_merge([
			'table' => null,
			'id_field' => 'id',
			'relationships' => [],
			'case_sensitive' => true,
			'tags' => [],
			'lowercase' => true,
		], $options);

		$this->parsePattern();
	}

	/**
	 * Parse the route pattern into segments
	 */
	private function parsePattern(): void
	{
		$parts = explode('/', trim($this->pattern, '/'));
		foreach ($parts as $part) {
			if (empty($part))
				continue;

			$segment = [
				'type' => 'static',
				'value' => $part,
				'parts' => [],
			];

			// Check if this is a dynamic segment
			if (str_contains($part, ':')) {
				$segment['type'] = 'dynamic';

				// Parse multiple fields separated by dashes (e.g., :name-:surname)
				$fieldParts = explode('-', $segment['value']);

				foreach ($fieldParts as $fieldPart) {
					if (str_starts_with($fieldPart, ':')) {
						// Check for relationship notation (e.g., category.name)
						if (str_contains($fieldPart, '.')) {
							$relationships = explode('.', $fieldPart, 2);
							$field = array_pop($relationships);
							$segment['parts'][] = [
								'type' => 'field',
								'name' => $fieldPart,
								'relationships' => $relationships,
								'field' => $field,
							];
						} else {
							$segment['parts'][] = [
								'type' => 'field',
								'name' => $fieldPart,
								'relationships' => [],
								'field' => $fieldPart,
							];
						}
					} else {
						$segment['parts'][] = [
							'type' => 'static',
							'value' => $fieldPart,
						];
					}
				}
			}

			$this->segments[] = $segment;
		}
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
