<?php namespace Model\Router\Tests\Fakes;

use Model\Router\ResolverInterface;

/**
 * In-memory ResolverInterface for unit tests.
 *
 * Tables are a plain array<string, array<int, array<string, mixed>>>.
 * String filter values are matched LIKE-style: `-` is treated as `%`, and
 * `%` becomes a lazy wildcard, mirroring ModElResolver's translation. When
 * multiple string filters match, the shortest stored value wins — matching
 * ModElResolver's `ORDER BY LENGTH(field)` heuristic.
 */
class FakeResolver implements ResolverInterface
{
	/** @var array<string, array<int, array<string, mixed>>> */
	private array $tables;

	public int $fetchCalls = 0;

	public function __construct(array $tables = [])
	{
		$this->tables = $tables;
	}

	public function parseEntity(string|array $entity): ?array
	{
		if (is_string($entity))
			$entity = ['table' => $entity];
		$entity['primary'] ??= 'id';
		return $entity;
	}

	public function getPrimary(array $entity): string
	{
		return $entity['primary'];
	}

	public function fetch(array $entity, ?int $id, array $filters = []): ?array
	{
		$this->fetchCalls++;
		$rows = $this->tables[$entity['table']] ?? [];

		if ($id !== null)
			$rows = array_filter($rows, fn($r) => isset($r[$entity['primary']]) and $r[$entity['primary']] == $id);

		$stringFilters = [];
		foreach (($filters['filters'] ?? []) as $field => $value) {
			$rows = array_filter($rows, fn($r) => $this->matchValue($r[$field] ?? null, $value));
			if (is_string($value) and !ctype_digit($value))
				$stringFilters[] = $field;
		}

		$rows = array_values($rows);
		if (count($rows) === 0)
			return null;

		// Mimic ORDER BY LENGTH(field) on the first string filter
		if (count($stringFilters) > 0) {
			$orderField = $stringFilters[0];
			usort($rows, fn($a, $b) => strlen((string)($a[$orderField] ?? '')) <=> strlen((string)($b[$orderField] ?? '')));
		}

		return $rows[0];
	}

	private function matchValue(mixed $rowValue, mixed $filterValue): bool
	{
		if ($rowValue === null)
			return false;

		if (is_int($filterValue) or (is_string($filterValue) and ctype_digit($filterValue)))
			return (string)$rowValue === (string)$filterValue;

		$pattern = str_replace('-', '%', (string)$filterValue);
		$regex = '/^' . str_replace('%', '.*?', preg_quote($pattern, '/')) . '.*$/iu';
		return (bool)preg_match($regex, (string)$rowValue);
	}

	public function parseRelationshipForMatch(array $entity, array $relationship): ?array
	{
		throw new \RuntimeException('FakeResolver::parseRelationshipForMatch not implemented');
	}

	public function mergeQueryFilters(array ...$filters): array
	{
		$merged = ['filters' => [], 'joins' => []];
		foreach ($filters as $set) {
			if (!empty($set['filters']))
				$merged['filters'] = array_merge($merged['filters'], $set['filters']);
			if (!empty($set['joins']))
				$merged['joins'] = array_merge($merged['joins'], $set['joins']);
		}
		return $merged;
	}

	public function resolveRelationshipForGeneration(array $entity, array|int $row, array $relationship): ?string
	{
		throw new \RuntimeException('FakeResolver::resolveRelationshipForGeneration not implemented');
	}
}
