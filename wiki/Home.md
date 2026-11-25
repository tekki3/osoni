# Osoni

Osoni is an approachable MVC framework for building websites with native PHP
8.3+ on Windows and Linux. It provides routing, views, validation,
authentication, database access, mail, uploads, APIs, document generation,
file readers, caching, and a native CLI.

Osoni uses normal PHP and explicit `require_once` loading. It does not require
Composer, external PHP packages, PSR autoloading, or a frontend build.

## Get started

You need PHP 8.3 or later and the native extensions for the features you use.
For a simple local setup, use SQLite with `pdo_sqlite`.

From the project directory, run:

```sh
php osoni init
php osoni storage:prepare
php osoni migrate
php osoni serve
```

Then open <http://127.0.0.1:8000>. The built-in server is for local
development only; use a supported web server for production.

## Where things live

- `bootstrap.php` loads framework files explicitly and builds the application.
- `routes/web.php` registers browser routes.
- `app/controllers/` contains request handlers.
- `app/views/` contains PHP and Osoni templates.
- `config/` contains validated service configuration.
- `database/migrations/` contains schema changes.
- `public/` is the web document root.
- `storage/` contains private runtime files.

Creating a controller or view does not publish a URL. Require controller files
and register routes explicitly.

## Learn more

- [Project README](https://github.com/tekki3/osoni/blob/main/README.md) —
  installation, examples, and feature guides.
- [Offline user manual](https://github.com/tekki3/osoni/blob/main/docs/index.html) —
  tutorials, CLI reference, configuration, and APIs.

## Contributing

Run the native test suite from the project root:

```sh
php osoni test
```

After changing documentation sources, rebuild and check the manual:

```sh
php docs/build.php
php docs/build.php --check
```

See the README for coding conventions, platform requirements, and deployment
guidance. Protect production data and configuration; tests use isolated
fixtures and must not send real mail or AI requests.
