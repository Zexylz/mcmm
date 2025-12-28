# Modpack Manager (MCMM)

Minecraft Modpack Manager for Unraid.

## Development & Standards

This repository enforces coding standards using GitHub Actions.
All Pull Requests and commits to `main` are checked for style violations.

### Style Guides

- **PHP**: [PSR-12](https://www.php-fig.org/psr/psr-12/) via `phpcs`.
- **HTML**: Standard HTML rules via `htmlhint`.
- **CSS**: Standard CSS rules via `stylelint`.
- **JavaScript**: Recommended rules via `eslint`.

### Running Checks Locally

To run checks locally, ensure you have the necessary tools installed (`php`, `composer`, `node`, `npm`).

```bash
# PHP
phpcs --standard=phpcs.xml .

# HTML
npm install -g htmlhint
htmlhint "**/*.html"

# CSS
npm install -g stylelint stylelint-config-standard
stylelint "**/*.css" --config .stylelintrc.json

# JavaScript
npm install -g eslint
eslint "**/*.js" --config .eslintrc.json
```
