# Contributing to Puls

Thanks for your interest in contributing! Puls is intentionally minimal — one PHP file, no frameworks, no dependencies. Please keep that in mind when proposing changes.

## Ground Rules

- **No runtime dependencies.** Puls must remain deployable as a file drop on any PHP 8.1+ host.
- **No build step.** The dashboard is self-contained HTML/CSS/JS.
- **Keep it simple.** If a feature needs a framework, it's probably out of scope.

## How to Contribute

1. Fork the repo and create a branch from `main`
2. Make your changes
3. Run tests: `./vendor/bin/pest`
4. Open a pull request with a clear description of what and why

## Development Setup

```bash
git clone git@github.com:your-fork/puls.git
cd puls
php puls key:generate
php puls user:add admin
composer install          # installs Pest + activates pre-push hook
php -S localhost:8080 -t public
```

## Code Style

- 4-space indentation in PHP
- 2-space indentation in HTML/CSS/JS
- English code and comments
- No unnecessary abstractions — three similar lines beat a premature helper

## Tests

All changes must pass the existing test suite. Add tests for new functionality.

```bash
./vendor/bin/pest
```

The pre-push hook runs tests automatically before every push.

## What We're Looking For

- Bug fixes with clear reproduction steps
- Performance improvements backed by measurements
- New bot/browser detection patterns
- Documentation improvements

## What's Probably Out of Scope

- Adding frameworks or build tools
- Database backends other than SQLite
- Cookie-based tracking
- Major UI redesigns (open an issue to discuss first)

## Reporting Bugs

Open a [GitHub issue](../../issues) with:
- What you expected
- What happened instead
- Steps to reproduce
- PHP version and environment details
