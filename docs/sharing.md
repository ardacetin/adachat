# Conversation sharing

Users share a read-only copy of a conversation with colleagues (v1.3).
A link shows the conversation **as it was when it was shared**, only to
signed-in, active users of the same Ada installation.

## Sharing

- The conversation toolbar has a "Share" button (owner only). "Create link"
  makes a new link and shows it once, with a copy button: Ada stores only
  the SHA-256 hash of its token (32 random bytes, base64url, 43
  characters), so a lost link cannot be shown again; create a new one.
- The dialog lists the conversation's live links (date, number of views)
  with "Revoke". A conversation can have several links; each is a separate
  snapshot. Revoking a link also drops its copy: only the record (date,
  views) stays.
- Limits, since every link stores the conversation again and every copy
  writes new messages:
  - at most `ADA_SHARE_MAX_LINKS` (default 10) live links per conversation
    (422 "revoke one first");
  - at most `ADA_SHARE_DAILY_LIMIT` (default 50) links and copies together
    per user per 24 hours (429).
- `POST /c/{conversation}/shares` (JSON `{url}`), `DELETE /shares/{share}`.

## What a link shows

The snapshot is taken from the active thread when the link is created:

- user messages and finished (or stopped) answers; failed and empty answers
  are left out;
- the model alias name of each answer and its web sources;
- attached files **by name only**: the files are never served to the viewer
  (`/chat/attachments/{id}` stays owner-only).

Messages written after sharing, a renamed title or a regenerated answer do
not change an existing link. The conversation itself stays private
(`ConversationPolicy` is unchanged).

`GET /s/{token}`:

- Guests are sent to sign in and come back to the link; disabled users are
  signed out (`EnsureUserIsActive`), as everywhere.
- The response carries `Cache-Control: private, no-store`,
  `X-Robots-Tag: noindex, nofollow` and `Referrer-Policy: no-referrer`; the
  page also has a `robots` meta tag.
- Views by anyone but the owner are counted (`view_count`), without who.

## Copy to my conversations

"Copy to my conversations" (`POST /s/{token}/copy`) creates a new
conversation for the viewer with the shared messages, so they can continue
it. The copy respects the viewer's own access:

- the model alias is kept only where the viewer's group may use it
  (`AliasAccess`); otherwise the viewer picks a model when they write;
- the assistant and its instructions are not copied;
- attachments are not copied (a message that had only files gets their
  names as its text).

## Turning it off and deleting

- Admin → Institution → Sharing: "Allow users to share conversations" (on by
  default, `institution.conversation_sharing`). Off: the Share button is
  hidden and **every existing link answers 404**; turning it back on brings
  the links back.
- A deleted conversation revokes its links at once; retention removes the
  shares with the conversation (foreign key cascade). Deleting a user removes
  their shares.
- There is no administrator list of shares: administrators do not see
  conversations, shared or not.

## Data

`conversation_shares`:

| Column | Notes |
|---|---|
| `conversation_id` | Cascade on delete |
| `user_id` | The owner who shared it |
| `token_hash` | SHA-256 of the token, unique |
| `title` | Title when shared |
| `snapshot` | JSON: `model_alias_id`, `messages[]` (`role`, `content`, `alias_id`, `alias` names, `attachments` names, `sources`) |
| `view_count`, `created_at`, `revoked_at` | |

## Audit

`conversation.shared` (conversation ID, number of messages),
`conversation.share_revoked` (conversation ID) and
`conversation.share_copied` (the new conversation's ID, number of
messages). No content, no title, no
token.
