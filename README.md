# Ada Chat

**Ada Chat** is an open-source, self-hosted institutional AI gateway and chat
platform for universities and organizations, with centralized authentication,
multi-provider AI access, user-level budgets, usage accounting, and
administrative controls.

[Türkçe](README.tr.md)

> **Status: architecture phase (M0).** There is no application code yet. The
> architecture is documented in [`docs/`](docs/) and is open for review.

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

- Google Workspace sign-in restricted to allowed domains
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

## Documentation

| Document | Contents |
|---|---|
| [Architecture](docs/architecture.md) | Overall design, domain boundaries, request and streaming flow, deployment |
| [Database design](docs/database-design.md) | Tables, relations, indexes, money precision, MySQL conventions |
| [Budget engine](docs/budget-engine.md) | Budget periods, token counting, reservations, settlement, concurrency |
| [Authentication](docs/authentication.md) | Google Workspace flow, roles, future OIDC/SAML/LDAP |
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

The license (AGPL-3.0 or Apache-2.0) has not been decided yet. Until a
`LICENSE` file is added, no license is granted.
