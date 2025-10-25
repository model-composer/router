<?php namespace Model\Router;

use Model\Db\Db;

class ModElResolver implements ResolverInterface
{
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

		if (empty($entity['id_field'])) {
			$db = Db::getConnection();
			$tableModel = $db->getTable($entity['table']);
			$entity['id_field'] = $tableModel->primary ? $tableModel->primary[0] : null;
			if (!$entity['id_field'])
				throw new \Exception('Table ' . $entity['table'] . ' does not have a primary key defined');
		}

		return $entity;
	}

	public function getIdField(array $entity): string
	{
		return $entity['id_field'];
	}

	public function fetch(array $entity, array $where): ?array
	{
		$db = Db::getConnection();
		return $db->select($entity['table'], $where);
	}

	public function resolveRelationship(string $relationship): ?string
	{
		return null; // TODO
	}
}
