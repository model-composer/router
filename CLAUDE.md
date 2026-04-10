# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

`model/router` is a standalone, framework-agnostic PHP router library, part of the ModEl Framework ecosystem. It supports bidirectional routing (URL → controller and controller → URL), multi-field URL segments, and database-backed field resolution via a pluggable resolver.

This is a Composer library consumed by other packages; there is no CI pipeline yet. A PHPUnit suite lives in `tests/` covering `Route` / `UrlMatcher` / `UrlGenerator` via a `FakeResolver` test double (`tests/Fakes/FakeResolver.php`). Run it with `composer test` (or `./vendor/bin/phpunit`). `ModElResolver` is intentionally not covered here — it is framework-coupled and belongs in integration tests in the consuming application.

The `FakeResolver` mimics `ModElResolver`'s LIKE translation: in filter values, `-` is treated as `%`, `%` becomes a lazy wildcard, and when multiple rows match the shortest stored string wins (matching the `ORDER BY LENGTH(field)` heuristic). Its relationship methods throw — add relationship-aware tests to a separate file if and when they are needed, ideally with a dedicated fake that pre-joins related fields onto rows under `rel_<name>` keys.

## Architecture

The router is built around a small set of collaborating classes in `src/`:

- **`Router`** — entry point. Loads routes, caches results, dispatches to matcher/generator. Routes come from two sources, merged on every (cold) load:
  1. `AbstractRouterProvider` subclasses discovered via `Model\ProvidersFinder\Providers::find('RouterProvider')`
  2. The `router.routes` config key via `Model\Config\Config`
  Routes are then sorted so that more specific routes (more segments, static before dynamic) are tried first.
- **`Route`** — parses a pattern string into `segments` and a compiled `regex`. A segment is either `static` or `dynamic`; dynamic segments contain `parts` (each part is a `field`, possibly with `relationships` and a literal `suffix`, or a `static` literal). Multi-field segments like `:name-:surname` split on `-` but the matcher later tries all word-distribution combinations.
- **`UrlMatcher`** — matches a URL against a `Route`. Two-pass algorithm: first pass does a quick regex check, processes static segments, collects relationship parts, and short-circuits if the primary key appears directly in the URL. Second pass resolves relationship filters, then collects per-dynamic-segment filter candidates (reusing `generateFieldCombinations` / `createCombinationPatterns` for multi-field segments), cross-products them via `crossProductCandidates`, and invokes the resolver with every merged filter set until one hits. This means multiple direct dynamic segments on the same entity (e.g. `/:type/products/:name`) are combined into a single query rather than resolved sequentially.
- **`UrlGenerator`** — reverse: builds a URL from a controller + element (id or row). Placeholder strings like `//rel0//` are inserted for relationship fields and resolved in a second pass via the resolver. Has an in-instance row cache keyed by entity + id. `urlEncode` handles Unicode (Cyrillic, Han) and strips to `[a-z0-9а-я\p{Han}_-]`.
- **`ResolverInterface`** — the seam between the router and any data layer. Implementations must provide: `parseEntity`, `getPrimary`, `fetch`, `parseRelationshipForMatch`, `mergeQueryFilters`, `resolveRelationshipForGeneration`.
- **`ModElResolver`** — the ModEl Framework implementation of the resolver. Uses `Model\Db\Db` for queries and `Model\Core\Core` + the `ORM` module for relationship resolution. Depends on ModEl-specific APIs; do not reference it from generic code paths. String fields are queried via `LIKE` with `-` replaced by `%`, and ordered by `LENGTH(field)` to pick the most specific match.
- **`AbstractRouterProvider`** — base class consumers extend to expose routes and to hook `preMatchUrl` / `postGenerateUrl` (for e.g. locale prefixes).
- **`Events\UrlGenerate`** — dispatched via `Model\Events\Events` whenever `Router::generate` is called, before cache lookup.

### Caching behavior

`Router` uses `Model\Cache\Cache::getCacheAdapter()` for three separate caches:
- `model.router.routes` — the compiled route list (24h)
- `model.router.matching.{sha1(url)}` — match results per URL (24h)
- `model.router.route.{controller}.{sha1(element)}.{sha1(tags)}` — generated URLs (24h)

All three caches are bypassed when the `DEBUG_MODE` constant is defined and truthy. When changing parsing, matching, or generation logic, keep in mind that results may be cached across requests in non-debug environments.

### Pattern syntax details

- `:field` — dynamic field, matched by the resolver
- `:relation.field` — relationship lookup (one-to-one only in `ModElResolver`)
- `:field\.ext` — escaped dot becomes a literal suffix (e.g., `.csv`), stripped before lookup and re-appended on generation
- `:a-:b` within one segment — multiple fields in the same segment; matcher enumerates all ways to distribute hyphen-separated words across fields
- Route option `strict` (default `false`) — when false, trailing path is allowed (`(\/.*)?` appended to regex); when true, segment counts must match exactly
- Route option `case_sensitive` (default `true` in `Route`, but note the README says otherwise — the constructor default wins)
- Route option `tags` — free-form metadata; `Router::generate` filters candidate routes by tag subset match via `Route::matchesTags`

## Conventions

- PHP file headers use the single-line form `<?php namespace Model\Router;`
- Indentation is tabs; opening braces on same line; single-line `if` bodies use no braces and newline-indented body (see global style rules)
- Logical operators are `and`/`or`, not `&&`/`||`
- Composer autoload is PSR-4: `Model\Router\` → `src/`

## When modifying this repo

- If you change `Route::parsePattern`, `UrlMatcher::match`, or `UrlGenerator::generate`, also mentally walk the three examples in `README.md` (simple param, multi-field, relationship) — they are the de-facto regression cases.
- The `ModElResolver` is the only concrete resolver in this package but consumers may ship their own. Keep `ResolverInterface` stable; prefer extending it additively.
- Update `README.md` alongside behavioral changes; there are no other docs.
