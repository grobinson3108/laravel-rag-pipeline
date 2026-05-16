# Contributing

Thanks for considering a contribution!

## Issues first

For non-trivial changes, **open an issue first** to discuss the approach. This package is intentionally thin — most additions are out of scope.

In-scope contributions:
- Alternative auth resolvers (Passport, custom guards)
- Better error messages
- Documentation improvements, typo fixes
- Test infrastructure (Orchestra Testbench setup)
- CI improvements

Out of scope (open an issue, expect "no"):
- Auto-registration of tools via Service Provider
- Built-in tool registry
- HTTP/SSE transport (planned separately for v1.0)
- Specific tools (those belong in your app, not this package)

## Pull requests

1. Fork the repo
2. Create a branch: `git checkout -b feat/your-feature`
3. Code + test
4. Commit with a clear message (no emoji prefix needed)
5. Open PR against `main`

## Code style

- PHP 8.2+ syntax, `declare(strict_types=1);`
- PSR-12 formatting (match existing style)
- Type-hint everything
- Short methods, single responsibility

## Tests

If you add code that can be tested, add tests. If you don't know how to test it, open the PR anyway and ask in the description.

## License

By contributing, you agree your code is released under MIT.
