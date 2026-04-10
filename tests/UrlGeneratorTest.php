<?php namespace Model\Router\Tests;

use Model\Router\Route;
use Model\Router\Tests\Fakes\FakeResolver;
use Model\Router\UrlGenerator;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UrlGeneratorTest extends TestCase
{
	private function generator(FakeResolver $resolver): UrlGenerator
	{
		return new UrlGenerator($resolver);
	}

	private function route(string $pattern, string $table, array $options = []): Route
	{
		$entity = (new FakeResolver())->parseEntity(['table' => $table]);
		return new Route($pattern, 'TestController', array_merge(['entity' => $entity], $options));
	}

	#[Test]
	public function generates_url_from_integer_id(): void
	{
		$resolver = new FakeResolver([
			'pages' => [['id' => 5, 'name' => 'about-us']],
		]);
		$route = $this->route('/pages/:name', 'pages');

		$this->assertSame('pages/about-us', $this->generator($resolver)->generate($route, 5));
	}

	#[Test]
	public function generates_url_from_prefetched_row_without_fetching(): void
	{
		$resolver = new FakeResolver([]); // empty — any fetch would return null
		$route = $this->route('/pages/:name', 'pages');

		$result = $this->generator($resolver)->generate($route, ['id' => 5, 'name' => 'about-us']);

		$this->assertSame('pages/about-us', $result);
		$this->assertSame(0, $resolver->fetchCalls);
	}

	#[Test]
	public function direct_id_route_does_not_fetch(): void
	{
		$resolver = new FakeResolver([]);
		$route = $this->route('/pages/:id', 'pages');

		$this->assertSame('pages/42', $this->generator($resolver)->generate($route, 42));
		$this->assertSame(0, $resolver->fetchCalls);
	}

	#[Test]
	public function cross_segment_generate_reuses_cached_row(): void
	{
		$resolver = new FakeResolver([
			'products' => [['id' => 1, 'type' => 'electronics', 'name' => 'laptop']],
		]);
		$route = $this->route('/:type/products/:name', 'products');

		$this->assertSame('electronics/products/laptop', $this->generator($resolver)->generate($route, 1));
		// Two dynamic segments both need the row — the per-instance row cache
		// means only one resolver fetch should fire.
		$this->assertSame(1, $resolver->fetchCalls);
	}

	#[Test]
	public function multi_field_segment_is_joined_with_dash(): void
	{
		$resolver = new FakeResolver([
			'users' => [['id' => 1, 'name' => 'john', 'surname' => 'doe']],
		]);
		$route = $this->route('/users/:name-:surname', 'users');

		$this->assertSame('users/john-doe', $this->generator($resolver)->generate($route, 1));
	}

	#[Test]
	public function suffix_is_appended_on_generation(): void
	{
		$resolver = new FakeResolver([
			'files' => [['id' => 1, 'name' => 'report']],
		]);
		$route = $this->route('/files/:name\.csv', 'files');

		$this->assertSame('files/report.csv', $this->generator($resolver)->generate($route, 1));
	}

	#[Test]
	public function url_encodes_value_with_spaces_and_special_chars(): void
	{
		$resolver = new FakeResolver([
			'pages' => [['id' => 1, 'name' => 'Hello World!@#']],
		]);
		$route = $this->route('/pages/:name', 'pages');

		$this->assertSame('pages/hello-world', $this->generator($resolver)->generate($route, 1));
	}

	#[Test]
	public function url_encodes_cyrillic(): void
	{
		$resolver = new FakeResolver([
			'pages' => [['id' => 1, 'name' => 'Привет Мир']],
		]);
		$route = $this->route('/pages/:name', 'pages');

		$this->assertSame('pages/привет-мир', $this->generator($resolver)->generate($route, 1));
	}

	#[Test]
	public function preserves_case_when_lowercase_disabled(): void
	{
		$resolver = new FakeResolver([
			'pages' => [['id' => 1, 'name' => 'AboutUs']],
		]);
		$route = $this->route('/pages/:name', 'pages', ['lowercase' => false]);

		$this->assertSame('pages/AboutUs', $this->generator($resolver)->generate($route, 1));
	}

	#[Test]
	public function returns_null_when_row_not_found(): void
	{
		$resolver = new FakeResolver(['pages' => []]);
		$route = $this->route('/pages/:name', 'pages');

		$this->assertNull($this->generator($resolver)->generate($route, 999));
	}

	#[Test]
	public function returns_null_when_field_is_missing_on_row(): void
	{
		$resolver = new FakeResolver([
			'pages' => [['id' => 1, 'title' => 'Hello']], // no 'name'
		]);
		$route = $this->route('/pages/:name', 'pages');

		$this->assertNull($this->generator($resolver)->generate($route, 1));
	}

	#[Test]
	public function returns_null_when_element_is_null_and_field_lookup_needed(): void
	{
		$resolver = new FakeResolver([
			'pages' => [['id' => 1, 'name' => 'home']],
		]);
		$route = $this->route('/pages/:name', 'pages');

		$this->assertNull($this->generator($resolver)->generate($route, null));
	}

	#[Test]
	public function static_segments_pass_through(): void
	{
		$resolver = new FakeResolver([
			'pages' => [['id' => 1, 'name' => 'home']],
		]);
		$route = $this->route('/static/:name/deep', 'pages');

		$this->assertSame('static/home/deep', $this->generator($resolver)->generate($route, 1));
	}
}
