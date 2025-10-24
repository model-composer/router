<?php namespace Model\Router\Providers;

use Model\Config\AbstractConfigProvider;

class ConfigProvider extends AbstractConfigProvider
{
	public static function migrations(): array
	{
		return [
			[
				'version' => '0.1.0',
				'migration' => function (array $config, string $env) {
					return [
						'routes' => [],
					];
				},
			],
		];
	}
}
