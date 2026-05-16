# AGENTS.md — Instructions for coding agents

If you're an AI coding agent (Claude Code, Cursor, etc.) working on this package, read this first.

## Project shape

`laravel-rag-pipeline` is a thin Composer library over [`guzzlehttp/guzzle + laudis/neo4j-php-client`](https://github.com/guzzlehttp/guzzle + laudis/neo4j-php-client). It does two things only:
1. Bootstraps a Laravel application from a CLI process
2. Resolves a Sanctum personal access token to an authenticated user

That's it. Resist the urge to add features. Keep this package thin.

## Key files

| File | Role | When to touch |
|------|------|---------------|
| `src/McpServerKernel.php` | Bootstrap + auth | Only if changing the Laravel boot sequence |
| `src/AbstractAuthenticatedMcpServer.php` | Base class users extend | Add lifecycle hooks here (boot/shutdown) |
| `src/Auth/SanctumTokenResolver.php` | Token → User | Extend (don't modify) for custom resolvers |
| `examples/*/` | Reference implementations | Update when the API changes |
| `composer.json` | Constraints + autoload | Bump versions here |
| `docs/*.md` | User-facing documentation | Update on any API change |

## Conventions

- **PHP 8.2+** strict types required (`declare(strict_types=1);`)
- **PSR-12** code style (Composer ships no formatter, but match the existing style)
- **Sanctum required** — don't introduce alternative auth that breaks the default flow
- **No new dependencies** unless absolutely necessary. The point is "thin"
- **MIT license** — don't add code with incompatible licenses

## Testing

There's no test suite yet (planned for v0.2). For now, verify changes by running the two examples manually against a Laravel project that has Sanctum installed.

## What NOT to do

- Don't add a Service Provider that auto-registers tools — that defeats the explicit registration pattern
- Don't introduce a tool registry — the SDK already has one
- Don't couple to a specific Eloquent model — `Authenticatable` is the contract
- Don't add HTTP transport in v0.x — that's planned for v1.0 with a clear design

## When in doubt

Open an issue first. This is alpha, the API is intentionally moving slowly to avoid breaking users.
