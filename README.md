# local_fakeai

Test-only Moodle plugin that exposes an **OpenAI-compatible chat completions endpoint** whose responses are scripted from the chat message itself. Designed for deterministic manual and Behat testing of `tool_aiagent` (and any other consumer of `aiprovider_openaicompatible`) without calling a real LLM.

> ⚠️ This plugin has no authentication. **Do not install on production.**

## How it works

1. You configure `aiprovider_openaicompatible` to send requests to this plugin's endpoint.
2. In the chat UI (or test scenario), send a message containing an embedded `[[FAKEAI: ... ]]` block with a JSON array of *steps*.
3. The endpoint walks the script across however many round-trips the agent makes, emitting tool calls, waits, or HTTP errors as you specified.

The endpoint is **stateless** — each request includes the full message history, so the fake locates its position by counting assistant messages already present.

## Configuration

In **Site administration → AI → AI providers → OpenAI-compatible**, create or edit an action and set:

| Field | Value |
| ----- | ----- |
| API endpoint | `<wwwroot>/local/fakeai/endpoint.php/v1` |
| API key | any non-empty string (e.g. `fake`) |
| Model | any string (e.g. `fake-model`) |

The provider appends `chat/completions` to the endpoint, producing
`<wwwroot>/local/fakeai/endpoint.php/v1/chat/completions`. Apache routes this back to `endpoint.php` via `PATH_INFO`; Moodle's `get_file_argument()` validates the suffix.

Any path ending in `chat/completions` is accepted, so equivalent forms also work:

- `<wwwroot>/local/fakeai/endpoint.php` (bare — provider appends `chat/completions`)
- `<wwwroot>/local/fakeai/endpoint.php/chat/completions` (provider detects suffix and doesn't double-append)
- `<wwwroot>/local/fakeai/endpoint.php?file=` (slasharguments-off form)

## Script syntax

Embed steps anywhere in the user message:

```
Whatever prose you want.
[[FAKEAI:
[
  {"action": "wait", "seconds": 3},
  {"action": "tool_call", "name": "get_unix_timestamp", "arguments": {"iso8601":"2026-01-01"}},
  {"action": "wait", "seconds": 2}
]
]]
```

The marker `[[FAKEAI: <json> ]]` is parsed with regex `/\[\[FAKEAI:\s*(.*?)\]\]/s` against the first `role: user` message. The JSON between the markers must be a top-level array of step objects.

If the marker is missing or invalid, the fake replies with `"[fakeai] script complete"` immediately so the agent loop terminates cleanly.

## Step types

Steps fall into two categories:

- **Side-effect steps** (`wait`) run before the next yielding step in the same turn.
- **Yielding steps** (`tool_call`, `tool_calls`, `http_error`) end the current turn and produce an HTTP response.

### `wait` — pause before next step

```json
{"action": "wait", "seconds": 3}
```

Calls `sleep($seconds)` server-side. Use to test UI loading states and long-poll behavior. Multiple `wait`s in a row are additive.

### `tool_call` — single tool invocation

```json
{"action": "tool_call", "name": "read_file", "arguments": {"path": "/tmp/x"}}
```

Returns `finish_reason: "tool_calls"` with one tool call. The agent will execute the named tool with the given arguments, then call back with the result. **Sugar** for a single-element `tool_calls`.

### `tool_calls` — multiple parallel tool invocations

```json
{"action": "tool_calls", "calls": [
  {"name": "read_file", "arguments": {"path": "a"}},
  {"name": "read_file", "arguments": {"path": "b"}},
  {"name": "read_file", "arguments": {"path": "c"}},
  {"name": "read_file", "arguments": {"path": "d"}}
]}
```

Returns multiple tool_calls in a single assistant message, mirroring OpenAI's parallel tool-calling. Useful for testing the auto-approve batch UX.

### `http_error` — return a non-2xx response

```json
{"action": "http_error", "status": 500, "message": "Simulated server error"}
```

Sets the HTTP status code and returns an OpenAI-shaped error body:

```json
{"error": {"message": "Simulated server error", "type": "fakeai_error", "code": "500"}}
```

Common values: `429` (rate limit), `401` (auth), `500` (server error), `503` (overload).

### End of script

When the script runs out of yielding steps, the endpoint emits a final assistant text response:

```json
{"choices": [{"message": {"role": "assistant", "content": "[fakeai] script complete"}, "finish_reason": "stop"}]}
```

Any trailing `wait` steps after the last yield still execute before this final response.

## State tracking across round-trips

Each turn re-sends the full history. The fake finds the **most recent** user message containing `[[FAKEAI: ...]]` (so a chat session can run several independent scripts), then counts assistant messages *after* that point to determine which yielding step to emit next:

| Turn | Assistant msgs since current script's user message | Yields |
| ---- | -------------------------------------------------- | ------ |
| 1    | 0                                                  | 0th yield + preceding side-effects |
| 2    | 1                                                  | 1st yield + side-effects between 0th and 1st |
| 3    | 2                                                  | 2nd yield + side-effects between 1st and 2nd |
| …    | …                                                  | … |

When the count exceeds the available yields, the endpoint replies with the default "script complete" text. Previous user messages (with or without scripts) and their assistant responses are ignored.

## End-to-end example

Script:

```
[[FAKEAI: [
  {"action": "wait", "seconds": 1},
  {"action": "tool_call", "name": "get_unix_timestamp", "arguments": {}},
  {"action": "wait", "seconds": 2},
  {"action": "tool_calls", "calls": [
    {"name": "get_unix_timestamp", "arguments": {}},
    {"name": "get_unix_timestamp", "arguments": {}}
  ]},
  {"action": "http_error", "status": 429, "message": "rate-limited"}
] ]]
```

What happens in the chat UI:

1. **Turn 1**: 1s wait, then the agent receives a `get_unix_timestamp` tool call. Agent executes it, sends result back.
2. **Turn 2**: 2s wait, then two parallel `get_unix_timestamp` tool calls. Agent executes both, sends results back.
3. **Turn 3**: HTTP 429 returned. The agent surfaces a rate-limit error in the UI.

## Adding new step types

Step parsing and execution lives in [classes/script_runner.php](classes/script_runner.php):

- Add the action name to `is_yielding_step()` if it ends a turn, otherwise leave it for the side-effect branch.
- Implement the behavior in `apply_yield()` (returns a response) or `apply_side_effect()` (mutates state and continues).
- Document it here under "Step types".

Possible future steps: `set_header`, `partial_response` (truncated JSON), `slow_response` (drip-feed bytes), `echo_message_count`, …
