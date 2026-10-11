# local_fakeai

Test-only Moodle plugin that imitates the OpenAI API: **Chat Completions**, the **Responses API** and the **Files API**. The model's replies are scripted in the chat message itself. The automated test suite of [LMSCloud AI Agent](https://lmscloud.io/products/ai-agent/) uses it to drive `tool_aiagent` deterministically without calling a real LLM.

> ⚠️ This plugin has no authentication. **Do not install on production.**

## Configuration

Configure the standard **OpenAI API provider** (`aiprovider_openai`) and its **Generate text** action:

| Field | Value |
| ----- | ----- |
| API key | any non-empty string |
| API endpoint | `<wwwroot>/local/fakeai/endpoint.php/v1/chat/completions` |
| Model | any string, for example `fake-model` (on Moodle 5.0+ choose the custom model) |

On Moodle 4.5 these are site-wide settings of the provider plugin. On Moodle 5.0 and later they belong to a provider instance and its action settings.

A provider plugin that asks for a base URL instead of the full chat completions URL takes `<wwwroot>/local/fakeai/endpoint.php/v1`.

The site calls the fake endpoint over HTTP, so the cURL security settings must allow requests to the site's own host and port (**Site administration → General → Security → HTTP security**: *cURL blocked hosts list* and *cURL allowed ports list*).

## Supported API

Paths are relative to `<wwwroot>/local/fakeai/endpoint.php/v1`. Anything before the API path is ignored, so `/v1` is optional.

| Request | Behaviour |
| --- | --- |
| `POST /chat/completions` | Runs the script from the conversation (see [Script syntax](#script-syntax)). |
| `POST /responses` | Runs the same scripts through the Responses API. The fake stores the conversation of every response it creates, so a follow-up that carries only `previous_response_id` and the new input items continues the script. |
| `DELETE /responses/{id}` | Deletes a stored response. |
| `POST /files` | Multipart upload with the fields `file` and `purpose`. Files are reported as `processed` immediately. |
| `GET /files/{id}` | Returns the file metadata. |
| `GET /files/{id}/content` | Returns the file bytes. For `purpose=file-extract` (Moonshot), it returns a JSON envelope `{content, file_type, filename}` instead, with the file's text for text files and a `[fakeai] Extracted text of …` placeholder for anything else. |
| `DELETE /files/{id}` | Deletes a file. |

Uploaded files and stored responses are kept in `$CFG->dataroot/local_fakeai/` and never cleaned up. PHPUnit and Behat start each run with an empty dataroot.

### Responses API and files in tool_aiagent

`tool_aiagent` uses the Responses API with the OpenAI provider when **AI agent settings → OpenAI → API type** is *Responses API* and the model name looks like an OpenAI model (it starts with `gpt` or with `o` and a digit), for example `gpt-fake`. With any other model name it uses Chat Completions.

When a tool reads a file (`read_file`), the OpenAI provider uploads it through the Files API and attaches it to the conversation as a `file` part (Chat Completions) or an `input_file` part (Responses API). The [`files`](#files) command shows which files reached the model.

## Script syntax

Type a comma-separated list of commands into the chat message. Commas inside `[]`, `{}` or strings are preserved. The current page and the attached files list that tool_aiagent appends to a message are stripped before parsing.

| Command | Example |
| --- | --- |
| Pause N seconds (`s` optional, `wait` alias) | `sleep 2s` |
| Single tool call | `/get_unix_timestamp` |
| Tool call with JSON5 args | `/crop_image{file_url:LASTFILE,width:50,height:50}` |
| Parallel batch | `[/get_unix_timestamp,/core_enrol_get_users_courses]` |
| Prose + tool call(s) in one assistant message | `say:Here is the plan,/get_unix_timestamp` |
| HTTP error (message optional, quotes optional) | `error 429 "rate limited"` |
| Self-healing error — errors once, then succeeds on retry | `errorfix 500 "transient"` |
| Final reply listing the files the model received | `files` |
| Final reply naming the API that served the request | `api` |

Arguments use JSON5 — unquoted keys, single-quoted strings, trailing commas, and comments all work. Anything that doesn't match a command is ignored, so casual prose alongside commands is fine.

When the script runs out of commands (or matched none), the chat replies with a random upbeat one-liner — `Well done!`, a fun fact, etc. If the last tool call returned an error or was denied, the reply turns consoling instead (`Bad luck. Try again?`). Under PHPUnit or Behat the reply is prefixed with `[fakeai] ` so tests can match on it deterministically.

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
| `read_file` | `{file_url:LASTFILE}` |

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

### `files`

`files` ends the script with a reply listing the files that reached the model as message content parts, for example `Received files: report.pdf, photo.png`, or `Received no files.` when there were none. Files are named after their upload when the fake holds them, otherwise after the file name or URL in the content part. Under PHPUnit or Behat the reply is prefixed with `[fakeai] `, like the final reply.

A script such as `/read_file,files` therefore checks that a file read by a tool was delivered to the model. The extra user message that carries the file is not treated as a new script, so the script continues after it.

### `api`

`api` ends the script with a reply naming the API that served the request: `Chat Completions` or `Responses API`, prefixed with `[fakeai] ` under PHPUnit or Behat. A scenario can end with `/get_unix_timestamp,api` to check which API the agent used.

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

# Responses API: the first turn returns a function call...
curl -sS -X POST "$SITE/local/fakeai/endpoint.php/v1/responses" \
  -H 'Content-Type: application/json' \
  -d '{"model":"gpt-fake","input":"/get_unix_timestamp,files"}'

# ...and the follow-up sends only the tool output with the previous response id.
curl -sS -X POST "$SITE/local/fakeai/endpoint.php/v1/responses" \
  -H 'Content-Type: application/json' \
  -d '{"model":"gpt-fake","previous_response_id":"resp_…","input":[{"type":"function_call_output","call_id":"call_…","output":"{}"}]}'

# Upload a file, then read it back.
curl -sS -X POST "$SITE/local/fakeai/endpoint.php/v1/files" -F purpose=user_data -F file=@report.pdf
curl -sS "$SITE/local/fakeai/endpoint.php/v1/files/file-…/content" -o report-copy.pdf
```
