# Contributing

Contributions are welcome. Here’s how to keep things consistent.

## Code and patches

- **Source of truth:** All plugin code lives under `trunk/`. Base branches and PRs on `trunk/` (or your fork’s default branch that tracks it).
- **WordPress/WooCommerce style:** Follow [WordPress PHP coding standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/) where applicable. Use escaping and sanitization for output and input; use nonces and capability checks for admin/AJAX.
- **No secrets:** Don’t add private keys, API keys, or other secrets. The plugin is designed to use only KPUB and public APIs.

## Reporting issues

- Open an issue on GitHub.
- Include WordPress and WooCommerce versions, PHP version, and steps to reproduce where relevant.
- For security-sensitive bugs, you can contact the maintainer privately (see plugin header / README for links) instead of posting publicly.

## Pull requests

- One logical change per PR when possible.
- Describe what changed and why.
- Ensure the plugin still activates and the payment flow works; mention if you’ve only tested in a specific environment.

Thanks for contributing.
