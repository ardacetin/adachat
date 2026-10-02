# Assistants

> Status: **Implemented** (v1.2, P4 and P5).
> Location: `app/Domain/Assistants`, `app/Models/Assistant.php`.

An assistant is a set of instructions written by the institution on top of a
model alias, offered to chosen groups. Examples: a thesis-writing helper
that explains the university's guide, a course assistant that answers in
the style of the course, an IT help desk that knows the local procedures.

## Data

| Table | Purpose |
|---|---|
| `assistants` | `slug` (unique, binary collation), `name` and `description` (JSON, per locale), `instructions` (text), `model_alias_id` (restrict on delete), `starter_prompts` (JSON, at most 4), `icon` (one of `Assistant::ICONS`), `sort_order`, `enabled` |
| `assistant_group` | Groups whose members see the assistant |
| `conversations.assistant_id` | The assistant a conversation was started with (null on delete) |

Assistants are not deleted, only disabled: conversations refer to them.

## Who can use an assistant

`AssistantAccess`: the assistant is enabled, it is assigned to the user's
group, **and** the user may use its model alias (`AliasAccess`: the alias is
enabled, assigned to the group, its model and provider are enabled). If any
of this stops being true, the assistant leaves the gallery and its
conversations cannot continue (403); they stay readable.

## Conversations

- `/assistants` lists the user's assistants; `/assistants/{slug}` opens a new
  conversation with one. The first message carries `assistant_id`; the
  conversation stores it and later messages take it from the conversation.
- The model selector is locked: the assistant's alias is always used, and a
  request with another alias is refused (422).
- The system prompt is the alias system prompt (institutional rules) first,
  then the assistant's instructions, separated by a blank line
  (`ContextBuilder::systemPrompt`).
- Starter prompts are shown as buttons in the empty conversation.
- Budgets work as usual: the instructions are input tokens of every request
  and are counted by the provider's token counter.
- Web search (v1.3): users can turn it on for a message only when the
  assistant allows it ("Users may search the web with this assistant") and
  its alias allows it too; otherwise the request is refused (422).

## Documents

An assistant can carry fixed documents (a guide, a syllabus, regulations).
There is no search over them (no RAG): their whole text is added to the
instructions of **every** request.

- Uploads use the same content checks and text extraction as chat
  attachments (`FileInspector`): PDF, Word, Excel, PowerPoint, text and code
  files; no images. Only the text is used, also for PDFs; a PDF without text
  (scanned) is refused.
- Files are kept on the private disk under `assistants/{assistant}/{uuid}`
  (`assistant_documents` table, cascade with the assistant); a removed
  document's file is deleted, and the retention orphan sweep also covers
  this directory.
- Limits: all documents of an assistant together at most
  `ADA_ASSISTANT_MAX_DOCUMENT_TOKENS` (default 50,000, estimated), and the
  fixed part of a request (alias system prompt, instructions, documents) at
  most half of the model's context window.
- The system prompt becomes: alias system prompt, instructions, a
  "Documents" heading and each document in a fence that its content cannot
  close.
- **Cost:** the documents are input tokens of every message. The form shows
  the fixed tokens per message and their price without caching. With
  Anthropic models the system prompt is marked for prompt caching
  (`cache_control`, five minutes) when the assistant has documents; cached
  reads are billed at the cached input price, which the budget engine
  already uses. OpenAI and Gemini cache long prompts automatically.

## Administration

Administration → Assistants (super administrators, like providers, models
and aliases). Changes are audited as `assistant.created` /
`assistant.updated`, documents as `assistant.document_added` /
`assistant.document_removed` (name and SHA-256); the audit entry stores a SHA-256 of the instructions
instead of the text (it can be long, and the form shows the current text).

Assistant instructions are institutional content written by
administrators, not user content: they are visible to super
administrators. Users' conversations with an assistant stay private like
all other conversations.
