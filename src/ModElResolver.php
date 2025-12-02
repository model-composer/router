<?php namespace Model\Router;

use Model\Db\Db;

class ModElResolver implements ResolverInterface
{
	public function __construct(private \Model\Core\Core $model)
	{
	}

	public function parseEntity(string|array $entity): ?array
	{
		if (is_string($entity))
			$entity = ['element' => $entity];

		if (empty($entity['table']) and !empty($entity['element'])) {
			$element = \Model\Core\Autoloader::searchFile('Element', $entity['element']);
			if (!$element)
				throw new \Exception('Element ' . $entity['element'] . ' not found');
			if (!$element::$table)
				throw new \Exception('Element ' . $entity['element'] . ' does not have a table defined');
			$entity['table'] = $element::$table;
		}

		if (empty($entity['table']))
			throw new \Exception('Entity must define a table or element');

		if (empty($entity['primary'])) {
			$db = Db::getConnection();
			$tableModel = $db->getTable($entity['table']);
			$entity['primary'] = $tableModel->primary ? $tableModel->primary[0] : null;
			if (!$entity['primary'])
				throw new \Exception('Table ' . $entity['table'] . ' does not have a primary key defined');
		}

		return $entity;
	}

	public function getPrimary(array $entity): string
	{
		return $entity['primary'];
	}

	public function fetch(array $entity, ?int $id, array $filters = []): ?array
	{
		$where = $filters['filters'] ?? [];
		foreach ($where as $k => $v)
			$where[$k] = ['LIKE', '%' . implode('%', explode('-', $v)) . '%'];

		if ($id)
			$where[$entity['primary']] = $id;

		return Db::getConnection()->select($entity['table'], $where, ['joins' => $filters['joins'] ?? []]);
	}

	public function parseRelationshipForMatch(array $entity, array $relationship): ?array
	{
		if (!$entity['element'])
			throw new \Exception('Router resolver can only resolve relationships for elements');

		$joins = [];

		$elements_tree = $this->model->getModule('ORM')->getElementsTree();
		$elements_tree = $elements_tree['elements'];

		$current_element = $elements_tree[$entity['element']] ?? null;
		foreach ($relationship['relationships'] as $idx_rel => $rel) {
			if (!$current_element)
				throw new \Exception('Element not found in elements tree while resolving relationship in router');

			$tree_relation = array_find($current_element['children'], fn($r) => $r['relation'] === $rel);
			if (!$tree_relation or $tree_relation['type'] !== 'single' or $tree_relation['assoc']) // Relationship must be exist and must be one-to-one
				throw new \Exception('Relationship ' . $rel . ' not found for element ' . $current_element['name']);

			$current_element = $tree_relation['element'] ? ($elements_tree[$tree_relation['element']] ?? null) : null;
			$joins[] = [
				'table' => $tree_relation['table'],
				'alias' => 'rel_' . $rel,
				'on' => [$tree_relation['field'] => $tree_relation['primary']],
				'fields' => $idx_rel === count($relationship['relationships']) - 1 ? [$relationship['name'] => 'rel_' . $relationship['name']] : [],
			];
		}

		return [
			'joins' => $joins,
			'filters' => [
				'rel_' . $relationship['name'] => $relationship['value'],
			],
		];
	}

	public function mergeQueryFilters(array ...$filters): array
	{
		$merged['filters'] = [];
		$merged['joins'] = [];

		foreach ($filters as $filter_set) {
			if (!empty($filter_set['filters']))
				$merged['filters'] = array_merge($merged['filters'], $filter_set['filters']);
			if (!empty($filter_set['joins']))
				$merged['joins'] = array_merge($merged['joins'], $filter_set['joins']);
		}

		return $merged;
	}

	public function resolveRelationshipForGeneration(array $entity, array|int $row, array $relationship): string
	{
		if (!$entity['element'])
			throw new \Exception('Element resolver can only resolve relationships for elements');

		$current_element = $this->model->getModule('ORM')->one($entity['element'], is_numeric($row) ? $row : $row[$entity['primary']]);
		foreach ($relationship['relationships'] as $rel) {
			$current_element = $current_element->{$rel};
			if (!$current_element)
				throw new \Exception('Could not resolve relationship ' . $rel);
		}

		return $current_element[$relationship['name']];
	}
}
