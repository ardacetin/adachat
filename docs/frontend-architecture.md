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
  verification, password reset (authentication is Google-only).
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
const { send, stop, status, draft, error } = useChatStream({
  conversationId,
  onStarted: (ids) => …,           // replace optimistic user message ids
  onCompleted: (result) => …,      // append final assistant message, refresh budget
});
// status: 'idle' | 'connecting' | 'streaming' | 'error'
```

Implementation:

- `fetch(url, { method: 'POST', headers: { 'X-XSRF-TOKEN', Accept: 'text/event-stream' }, body, signal })`.
- Response body read via `ReadableStream`, parsed with `eventsource-parser`
  (~2 kB); typed events: `message.started`, `delta`, `message.completed`,
  `error` (see [architecture.md §6](architecture.md#6-streaming-architecture)).
- Deltas are accumulated in a ref and flushed to state with
  `requestAnimationFrame` to avoid re-rendering on every token.
- **Stop:** `AbortController.abort()` + `POST /messages/{id}/cancel`.
- Errors carry stable codes → `t('chat.errors.<code>')`; budget exhaustion
  shows the `BudgetExhaustedAlert` with reset date.
- Leaving the page aborts the stream; revisiting shows the persisted partial
  message (the server persisted it).

## 5. Markdown rendering

- `react-markdown` + `remark-gfm` (tables, task lists, strikethrough).
- **Raw HTML is never rendered**: no `rehype-raw`; `skipHtml` enabled.
- Default `urlTransform` blocks `javascript:`/`data:` URLs; links open with
  `target="_blank" rel="noopener noreferrer nofollow"`.
- Code blocks: language label, copy button, syntax highlighting with
  **Shiki** loaded lazily (dynamic import of the highlighter and only the
  requested language grammars), falling back to plain `<pre>` while loading.
- Streaming performance: split the message into top-level blocks and memoize
  completed blocks so only the last block re-renders while tokens arrive.
  `streamdown` is evaluated in M6 as an alternative.
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
- Budget indicator: "Monthly usage · $6.24 / $10.00", `Progress`,
  "$3.76 remaining · Resets Oct 1" (localized).

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
- Colour contrast enforced for branding (§7). Target WCAG 2.1 AA; automated
  axe checks in Playwright from M10.

## 11. Frontend tests

- **Vitest + Testing Library:** `useChatStream` (event parsing, abort, error
  codes), markdown renderer security (raw HTML, `javascript:` links),
  budget indicator formatting, model selector.
- **Playwright (E2E)** against a fake provider driver: login (dev login),
  new conversation with streaming, stop generating, budget exhausted, language
  switch, admin model/alias management, admin budget management.
