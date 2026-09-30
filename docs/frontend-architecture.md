# Frontend Architecture

> Status: **Partially implemented** — foundation, i18n, theming/branding,
> settings and admin screens (M1–M4). The chat UI follows in M6.

## 1. Foundation

- Start from the official **Laravel React starter kit** (Laravel 13):
  React 19, TypeScript, Inertia.js v3, Tailwind CSS v4, shadcn/ui, Vite+
  (Vite with oxlint/oxfmt for linting and formatting).
- Reuse what the kit already provides instead of rebuilding it: the
  `use-appearance` hook (light/dark/system with no flash of wrong theme), the
  shadcn `Sidebar`-based app shell, settings pages layout, Wayfinder typed
  route helpers, lint/format configuration.
- Remove what Ada does not need: password auth pages, registration, e-mail
  verification, password reset (sign-in goes through the institution's identity provider).
- **No separate SPA / Next.js app.** One Laravel deployment serves pages via
  Inertia; the only non-Inertia endpoint used by the UI is the chat SSE stream.

## 2. Directory structure

The starter kit's conventions are kept: lower-case kebab-case file names,
PascalCase component names, `@/` alias to `resources/js`.

```
resources/js/
├── app.tsx                    # Inertia bootstrap, i18n init, lazy page resolution
├── components/
│   ├── ui/                    # shadcn/ui source (owned by Ada, restyled via tokens)
│   ├── layout/                # app-shell, app-sidebar, user-menu, institution-logo
│   ├── chat/                  # chat-composer, message-list, message-item, markdown-renderer,
│   │                          # code-block, conversation-sidebar, empty-state, stop-button
│   ├── models/                # model-selector, model-details-tooltip
│   ├── budget/                # budget-indicator, usage-summary, budget-exhausted-alert
│   └── admin/                 # data-table, kpi-card, spend-chart, filters, masked-secret
├── pages/
│   ├── auth/login.tsx
│   ├── chat/index.tsx         # new conversation
│   ├── chat/show.tsx          # existing conversation
│   ├── settings/{profile,appearance,language,usage}.tsx
│   └── admin/{dashboard,users,groups,providers,models,aliases,
│              budget-policies,usage,audit-logs,institution,system}/…
├── layouts/
│   ├── chat-layout.tsx        # sidebar + main; Sheet on mobile
│   ├── admin-layout.tsx       # admin nav; loaded only by admin pages
│   ├── settings-layout.tsx
│   └── auth-layout.tsx
├── hooks/                     # use-chat-stream, use-appearance, use-locale, use-budget
├── lib/                       # sse-client, format (Intl), utils (cn)
├── i18n/
│   ├── index.ts
│   └── locales/{en,tr}/{common,auth,chat,budget,settings,admin}.json
└── types/                     # shared props, domain types (Conversation, Message, ModelAlias…)
```

Rule: `components/ui` contains only shadcn primitives; application-specific
components live in their domain folder and compose primitives. No custom
component is created when a shadcn component exists (Sidebar, Sheet, Dialog,
DropdownMenu, Select, Command, Tabs, Table, Tooltip, Avatar, Badge, Progress,
Popover, Alert, Skeleton, Card, Input, Textarea, ScrollArea, Switch…).

## 3. Inertia data flow

Shared props (`HandleInertiaRequests`), kept small because they are sent on
every navigation:

```ts
interface SharedProps {
  auth: { user: { id: number; name: string; email: string; avatarUrl?: string;
                  role: 'super_admin' | 'admin' | 'user'; appearance: Appearance } | null };
  can: { accessAdmin: boolean; manageSystem: boolean };   // UI hints only
  institution: { name: string; shortName: string; logoUrl?: string; logoDarkUrl?: string;
                 faviconUrl?: string; supportEmail?: string; privacyUrl?: string; termsUrl?: string };
  locale: { current: 'tr' | 'en'; available: Array<'tr' | 'en'> };
  budget: { limit: string; spent: string; reserved: string; remaining: string;
            periodEnd: string } | null;                   // decimal strings, formatted client-side
  flash: { success?: string; error?: string };
}
```

- Money travels as **decimal strings** and is formatted with `Intl.NumberFormat`
  (never parsed into floats for arithmetic).
- Conversation list: Inertia deferred prop on the chat layout, paginated
  with merge props (infinite scroll), so first paint is not blocked.
- After a stream completes, the page does a partial reload
  (`router.reload({ only: ['budget', 'conversations'] })`).

## 4. Chat state and streaming

No Redux/Zustand/React Query. Server state comes from Inertia props; the only
client-side state that outlives a render is the in-flight generation, handled
by one hook.

```ts
const { status, draft, run, stop } = useChatStream();
await run(url, body, {
  onStarted: (event) => …,   // new conversation: move to /c/{id}
  onCompleted: (event) => …, // reload the conversation props
  onError: (code) => …,
});
// status: 'idle' | 'streaming'; draft: the streaming answer's text
```

As built (M6), in `resources/js/hooks/use-chat-stream.ts`:

- `fetch(url, { method: 'POST', headers: { 'X-XSRF-TOKEN', Accept: 'text/event-stream' }, body, signal })`;
  the XSRF token is read from Laravel's cookie (`lib/xsrf.ts`).
- A response that is not `text/event-stream` is a refusal before anything was
  stored (JSON with a stable `code`: budget, rate limit, alias not allowed…).
- The body is read via `ReadableStream` and parsed with `eventsource-parser`;
  typed events: `message.started`, `delta`, `message.completed`, `error` (see
  [architecture.md §6](architecture.md#6-streaming-architecture)).
- Deltas are accumulated in a ref and flushed to state with
  `requestAnimationFrame` to avoid re-rendering on every token.
- **Stop:** `POST /chat/messages/{id}/cancel`; the server ends the stream
  with `message.completed` (`status: cancelled`). The fetch is aborted only if
  the stream has not ended 5 seconds later.
- Errors carry stable codes → `t('chat:errors.<code>')`.
- Leaving the page aborts the stream; revisiting shows the persisted partial
  message (the server persisted it).

Components: `pages/chat/{index,show}.tsx`, `components/chat/` (`chat-view`,
`message-item`, `composer`, `markdown`, `code-block`, `conversation-list`) and
`components/models/model-selector.tsx`. The conversation list is a deferred
prop shared by both pages.

## 5. Markdown rendering

- `react-markdown` + `remark-gfm` (tables, task lists, strikethrough).
- **Raw HTML is never rendered**: no `rehype-raw`; `skipHtml` enabled.
- Default `urlTransform` blocks `javascript:`/`data:` URLs; links open with
  `target="_blank" rel="noopener noreferrer nofollow"`.
- Code blocks: language label, copy button, syntax highlighting with
  **Shiki** loaded lazily (dynamic import of the highlighter and only the
  requested language grammars), falling back to plain `<pre>` while loading.
- Streaming performance: completed messages are memoized, so only the
  streaming answer re-renders (at most once per animation frame). Splitting
  it into memoized blocks is left for when long answers show a need.
- Images in model output are not rendered in V1 (text link instead) to avoid
  tracking-pixel leaks.

## 6. Localization (i18n)

Alternatives considered:

| Option | Verdict |
|---|---|
| Laravel PHP lang files shared to React via Inertia props | Sends all strings on every request, no pluralization/namespacing on client. |
| `laravel-react-i18n` (reads Laravel lang files via Vite plugin) | Single source, but small community and fewer features. |
| **`i18next` + `react-i18next` for UI, Laravel lang for backend (chosen)** | Standard, typed keys, namespaces, pluralization (Turkish/English rules), lazy namespaces. |

Split of responsibilities:

- **Backend** (`lang/{en,tr}/*.php`): validation messages, e-mails,
  notifications, server-rendered errors. Inertia validation errors therefore
  arrive already translated.
- **Frontend** (`resources/js/i18n/locales/{en,tr}/<namespace>.json`): all UI
  text. Namespaces: `common`, `auth`, `chat`, `budget`, `settings`, `admin`.
  `admin` is loaded lazily with admin pages.
- **Database content** shown to users (alias name/description) is stored as
  JSON `{tr, en}` and resolved server-side for the current locale.

Enforcement:

- TypeScript resource typing so `t('chat.newConversation')` is type-checked.
- `npm run i18n:check` (`scripts/check-i18n.mjs`, TypeScript compiler API)
  fails CI on JSX text or user-facing attributes (`title`, `placeholder`,
  `aria-label`, …) containing literal text. oxlint has no equivalent rule.
- A Pest test checks that every locale has identical key sets, for both
  `lang/` and the frontend JSON files.

Locale resolution (server, middleware): user preference → institution default
(`InstitutionSettings.default_locale`) → `Accept-Language` → `en`. The current
locale is shared via Inertia; switching language in settings persists to the
user and reloads translations. Dates, numbers and currency use `Intl` with the
active locale and the institution timezone.

## 7. Theming, dark mode and branding

- shadcn CSS variables (`--background`, `--primary`, …) defined in
  `resources/css/app.css` for `:root` and `.dark` form Ada's neutral base
  theme.
- **Dark mode:** `light` / `dark` / `system` via the starter kit's
  `use-appearance` (cookie read server-side to avoid flashes). The choice is
  also saved on `users.appearance` so it follows the user across devices.
- **Institution branding:** the server generates a small style block in
  `app.blade.php` from `InstitutionSettings.primary_color`:

  ```html
  <style nonce="…">
    :root { --primary: oklch(…); --primary-foreground: oklch(…); --ring: oklch(…); --sidebar-primary: oklch(…); }
    .dark { --primary: oklch(…); --primary-foreground: oklch(…); … }
  </style>
  ```

  - Only primary-derived tokens are customizable in V1 (accessible by
    construction; no free-form theme editor).
  - Rules (`App\Domain\Institution\Theme\ThemeTokens`): the colour needs
    ≥ 3:1 contrast against the light background (WCAG 1.4.11, buttons and
    focus rings), otherwise the admin form rejects it with an explanation;
    the dark-mode variant is lightened in OKLCH until it reaches 3:1 on the
    dark background; `--primary-foreground` is near-white or near-black,
    falling back to pure white/black for mid tones, always ≥ 4.5:1.
- Logos (light/dark) and favicon come from settings and are served with
  root-relative URLs (robust behind reverse proxies). The sidebar shows the
  product name with the institution short name underneath; the sign-in page
  shows the institution logo.
- The `admin` translation namespace is loaded lazily by the admin layout
  (`useLazyNamespace`), so it never enters the chat bundle.

## 8. Layout and responsive behaviour

- **Desktop:** collapsible sidebar (new chat, conversations grouped by
  Today / Previous 7 days / Older, budget indicator at the bottom, user menu),
  centred reading column (~768 px) for messages, sticky composer.
- **Mobile:** sidebar becomes a `Sheet`; composer stays pinned above the
  keyboard; model selector in the header.
- Composer V1: auto-growing `Textarea`, Send (Enter; Shift+Enter newline),
  Stop while streaming, `ModelSelector`. No attachment button until uploads
  exist. The composer has named slots for future tools/attachments.
- Budget indicator (as built, M7: `components/budget/budget-indicator.tsx`):
  "Monthly budget · 38% used", a progress bar, and "$6.24 of $10.00 left" —
  or, with the institution's *percentage only* display, "Renews on 1
  November". It links to the usage page (`/usage`) and comes from the shared
  `budget` prop (`BudgetSummary`), refreshed after every answer.
- Budget exhausted: the chat shows a destructive alert with the renewal date
  and a link to the usage page, and the composer is disabled; a refusal with
  `budget_exhausted` reloads the `budget` prop instead of showing a generic
  error.

## 9. Performance

- Pages are resolved with `import.meta.glob` (lazy), so each page is its own
  chunk; **admin pages, charts (shadcn charts / Recharts) and the admin i18n
  namespace never enter the chat bundle**.
- Shiki and language grammars load on demand.
- Budget: chat route initial JS ≤ ~200 kB gzip (checked in CI with a bundle
  size report from M10).
- No animation framework; Tailwind transitions only.
- Fonts are bundled from npm (`@fontsource-variable/instrument-sans`); no
  third-party font CDN at build time or runtime.

## 10. Accessibility

- Keep shadcn/Radix semantics; custom components get visible focus states,
  labels (`aria-label` translated), and keyboard support.
- Message list is an `aria-live="polite"` region updated per completed block,
  not per token, to avoid screen reader spam.
- Colour contrast enforced for branding (§7). Target WCAG 2.1 AA.
- As built (M10): `tests/e2e/accessibility.spec.ts` runs axe-core (WCAG 2.1
  A/AA) on the sign-in page, the chat, usage and settings pages (light and
  dark) and the main administration pages, and fails on serious or critical
  violations. Automated checks find only part of the problems; before a
  release also check by hand: keyboard-only use of the chat (send, stop,
  model menu, sidebar), a screen reader on a streamed answer, 200 % zoom and
  a narrow (320 px) screen.
- Bundle size budget (M10): `npm run bundle:check` after a build (also in
  CI) — the entry (gzipped JS loaded on every page) at most 230 KiB, the
  chat pages 75 KiB, other pages 40 KiB; lazily loaded code (Shiki,
  translations) is not counted. Budgets are raised only deliberately.

## 11. Frontend tests

- **Vitest + Testing Library:** `useChatStream` (event parsing, abort, error
  codes), markdown renderer security (raw HTML, `javascript:` links),
  budget indicator formatting, model selector.
- **Playwright (E2E)** against a mock provider: login (dev login),
  new conversation with streaming, stop generating, budget exhausted, language
  switch, admin model/alias management, admin budget management.
  As built (M6): `tests/e2e/chat.spec.ts` covers the chat flows (streamed
  Markdown answer, stop and partial answer after reload, regenerate, Turkish).
  Ada talks to `tests/e2e/mock-provider.mjs`, a small Node server speaking the
  OpenAI Responses API, configured as a normal provider by
  `tests/e2e/seed.php`; `playwright.config.ts` starts both servers. CI runs it
  as the `e2e` job. Vitest is not set up yet.
