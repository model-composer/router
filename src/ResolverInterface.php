<?php namespace Model\Router;

interface ResolverInterface
{
	public function select(string $table, array $where): ?array;

	public function resolveRelationship(string $relationship): ?string;
}
