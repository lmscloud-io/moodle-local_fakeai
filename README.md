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

The **entire user message is the script** — comma-separated commands, no wrapper. Commas inside `[]`, `{}` or strings are preserved.

| Command | Example |
| --- | --- |
| Pause N seconds (`s` optional, `wait` alias) | `sleep 2s` |
| Single tool call | `/get_unix_timestamp` |
| Tool call with JSON5 args | `/get_unix_timestamp{iso8601:"2026-01-01"}` |
| Parallel batch | `[/read_file{path:"a"},/read_file{path:"b"}]` |
| HTTP error (message optional, quotes optional) | `error 429 "rate limited"` |

Arguments are JSON5 — unquoted keys, single-quoted strings, trailing commas, and comments all work. Unparseable segments are dropped silently. When the script runs out of yielding commands, the endpoint emits `"[fakeai] script complete"` so the agent loop terminates.

Combined example:

```
sleep 1s,/get_unix_timestamp,[/read_file{path:"a"},/read_file{path:"b"}],error 429
```

## State across round-trips

Each turn re-sends the full history. The fake finds the **most recent** user message (so a chat session can run several scripts), then counts assistant messages *after* it to pick the next yielding command. Yields are `/tool`, `[…]`, and `error`; `sleep`s before the next yield execute first.

## Talking to the endpoint with curl

Useful for smoke-testing without configuring `aiprovider_openaicompatible`.

```bash
SITE=http://example.com

# Sleep + tool call + sleep.
curl -sS -X POST "$SITE/local/fakeai/endpoint.php/v1/chat/completions" \
  -H 'Content-Type: application/json' \
  -d '{"messages":[{"role":"user","content":"sleep 0s,/get_unix_timestamp{iso8601:\"2026-01-01\"},sleep 0s"}]}'

# Parallel batch.
curl -sS -X POST "$SITE/local/fakeai/endpoint.php/v1/chat/completions" \
  -H 'Content-Type: application/json' \
  -d '{"messages":[{"role":"user","content":"[/toolname1{param:\"value\"},/toolname2]"}]}'

# HTTP error with status code and message.
curl -sS -w "\nHTTP=%{http_code}\n" -X POST "$SITE/local/fakeai/endpoint.php/v1/chat/completions" \
  -H 'Content-Type: application/json' \
  -d '{"messages":[{"role":"user","content":"error 429 \"rate limited\""}]}'
```

## Adding new commands

Parser lives in [classes/command_parser.php](classes/command_parser.php) — add a branch to `parse_segment()` returning a `{action, …}` step. If the new action ends a turn, list it in `script_runner::is_yielding_step()` and implement it in `apply_yield()`; otherwise add it to `apply_side_effect()`.
