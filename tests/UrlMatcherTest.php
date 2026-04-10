<?php namespace Model\Router\Tests;

use Model\Router\Route;
use Model\Router\Tests\Fakes\FakeResolver;
use Model\Router\UrlMatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

class UrlMatcherTest extends TestCase
{
	private function matcher(FakeResolver $resolver): UrlMatcher
	{
		return new UrlMatcher($resolver);
	}

	private function route(string $pattern, string $table, array $options = []): Route
	{
		$entity = (new FakeResolver())->parseEntity(['table' => $table]);
		return new Route($pattern, 'TestController', array_merge(['entity' => $entity], $options));
	}

	#[Test]
	public function matches_single_field_by_name(): void
	{
		$resolver = new FakeResolver([
			'pages' => [
				['id' => 1, 'name' => 'home'],
				['id' => 2, 'name' => 'about-us'],
			],
		]);
		$route = $this->route('/pages/:name', 'pages');

		$result = $this->matcher($resolver)->match('/pages/about-us', $route);

		$this->assertNotNull($result);
		$this->assertSame(2, $result['id']);
	}

	#[Test]
	public function id_short_circuits_without_fetching(): void
	{
		// Empty table — any resolver fetch would miss. ID shortcut must bypass it.
		$resolver = new FakeResolver([]);
		$route = $this->route('/pages/:id', 'pages');

		$result = $this->matcher($resolver)->match('/pages/123', $route);

		$this->assertNotNull($result);
		$this->assertSame(123, $result['id']);
	}

	#[Test]
	public function returns_null_when_static_segment_does_not_match(): void
	{
		$resolver = new FakeResolver([
			'pages' => [['id' => 1, 'name' => 'home']],
		]);
		$route = $this->route('/pages/:name', 'pages');

		$this->assertNull($this->matcher($resolver)->match('/articles/home', $route));
	}

	#[Test]
	public function returns_null_when_field_has_no_row(): void
	{
		$resolver = new FakeResolver([
			'pages' => [['id' => 1, 'name' => 'home']],
		]);
		$route = $this->route('/pages/:name', 'pages');

		$this->assertNull($this->matcher($resolver)->match('/pages/missing', $route));
	}

	#[Test]
	public function multi_field_segment_tries_word_distributions(): void
	{
		$resolver = new FakeResolver([
			'users' => [
				['id' => 1, 'name' => 'john-doe', 'surname' => 'smith'],
			],
		]);
		$route = $this->route('/users/:name-:surname', 'users');

		$result = $this->matcher($resolver)->match('/users/john-doe-smith', $route);

		$this->assertNotNull($result);
		$this->assertSame(1, $result['id']);
	}

	#[Test]
	public function cross_segment_matching_combines_filters(): void
	{
		$resolver = new FakeResolver([
			'products' => [
				['id' => 1, 'type' => 'electronics', 'name' => 'laptop'],
				['id' => 2, 'type' => 'electronics', 'name' => 'phone'],
				['id' => 3, 'type' => 'books', 'name' => 'laptop'],
			],
		]);
		$route = $this->route('/:type/products/:name', 'products');

		$this->assertSame(1, $this->matcher($resolver)->match('/electronics/products/laptop', $route)['id']);
		$this->assertSame(2, $this->matcher($resolver)->match('/electronics/products/phone', $route)['id']);
		$this->assertSame(3, $this->matcher($resolver)->match('/books/products/laptop', $route)['id']);
	}

	#[Test]
	public function cross_segment_returns_null_when_combination_has_no_row(): void
	{
		// Both values exist in isolation, but no row has BOTH — under the old
		// sequential matcher this would wrongly return the first 'books' row.
		$resolver = new FakeResolver([
			'products' => [
				['id' => 1, 'type' => 'books', 'name' => 'novel'],
				['id' => 2, 'type' => 'electronics', 'name' => 'laptop'],
			],
		]);
		$route = $this->route('/:type/products/:name', 'products');

		$this->assertNull($this->matcher($resolver)->match('/books/products/laptop', $route));
	}

	#[Test]
	public function strict_option_rejects_extra_segments(): void
	{
		$resolver = new FakeResolver([
			'pages' => [['id' => 1, 'name' => 'home']],
		]);
		$strict = $this->route('/pages/:name', 'pages', ['strict' => true]);
		$loose = $this->route('/pages/:name', 'pages');

		$this->assertNull($this->matcher($resolver)->match('/pages/home/extra', $strict));
		$this->assertNotNull($this->matcher($resolver)->match('/pages/home/extra', $loose));
	}

	#[Test]
	public function suffix_is_stripped_before_lookup(): void
	{
		$resolver = new FakeResolver([
			'files' => [['id' => 1, 'name' => 'report']],
		]);
		$route = $this->route('/files/:name\.csv', 'files');

		$this->assertSame(1, $this->matcher($resolver)->match('/files/report.csv', $route)['id']);
		$this->assertNull($this->matcher($resolver)->match('/files/report.xml', $route));
	}

	#[Test]
	public function id_wins_over_other_segments_in_same_pattern(): void
	{
		// Even though the :type segment would otherwise filter the lookup,
		// extracting an ID from a later segment still short-circuits directly.
		$resolver = new FakeResolver([]);
		$route = $this->route('/:type/products/:id', 'products');

		$result = $this->matcher($resolver)->match('/electronics/products/42', $route);

		$this->assertNotNull($result);
		$this->assertSame(42, $result['id']);
	}

	#[Test]
	public function multi_field_segment_combines_with_another_segment(): void
	{
		$resolver = new FakeResolver([
			'users' => [
				['id' => 1, 'role' => 'admin', 'name' => 'john', 'surname' => 'doe'],
				['id' => 2, 'role' => 'user', 'name' => 'john', 'surname' => 'doe'],
			],
		]);
		$route = $this->route('/:role/users/:name-:surname', 'users');

		$this->assertSame(1, $this->matcher($resolver)->match('/admin/users/john-doe', $route)['id']);
		$this->assertSame(2, $this->matcher($resolver)->match('/user/users/john-doe', $route)['id']);
	}

	#[Test]
	public function returns_null_when_fewer_segments_than_pattern(): void
	{
		$resolver = new FakeResolver([
			'products' => [['id' => 1, 'type' => 'books', 'name' => 'novel']],
		]);
		$route = $this->route('/:type/products/:name', 'products');

		$this->assertNull($this->matcher($resolver)->match('/books/products', $route));
	}
}
