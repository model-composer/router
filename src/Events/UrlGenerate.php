<?php namespace Model\Router\Events;

use Model\Events\AbstractEvent;

class UrlGenerate extends AbstractEvent
{
	public function __construct(private ?string $controller = null, private int|array|null $element = null, private array $tags = [])
	{
	}

	public function getData(): array
	{
		return [
			'controller' => $this->controller,
			'element' => $this->element,
			'tags' => $this->tags,
		];
	}
}
