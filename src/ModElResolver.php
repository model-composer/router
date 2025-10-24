<?php namespace Model\Router;

use Model\Db\Db;

class ModElResolver implements ResolverInterface
{
	public function getIdFieldFor(string $table): ?string
	{
		$db = Db::getConnection();
		$tableModel = $db->getTable($table);
		return $tableModel->primary ? $tableModel->primary[0] : null;
	}

	public function select(string $table, array $where): ?array
	{
		$db = Db::getConnection();
		return $db->select($table, $where);
	}

	public function getTableFromModel(string $model): ?string
	{
		$modElElement = \Model\Core\Autoloader::searchFile('Element', $model);
		if (!$modElElement)
			$this->model->error('Element ' . $model . ' not found');
		return $modElElement::$table;
	}

	public function resolveRelationship(string $relationship): ?string
	{
		return null; // TODO
	}
}
