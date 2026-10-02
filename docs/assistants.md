# Assistants

> Status: **Implemented** (v1.2, P4). Fixed documents follow in P5.
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

## Administration

Administration → Assistants (super administrators, like providers, models
and aliases). Changes are audited as `assistant.created` /
`assistant.updated`; the audit entry stores a SHA-256 of the instructions
instead of the text (it can be long, and the form shows the current text).

Assistant instructions are institutional content written by
administrators, not user content: they are visible to super
administrators. Users' conversations with an assistant stay private like
all other conversations.
