# Answer feedback

Users rate answers with a thumbs up or down (v1.3). Administrators see how
satisfied users are with each model alias, model and assistant, without
seeing any answer or any user.

## In the chat

- Every finished answer has 👍 and 👎 next to "Copy" and "Regenerate".
- A thumbs down asks for a reason: inaccurate, not helpful, incomplete, too
  long, something else. There is no free text: what users write could
  contain the content of the conversation.
- Clicking the chosen thumb again takes the vote back; choosing the other
  replaces it. One vote per answer.
- `PUT /chat/messages/{message}/feedback` (`rating` = `up` | `down` | null,
  `reason` with `down`), only for the owner of the conversation and only
  for completed answers.

## Data

`message_feedback` holds the rating, the reason and the alias, model and
assistant copied from the answer, and **no user**:

| Column | Notes |
|---|---|
| `message_id` | Unique (one vote per answer); set to NULL when the conversation is deleted |
| `model_alias_id`, `ai_model_id`, `assistant_id` | Copied at voting time, so the totals survive the conversation |
| `rating`, `reason` | `up` / `down`; a reason only with `down` (check constraint) |

When a conversation is deleted (by the user or by retention), its votes
stay as anonymous counts.

## Satisfaction report

Admin → Satisfaction (administrators and super administrators):

- Votes, the share of thumbs up and the reasons for thumbs down, for a range
  of days.
- A breakdown by alias, model or assistant (up to 100 rows).
- A rate is shown only from 5 votes on (`FeedbackStatistics::MIN_VOTES`);
  fewer votes say "too few votes".
- The report is built from `message_feedback` only: no message, conversation,
  user or group appears, and nothing links back to them.
