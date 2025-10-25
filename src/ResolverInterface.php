<?php namespace Model\Router;

interface ResolverInterface
{
	public function parseEntity(string|array $entity): ?array;

	public function getIdField(array $entity): string;

	public function fetch(array $entity, array $where): ?array;

	public function resolveRelationship(string $relationship): ?string;
}
