# ADR 001: Architecture Decisions for Checkout Autenticado con Laravel Sanctum e Inertia Vue

## Status
Accepted

## Context
Project requiring structured implementation matching Gherkin specification.

## Decisions
- **Architecture Style**: Monolith Architecture (MVC / Monolithic) (monolith)
- **Primary Backend Language**: php
- **Backend Framework**: laravel (composer/framework ^10.0)
- **ORM / Persistence**: pdo (ext-pdo (PDO Native))
- **Validation**: native-php-filter (ext-filter (filter_var))
- **Authentication**: jwt-bcrypt (bcrypt cost factor 12, JWT TTL 3600s)
- **Backend Testing Framework**: phpunit (phpunit/phpunit ^10.5)
- **Frontend Framework**: vue
- **Frontend Language**: javascript
- **Frontend Bundler**: vite
- **Frontend Unit Testing**: vitest
- **Frontend E2E Testing**: cypress

## Prohibited Layer Dependencies
Domain core must NOT import:
- `direct SQL string interpolation`
- `global state mutation`
- `laravel/framework`
- `symfony/symfony`
- `illuminate/*`
