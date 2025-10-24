<?php namespace Model\Router;

interface ResolverInterface
{
	public function getIdFieldFor(string $table): ?string;

	public function select(string $table, array $where): ?array;

	public function getTableFromModel(string $model): ?string;

	public function resolveRelationship(string $relationship): ?string;
}
