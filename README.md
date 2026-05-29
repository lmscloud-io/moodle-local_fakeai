# local_fakeai

Test-only Moodle plugin exposing an **OpenAI-compatible chat completions endpoint** whose responses are scripted from the chat message itself. Used in the automated test suite of [LMSCloud AI Agent](https://lmscloud.io/products/ai-agent/) to drive `tool_aiagent` (and any other consumer of [`aiprovider_openaicompatible`](https://github.com/lmscloud-io/moodle-aiprovider_openaicompatible) — also on the [Moodle plugins directory](https://moodle.org/plugins/aiprovider_openaicompatible)) deterministically without calling a real LLM.

> ⚠️ This plugin has no authentication. **Do not install on production.**

## Configuration

In **Site administration → AI → AI providers → OpenAI-compatible**, create or edit an action and set:

| Field | Value |
| ----- | ----- |
| API endpoint | `<wwwroot>/local/fakeai/endpoint.php/v1` |
| API key | any non-empty string |
| Model | any string |

## Script syntax

Type a comma-separated list of commands into the chat message. Commas inside `[]`, `{}` or strings are preserved.

| Command | Example |
| --- | --- |
| Pause N seconds (`s` optional, `wait` alias) | `sleep 2s` |
| Single tool call | `/get_unix_timestamp` |
| Tool call with JSON5 args | `/crop_image{file_url:LASTFILE,width:50,height:50}` |
| Parallel batch | `[/get_unix_timestamp,/core_enrol_get_users_courses]` |
| HTTP error (message optional, quotes optional) | `error 429 "rate limited"` |
| Self-healing error — errors once, then succeeds on retry | `errorfix 500 "transient"` |

Arguments use JSON5 — unquoted keys, single-quoted strings, trailing commas, and comments all work. Anything that doesn't match a command is ignored, so casual prose alongside commands is fine; if no commands match, the chat just replies with `[fakeai] script complete`.

Combined example:

```
sleep 1s,/get_unix_timestamp,[/get_unix_timestamp,/core_enrol_get_users_courses],error 429
```

### Shortcuts

Short aliases expand to a full tool call. You can still pass args to override the canned ones (e.g. `/read{userid:5}`).

| Shortcut | Expands to |
| --- | --- |
| `/read` | `/core_enrol_get_users_courses{userid:CURRENTUSER}` — succeeds for the logged-in user |
| `/readfail` | `/core_enrol_get_users_courses{userid:-1}` — read that fails |
| `/safe` | `/create_files{files:[{filename:"a.txt",content:"a"}]}` — minimal valid write |
| `/safefail` | `/create_files{files:[]}` — write that should be rejected |
| `/write` | `/core_course_update_courses{courses:[{id:COURSEID,visible:true}]}` — write to the first visible non-site course |
| `/writefail` | `/x_mod_folder_update_module` — write tool called with missing required parameters |

### Default arguments

These tools have built-in defaults so the bare `/name` form works out of the box. Any keys you pass override the defaults; missing keys fall back.

| Tool | Default arguments |
| --- | --- |
| `get_unix_timestamp` | `{iso8601:"2026-01-01"}` |
| `crop_image` | `{file_url:LASTFILE, x:0, y:0, width:100, height:100}` |
| `core_enrol_get_users_courses` | `{userid:CURRENTUSER}` |

### Placeholder tokens

Use these bare identifiers anywhere a value is expected (in your args or in the canned defaults above):

| Token | Resolves to |
| --- | --- |
| `LASTFILE` | URL of the most recent attachment in the conversation. Empty string if none. |
| `CURRENTUSER` | Id of the user currently chatting. `0` if not available. |
| `COURSEID` | Id of the first visible non-site course. `0` if there isn't one. |

### `errorfix`

The first call to `errorfix CODE` returns the given HTTP error. If the same message is resubmitted within 60 seconds — e.g. via tool_aiagent's **Try again** button — `errorfix` is skipped and the next command in the script runs instead. After that, the cycle resets.

This makes it easy to script "first attempt fails, retry succeeds" flows like `/tool1, errorfix 500, /tool2`: the first attempt errors after `/tool1`, the retry rolls through `errorfix` and reaches `/tool2`.

## Talking to the endpoint with curl

Useful for smoke-testing without going through the chat UI.

```bash
SITE=http://example.com

# Tool call using its default args.
curl -sS -X POST "$SITE/local/fakeai/endpoint.php/v1/chat/completions" \
  -H 'Content-Type: application/json' \
  -d '{"messages":[{"role":"user","content":"/get_unix_timestamp"}]}'

# Parallel batch.
curl -sS -X POST "$SITE/local/fakeai/endpoint.php/v1/chat/completions" \
  -H 'Content-Type: application/json' \
  -d '{"messages":[{"role":"user","content":"[/get_unix_timestamp,/core_enrol_get_users_courses]"}]}'

# HTTP error with status code and message.
curl -sS -w "\nHTTP=%{http_code}\n" -X POST "$SITE/local/fakeai/endpoint.php/v1/chat/completions" \
  -H 'Content-Type: application/json' \
  -d '{"messages":[{"role":"user","content":"error 429 \"rate limited\""}]}'
```
