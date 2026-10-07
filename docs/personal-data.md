# Personal data protection

Ada can check messages for personal data before they are sent to an AI
provider, and warn, mask or block. Administration → **Personal data**
(super administrators). Everything is off until an administrator turns a
check on.

## What is checked

The text a user writes and the extracted text of their attachments (text
files, PDF and Office documents). Images are not checked. Assistant
instructions and documents, written by administrators, are not checked.

Built-in checks:

| Kind | Found when |
|---|---|
| Turkish identity number (T.C. kimlik no) | 11 digits, not starting with 0, whose last two digits are valid check digits |
| IBAN | Any country, with or without spaces in groups of four, and a valid check number (mod 97) |
| Card number | 13–19 digits (spaces or dashes allowed), starting with 2–6, passing the Luhn check |
| Phone number | Turkish mobiles with or without `+90`, `0090` or `0`; landlines with one |
| E-mail address | `name@domain.tld` |

Numbers with check digits are reported only when the check digit is
right, so ordinary numbers (order numbers, years) are rarely mistaken for
them.

**Institution patterns:** up to 20 regular expressions (PCRE, Unicode) for
data of your own, such as student or staff numbers, each with a name and an
action. A pattern that matches an empty text is refused (it would match
everywhere). Patterns run with a low backtracking limit: one that would take
too long finds nothing, and a warning is logged, instead of slowing the
chat. **Try it** on the page checks a sample text with the rules on the
form, saved or not; nothing is stored.

## Actions

| Action | What happens |
|---|---|
| Off | Nothing. |
| Warn | The message is not sent. The user sees which kinds were found and can edit the message or choose **Send anyway**, which sends it unchanged. |
| Mask | The value is replaced with a placeholder such as `[TCKN_1]` before the request leaves Ada; the answer the user sees has the real value back. |
| Block | The message is not sent, even after confirmation. |

When several kinds are found, block wins over warn; masked kinds do not
cause a warning. A warned or blocked message is refused before anything is
stored, counted or charged. The response names the kinds, never the
values.

## How masking works

- Each request is masked as a whole: the new message, the conversation
  history (earlier answers included) and attachment text. The same value
  gets the same placeholder in every message of the request
  (`[TCKN_1]`, `[TCKN_2]`, `[EMAIL_1]`, …; institution patterns use their
  name, e.g. `[OGRENCI_NO_1]`).
- A short note in the system prompt tells the model that placeholders stand
  for real values and should be kept as they are.
- While streaming, Ada puts the values back into the answer, also when a
  placeholder arrives split over several chunks. The stored answer has the
  real values, as the user saw it.
- The mapping exists only in memory for that request; it is never stored.
- The user's message is stored as written. It notes which kinds were
  hidden and how many (`Hidden from the AI model: Turkish identity
  number`), never the values.
- While any kind is masked, PDFs are sent as their extracted text instead
  of as files, so their text can be masked too. Scanned PDFs without text
  are then not sent (the model gets a note).

## Limits

- Detection is pattern based. It finds the formats above, not names,
  addresses or free-form descriptions of people. It is a safety net, not a
  guarantee; the usage notice (Administration → Privacy) should still tell
  users what not to share.
- Images, and text inside images, are not checked.
- The model sees placeholders, so it cannot reason about the values
  themselves (for example check an IBAN's bank code).
- Web search queries the model writes contain placeholders, not values.
- Conversation sharing and export contain the stored text, with the real
  values.
