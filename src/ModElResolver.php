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

	public function fetch(array $entity, ?int $id, ?array $filters = []): ?array
	{
		if ($id)
			$filters[$entity['primary']] = $id;

		return Db::getConnection()->select($entity['table'], $filters);
	}

	public function resolveRelationship(array $entity, array|int $row, array $relationship): string
	{
		if (!$entity['element'])
			throw new \Exception('Element resolver can only resolve relationships for elements');

		$curr_element = $this->model->getModule('ORM')->one($entity['element'], is_numeric($row) ? $row : $row[$entity['primary']]);
		foreach ($relationship['relationships'] as $rel) {
			$curr_element = $curr_element->{$rel};
			if (!$curr_element)
				throw new \Exception('Could not resolve relationship ' . $rel);
		}

		return $curr_element[$relationship['field']];
	}
}
