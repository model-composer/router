<?php namespace Model\Router;

interface ResolverInterface
{
	public function parseEntity(string|array $entity): ?array;

	public function getPrimary(array $entity): string;

	public function fetch(array $entity, int $id, array $filters = [], array $joins = []): ?array;

	public function parseRelationshipForMatch(array $relationship): ?array;

	public function resolveRelationshipForGeneration(array $entity, array $row, array $relationship): ?string;
}
