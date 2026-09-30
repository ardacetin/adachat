# Security Policy

## Supported versions

Ada Chat has not been released yet. Once releases exist, security fixes will
be provided for the latest minor release.

| Version | Supported |
|---|---|
| unreleased (`main`) | ✅ |

## Reporting a vulnerability

**Please do not report security vulnerabilities through public GitHub issues,
discussions or pull requests.**

Report privately through GitHub's
[private vulnerability reporting](https://docs.github.com/en/code-security/security-advisories/guidance-on-reporting-and-writing-information-about-vulnerabilities/privately-reporting-a-security-vulnerability)
("Report a vulnerability" in the repository's **Security** tab).

Please include:

- a description of the issue and its impact,
- steps to reproduce or a proof of concept,
- affected version/commit and configuration,
- any suggested mitigation.

## What to expect

- Acknowledgement within **3 business days**.
- An initial assessment within **10 business days**.
- Coordinated disclosure: we will agree on a disclosure date with you, normally
  within 90 days, and credit you in the advisory unless you prefer otherwise.

## Scope

In scope: the Ada Chat application code and its official Docker images and
deployment configuration in this repository.

Out of scope: vulnerabilities in third-party AI providers, in an institution's
own infrastructure or configuration, and issues requiring physical or
administrative server access.

## Guidance for operators

- Never commit `.env` files, API keys or institution secrets.
- Keep `APP_DEBUG=false` in production and back up `APP_KEY` securely
  (it encrypts stored provider credentials).
- Use dedicated provider API keys for Ada with provider-side spending limits.

See [docs/security.md](docs/security.md) for the security architecture.
