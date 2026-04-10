<?php namespace Model\Router\Tests;

use Model\Router\Route;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class RouteTest extends TestCase
{
	#[Test]
	public function parses_static_segments(): void
	{
		$route = new Route('/about/contact', 'Ctrl');

		$this->assertCount(2, $route->segments);
		$this->assertSame('static', $route->segments[0]['type']);
		$this->assertSame('about', $route->segments[0]['value']);
		$this->assertSame('static', $route->segments[1]['type']);
		$this->assertSame('contact', $route->segments[1]['value']);
	}

	#[Test]
	public function parses_dynamic_segment_with_single_field(): void
	{
		$route = new Route('/pages/:name', 'Ctrl');

		$this->assertSame('dynamic', $route->segments[1]['type']);
		$this->assertCount(1, $route->segments[1]['parts']);

		$part = $route->segments[1]['parts'][0];
		$this->assertSame('field', $part['type']);
		$this->assertSame('name', $part['name']);
		$this->assertEmpty($part['relationships']);
		$this->assertSame('', $part['suffix']);
	}

	#[Test]
	public function parses_multi_field_segment(): void
	{
		$route = new Route('/users/:name-:surname', 'Ctrl');

		$parts = $route->segments[1]['parts'];
		$this->assertCount(2, $parts);
		$this->assertSame('name', $parts[0]['name']);
		$this->assertSame('surname', $parts[1]['name']);
	}

	#[Test]
	public function parses_relationship_field(): void
	{
		$route = new Route('/products/:category.name', 'Ctrl');

		$part = $route->segments[1]['parts'][0];
		$this->assertSame('name', $part['name']);
		$this->assertSame(['category'], $part['relationships']);
	}

	#[Test]
	public function parses_literal_suffix_via_escaped_dot(): void
	{
		$route = new Route('/files/:name\.csv', 'Ctrl');

		$part = $route->segments[1]['parts'][0];
		$this->assertSame('name', $part['name']);
		$this->assertSame('.csv', $part['suffix']);
		$this->assertEmpty($part['relationships']);
	}

	#[Test]
	public function parses_relationship_with_suffix(): void
	{
		$route = new Route('/products/:category.name\.html', 'Ctrl');

		$part = $route->segments[1]['parts'][0];
		$this->assertSame('name', $part['name']);
		$this->assertSame(['category'], $part['relationships']);
		$this->assertSame('.html', $part['suffix']);
	}

	#[Test]
	public function parses_mixed_static_and_field_inside_segment(): void
	{
		$route = new Route('/files/prefix-:name', 'Ctrl');

		$parts = $route->segments[1]['parts'];
		$this->assertCount(2, $parts);
		$this->assertSame('static', $parts[0]['type']);
		$this->assertSame('prefix', $parts[0]['value']);
		$this->assertSame('field', $parts[1]['type']);
		$this->assertSame('name', $parts[1]['name']);
	}

	#[Test]
	public function compiles_regex_for_static_segments(): void
	{
		$route = new Route('/about/contact', 'Ctrl');

		$this->assertSame(1, preg_match($route->regex, '/about/contact'));
		$this->assertSame(1, preg_match($route->regex, '/about/contact/extra')); // non-strict
		$this->assertSame(0, preg_match($route->regex, '/about'));
	}

	#[Test]
	public function strict_regex_rejects_trailing_path(): void
	{
		$route = new Route('/about', 'Ctrl', ['strict' => true]);

		$this->assertSame(1, preg_match($route->regex, '/about'));
		$this->assertSame(0, preg_match($route->regex, '/about/extra'));
	}

	#[Test]
	public function regex_matches_dynamic_segment(): void
	{
		$route = new Route('/pages/:name', 'Ctrl');

		$this->assertSame(1, preg_match($route->regex, '/pages/home'));
		$this->assertSame(1, preg_match($route->regex, '/pages/about-us'));
	}

	#[Test]
	public function regex_enforces_literal_suffix(): void
	{
		$route = new Route('/files/:name\.csv', 'Ctrl');

		$this->assertSame(1, preg_match($route->regex, '/files/report.csv'));
		$this->assertSame(0, preg_match($route->regex, '/files/report.xml'));
	}

	#[Test]
	public function default_options_are_applied(): void
	{
		$route = new Route('/pages/:name', 'Ctrl');

		$this->assertSame('Ctrl', $route->controller);
		$this->assertSame('pages/:name', $route->pattern);
		$this->assertNull($route->options['entity']);
		$this->assertFalse($route->options['strict']);
		$this->assertTrue($route->options['lowercase']);
		$this->assertSame([], $route->options['tags']);
	}

	#[Test]
	public function matches_tags_requires_all_to_be_present(): void
	{
		$route = new Route('/x', 'Ctrl', ['tags' => ['lang' => 'en', 'type' => 'public']]);

		$this->assertTrue($route->matchesTags([]));
		$this->assertTrue($route->matchesTags(['lang' => 'en']));
		$this->assertTrue($route->matchesTags(['lang' => 'en', 'type' => 'public']));
		$this->assertFalse($route->matchesTags(['lang' => 'it']));
		$this->assertFalse($route->matchesTags(['unknown' => 'x']));
	}

	#[Test]
	public function empty_pattern_compiles(): void
	{
		$route = new Route('/', 'Ctrl');

		$this->assertCount(0, $route->segments);
		$this->assertSame(1, preg_match($route->regex, '/'));
	}
}
