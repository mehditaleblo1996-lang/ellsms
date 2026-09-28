# Prohibited words (#40)

Platform admins keep a list of words or phrases (`/admin/prohibited-words`, table
`ellsms_prohibited_words`). A message whose text contains an **active** entry is not sent on any path,
and nothing is charged for it.

| Path | Behaviour |
|---|---|
| Direct send, public API `POST /messages` | refused before quota or credit is reserved; the API answers `422` with `content: ["prohibited_content"]` |
| Scheduled send, auto-reply | permanent (non-retryable) refusal |
| Bulk / p2p / smart / gradual queued from the panel or API (`bulk_queue_job()`) | the whole job is refused up front, naming how many rows are affected |
| Large-file import analysis | the row counts as invalid and is never priced or queued |
| A row already queued (text changed after the rule was added) | fails in `bulk_send_group()` with a clear reason, uncharged |
| Anything else reaching `dispatch_message_raw()` (e.g. the legacy URL API) | refused there as a safety net |

**Matching.** Both sides are normalized first: Arabic ي/ى/ك → Persian ی/ک, ZWNJ / bidi marks / tatweel /
diacritics removed, digits → Latin, whitespace collapsed, lower-cased. `contains` matches anywhere
(`قمار` also catches `قمارخانه`); `word` matches only a whole word, not glued to another letter or digit
(`bet` does not catch `alphabet`). The page has a "test text" box.

**Logging.** A refusal is logged as `content_policy.refused` with the rule id and path — never the
message text. Rules are cached ~30 s per process, so a change reaches running workers within that.

Same idea as Vesal's `TBL_PROHIBITEDWORDS` (`MessageUtil.contentMessageValidation`), which matches the
raw text only.

Tests: `tests/Integration/ContentPolicyTest.php`, `tests/Integration/ProhibitedWordsHttpTest.php`.
