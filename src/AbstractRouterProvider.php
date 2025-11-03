<?php namespace Model\Router;

use Model\ProvidersFinder\AbstractProvider;

abstract class AbstractRouterProvider extends AbstractProvider
{
	public static function getRoutes(): array
	{
		return [];
	}

	public static function preMatchUrl(string $url): string
	{
		return $url;
	}

	public static function postGenerateUrl(string $url, array $options): string
	{
		return $url;
	}
}
