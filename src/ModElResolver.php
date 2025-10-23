<?php namespace Model\Router;

use Model\Db\Db;

class ModElResolver implements ResolverInterface
{
	public function select(string $table, array $where): ?array
	{
		$db = Db::getConnection();
		return $db->select($table, $where);
	}

	public function resolveRelationship(string $relationship): ?string
	{
		return null; // TODO
	}
}
