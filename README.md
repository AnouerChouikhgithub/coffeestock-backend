# CoffeeStock Backend

This repository contains the Symfony 7.4 API foundation for CoffeeStock.

## Project purpose and current status

The backend currently provides the application foundation rather than domain business logic. It includes:

- a Symfony 7.4 application skeleton
- PostgreSQL + Doctrine configuration
- CORS for local frontend integration
- a JSON health endpoint for API readiness checks
- PHPUnit, PHPStan, and PHP-CS-Fixer setup
- CI validation via GitHub Actions

The following are intentionally not implemented in this repository yet:

- inventory domain entities and repositories
- stock movement workflows
- order management or procurement logic
- user authentication and authorization
- any business-specific database schema beyond the foundation setup

## Technology stack

- Symfony 7.4
- PostgreSQL 16
- Doctrine ORM and Migrations
- PHP 8.3
- Composer 2
- Docker Compose for local database provisioning
- PHPUnit, PHPStan, PHP-CS-Fixer

## Prerequisites

- PHP 8.3 installed and available on PATH
- Composer 2.x
- PostgreSQL 16 or Docker Desktop
- Symfony CLI is optional but useful for local development

## Fresh clone setup

From a fresh clone:

```bash
cd coffeestock-backend
composer install
```

If the project is being run on a machine where PHP is not on PATH, ensure the installed PHP directory is available before invoking Composer or Symfony. On this environment, the local PHP install was under `C:\xampp\php`.

## Environment configuration

The repository contains `.env.example` and the local runtime file `.env` used for development.

The real environment names used by this project are:

- `APP_ENV`
- `APP_SECRET`
- `DEFAULT_URI`
- `DATABASE_URL`
- `CORS_ALLOW_ORIGIN`
- `MESSENGER_TRANSPORT_DSN`
- `MAILER_DSN`

Example configuration is available in `.env.example` and the local `.env` file. Do not commit real secrets or machine-specific credentials.

## PostgreSQL setup

The project uses a local PostgreSQL service configured in `compose.yaml`.

### Using Docker

```bash
docker compose up -d database
```

This starts the PostgreSQL container on the host port `6543`.

Important:

- the database service itself listens on PostgreSQL port `5432` inside the container
- the host port is mapped to `6543` to avoid port conflicts with other local services
- the app is configured for this local setup via `DATABASE_URL`

### Database creation and verification

The current project expects the development database to exist as:

- `coffeestock_dev`

The test database is:

- `coffeestock_test`

Verify connectivity with:

```bash
php bin/console doctrine:query:sql "SELECT 1"
```

To confirm the live database identity without exposing credentials, use:

```bash
php bin/console doctrine:query:sql "SELECT current_database(), current_user, inet_server_addr(), inet_server_port()"
```

## Symfony development server

Start the local Symfony server:

```bash
symfony server:start --port=8000
```

The application health endpoint is:

- `GET http://localhost:8000/api/health`

The current health response includes:

- application status
- app name
- environment
- database connectivity status

## Quality checks and validation

Run the project checks using the current scripts and tooling:

```bash
php vendor/bin/phpunit --testdox
php bin/console lint:container
php bin/console debug:router
php vendor/bin/phpstan analyse --memory-limit=1G
php vendor/bin/php-cs-fixer fix --dry-run --diff --using-cache=no
```

Composer scripts are also defined in `composer.json`:

```bash
composer test
composer analyse
composer lint
```

## Migration workflow

This foundation is intentionally limited to the API shell. Migrations should be added only when domain entities are approved and implemented. The expected workflow is:

```bash
php bin/console doctrine:migrations:diff
php bin/console doctrine:migrations:migrate
```

Do not create migrations for placeholder or speculative domain models during the foundation stage.

## Backend directory structure

```text
coffeestock-backend/
├── .env.example
├── .env
├── .github/workflows/ci.yml
├── .gitignore
├── compose.yaml
├── composer.json
├── phpstan.neon
├── phpunit.dist.xml
├── README.md
├── src/
│   ├── Controller/
│   │   └── HealthController.php
│   └── Kernel.php
├── tests/
│   ├── bootstrap.php
│   └── Controller/
│       └── HealthControllerTest.php
├── config/
├── public/
├── migrations/
├── templates/
├── translations/
├── vendor/
└── var/
```

## API and local integration conventions

- API routes are intentionally under `/api`
- Responses use JSON payloads
- The current backend already exposes `GET /api/health`
- CORS is configured to allow local frontend access from the Vite dev server
- Local frontend base URL is expected to be `http://localhost:5173`

The actual local integration endpoints are:

- Frontend: `http://localhost:5173`
- Backend: `http://localhost:8000`
- API health: `http://localhost:8000/api/health`

## Troubleshooting

### Composer or PHP issues

- Ensure the correct PHP binary is on PATH.
- If your shell cannot find `php`, add your PHP installation directory to PATH.
- Re-run `composer install` after the environment is corrected.

### PostgreSQL issues

- Confirm Docker is running before `docker compose up -d database`.
- Verify the container is healthy with `docker compose ps`.
- Check that the configured database names match the expected local values.
- Verify the `DATABASE_URL` points to the correct host and port, using the project’s current local setup.

### PHPUnit / Symfony bootstrapping issues

- Ensure `APP_ENV` is set appropriately for the current runtime.
- Keep `KERNEL_CLASS` aligned with `App\Kernel`.
- Use the project’s shipped PHPUnit configuration file (`phpunit.dist.xml`) rather than a custom ad hoc setup.

### CORS and frontend connectivity issues

- Ensure the frontend URL is included in the backend’s CORS configuration.
- Confirm the frontend is running on the Vite default port `5173`.
- Verify the frontend `.env` has `VITE_API_BASE_URL=http://localhost:8000/api`.

## GitHub Actions CI

The repository includes a CI workflow at `.github/workflows/ci.yml` that validates:

- Composer dependencies installation
- Symfony container linting
- PHPUnit execution
- PHPStan analysis
- PHP-CS-Fixer dry-run formatting checks

## Shared documentation

This repository is intentionally self-contained, but the workspace also provides shared project-level documentation:

- `../README.md`
- `../docs/architecture.md`
- `../docs/api-baseline.md`

Those files are supplementary. This README contains the repository-specific setup instructions required for a developer cloning only this repository.

## Implemented functionality vs planned features

Implemented in the current foundation:

- Symfony skeleton and framework configuration
- PostgreSQL + Doctrine connectivity foundation
- local CORS setup for the Vite frontend
- health endpoint for runtime verification
- API foundation and validation tooling

Planned for later phases:

- inventory entities and stock management domain
- order and purchase workflows
- reporting and operations dashboards
- authentication and authorization
- richer data validation and domain business rules
