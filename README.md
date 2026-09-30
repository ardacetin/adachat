# Ada Chat

**Ada Chat** is an open-source, self-hosted institutional AI gateway and chat
platform for universities and organizations, with centralized authentication,
multi-provider AI access, user-level budgets, usage accounting, and
administrative controls.

[Türkçe](README.tr.md)

> **Status: early development.** Milestones M0–M7 are done: foundation,
> Google Workspace (SAML) sign-in, institution settings and branding, AI
> providers and model aliases, the budget engine, streaming chat, groups,
> budget policies and user usage, administration of users and the audit
> log, and the dashboard and reports. Next: hardening (M10). The
> architecture is documented in [`docs/`](docs/); see the
> [roadmap](docs/v1-roadmap.md).

## Why Ada?

Staff and faculty increasingly ask for individual subscriptions to ChatGPT,
Claude, Gemini and similar services. Per-seat subscriptions are expensive,
hard to manage and hard to report on, and they leave security policy to each
individual.

Ada lets an institution connect its own AI provider API accounts once and
offer controlled access to everyone:

- Staff sign in with their institutional account (Google Workspace in V1).
- They use the models the institution allows, under friendly names like
  "Fast" or "Advanced".
- Each person has a monthly budget in USD (for example $10). When it is used
  up, new requests are refused; it renews automatically every month.
- Administrators see spending and usage — but not conversation content.

## Planned V1 features

- Google Workspace sign-in (SAML 2.0) restricted to allowed domains
- Turkish and English UI, dark mode, institution branding
- Roles (super admin, admin, user) and groups
- OpenAI, Anthropic and Google Gemini through a provider abstraction
- Model registry, model aliases and per-group model permissions
- Streaming chat with Markdown rendering and conversation history
- Monthly USD budgets with hard enforcement (provider token counting,
  reservations, output capping)
- Usage accounting with pricing snapshots, usage reports and an admin dashboard
- Encrypted provider credentials and audit logging
- Deployment on Ubuntu + Nginx + PHP-FPM + MySQL + Redis, or with Docker

Out of scope for V1: RAG, web search, agents, tools, image generation, voice,
file uploads and multi-tenant SaaS.

## Technology

PHP 8.4+, Laravel 13, MySQL 8.4 LTS, Redis, Inertia.js, React, TypeScript,
Tailwind CSS and shadcn/ui — deployed as a single application.

## Development

Requirements: PHP 8.4+, Composer, Node.js 22+, Docker (for MySQL/Redis/Mailpit)
or a local MySQL 8.4.

```bash
docker compose up -d            # MySQL 8.4, Redis, Mailpit
cp .env.example .env            # set ADA_DEV_LOGIN=true for local sign-in
composer install && npm install
php artisan key:generate
php artisan migrate --seed      # creates sample users
php artisan storage:link        # serves uploaded logos
composer dev                    # app on http://localhost:8000
```

Sign-in uses SAML 2.0 with a custom SAML app in Google Workspace: set
`SAML_IDP_ENTITY_ID`, `SAML_IDP_SSO_URL`, `SAML_IDP_CERT` and
`AUTH_ALLOWED_DOMAINS`, and enter Ada's ACS URL (`<APP_URL>/auth/saml/acs`)
and Entity ID (`<APP_URL>/auth/saml/metadata`) in Google Admin (see
[authentication](docs/authentication.md#setting-up-the-google-workspace-saml-app));
locally you can also use the development login. Run all checks with
`composer ci:check`.

End-to-end tests (Playwright, against a mock AI provider): prepare a database
with `php artisan migrate:fresh --seed && php tests/e2e/seed.php`, build the
assets, then run `npx playwright install chromium` once and `npm run test:e2e`.

The budget jobs (expiring abandoned reservations, daily reconciliation) and
the nightly retention clean-up (`ada:retention:prune`) run from Laravel's
scheduler: `php artisan schedule:work` locally, a cron entry
for `php artisan schedule:run` every minute in production. The monthly limit
of the Default budget policy comes from `ADA_DEFAULT_MONTHLY_LIMIT_USD` on
installation. `php artisan ada:doctor` checks a running installation
(settings, database, scheduler, sign-in, AI providers) and exits with an
error when something needs fixing.

## Documentation

| Document | Contents |
|---|---|
| [Architecture](docs/architecture.md) | Overall design, domain boundaries, request and streaming flow, deployment |
| [Database design](docs/database-design.md) | Tables, relations, indexes, money precision, MySQL conventions |
| [Budget engine](docs/budget-engine.md) | Budget periods, token counting, reservations, settlement, concurrency |
| [Authentication](docs/authentication.md) | SAML sign-in with Google Workspace, roles, future OIDC/LDAP |
| [Provider architecture](docs/provider-architecture.md) | Provider interface, adapters, token counters, usage normalization |
| [Frontend architecture](docs/frontend-architecture.md) | React/Inertia structure, streaming state, i18n, theming |
| [Security](docs/security.md) | Threats and controls |
| [V1 roadmap](docs/v1-roadmap.md) | Milestones |

## Institution-neutral by design

Ada contains no institution-specific names, domains, colours or logos in
code. Each institution configures its own name, branding, allowed domains,
providers, models and budgets. The first production deployment will be at
Beykoz University, but Ada is built for any institution.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). Security issues: see
[SECURITY.md](SECURITY.md) — please do not open public issues for
vulnerabilities.

## License

Ada Chat is licensed under the [GNU Affero General Public License v3.0 or
later](LICENSE) (`AGPL-3.0-or-later`). If you run a modified version of Ada for
users over a network, you must make the source code of your modified version
available to those users.
