<?php namespace Model\Router;

use Model\ProvidersFinder\AbstractProvider;

abstract class AbstractRouterProvider extends AbstractProvider
{
	public static function getRoutes(): array
	{
		return [];
	}
}
