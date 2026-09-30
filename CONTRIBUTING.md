# Contributing to Ada Chat

Thank you for your interest in Ada Chat! The project is in its architecture
phase; the best way to contribute right now is to review the documents in
[`docs/`](docs/) and open issues or discussions.

## Ground rules

- **English in code.** Class, method, variable, database column, route,
  configuration key, TypeScript type and React component names are English.
  Technical code comments are English.
- **No hard-coded user-facing text.** All UI strings go through the
  localization system (English and Turkish are both required). Backend
  strings live in `lang/{en,tr}`, frontend strings in
  `resources/js/i18n/locales/{en,tr}`.
- **Institution-neutral.** Never add institution names, domains, colours or
  logos to code. Use settings.
- **Money is never a float.** Use the `Usd` value object / `DECIMAL` columns.
- **No secrets in commits.** `.env.example` contains placeholders only.
- **Minimal dependencies.** Justify every new Composer/npm package in the PR.
- **Tests required** for budget, usage, authentication and authorization
  changes. Budget engine changes without tests will not be merged.

## Workflow

1. Open an issue (or comment on an existing one) before larger changes.
2. Fork and create a branch from `main`.
3. Make focused commits; keep PRs small.
4. Run the checks locally (available from M1): `composer lint`, `composer
   analyse`, `composer test`, `npm run lint`, `npm run types`, `npm test`.
5. Open a pull request describing the change and how it was tested.

## Developer Certificate of Origin

Contributions are accepted under the [Developer Certificate of Origin
(DCO)](https://developercertificate.org/). Sign off each commit:

```
git commit -s -m "Add budget reservation expiry command"
```

## Documentation language

- `README.md` and user/deployment documentation: English (with Turkish
  translations where marked, e.g. `README.tr.md`).
- Architecture documents in `docs/`: English.

## Code of conduct

Be respectful and constructive. Harassment or discrimination is not tolerated.

## Security

Do not report vulnerabilities in public issues — see [SECURITY.md](SECURITY.md).
