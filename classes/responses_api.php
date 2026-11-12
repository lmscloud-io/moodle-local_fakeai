<?php
// This file is part of plugin local_fakeai.
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_fakeai;

/**
 * Fake OpenAI Responses API on top of {@see script_runner}.
 *
 * The Responses API is stateful: a follow-up request carries only the new input items and
 * the `previous_response_id`. The fake therefore stores the conversation of every response
 * it creates, as chat completions messages, in dataroot ({@see storage}). A request is
 * served by loading the previous conversation, appending the new input, running the script
 * on the result exactly as for chat completions, and storing the conversation again under
 * the new response id. Errors are not stored, so a retry repeats the failed request.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class responses_api {
    /** Storage area for stored conversations. */
    public const AREA = 'responses';

    /**
     * Create a model response (POST /responses).
     *
     * @param array $request Decoded request body.
     * @return reply
     */
    public static function create(array $request): reply {
        $previousid = (string) ($request['previous_response_id'] ?? '');
        $messages = [];
        if ($previousid !== '') {
            $messages = self::load_messages($previousid);
            if ($messages === null) {
                return reply::error(400, "Previous response with id '$previousid' not found.");
            }
        }
        $messages = self::with_instructions($messages, (string) ($request['instructions'] ?? ''));
        $messages = array_merge($messages, self::input_to_messages($request['input'] ?? ''));

        $model = (string) ($request['model'] ?? 'fakeai');
        $reply = (new script_runner(['model' => $model, 'messages' => $messages], script_runner::API_RESPONSES))->compute();
        if ($reply['type'] === 'error') {
            return reply::error($reply['status'], $reply['message']);
        }

        $id = storage::new_id('resp_');
        $output = [];
        if ($reply['type'] === 'tool_calls') {
            $messages[] = [
                'role' => 'assistant',
                'content' => $reply['content'],
                'tool_calls' => script_runner::chat_tool_calls($reply['calls']),
            ];
            if ($reply['content'] !== null) {
                $output[] = self::message_item($reply['content']);
            }
            foreach ($reply['calls'] as $call) {
                $output[] = [
                    'type' => 'function_call',
                    'id' => storage::new_id('fc_'),
                    'call_id' => $call['id'],
                    'name' => $call['name'],
                    'arguments' => $call['arguments'],
                    'status' => 'completed',
                ];
            }
        } else {
            $messages[] = ['role' => 'assistant', 'content' => $reply['content']];
            $output[] = self::message_item($reply['content']);
        }
        file_put_contents(storage::path(self::AREA, $id . '.json'), json_encode($messages));

        return reply::json([
            'id' => $id,
            'object' => 'response',
            'created_at' => time(),
            'status' => 'completed',
            'model' => $model,
            'previous_response_id' => $previousid !== '' ? $previousid : null,
            'output' => $output,
            'usage' => [
                'input_tokens' => script_runner::PROMPT_TOKENS,
                'output_tokens' => script_runner::COMPLETION_TOKENS,
                'total_tokens' => script_runner::PROMPT_TOKENS + script_runner::COMPLETION_TOKENS,
            ],
        ]);
    }

    /**
     * Delete a stored response (DELETE /responses/{id}).
     *
     * @param string $id Response id.
     * @return reply
     */
    public static function delete(string $id): reply {
        if (self::load_messages($id) === null) {
            return reply::error(404, "Response with id '$id' not found.");
        }
        @unlink(storage::path(self::AREA, $id . '.json'));
        return reply::json(['id' => $id, 'object' => 'response', 'deleted' => true]);
    }

    /**
     * Stored conversation of a response, or null when the id is unknown.
     *
     * @param string $id Response id.
     * @return array|null Chat completions messages.
     */
    public static function load_messages(string $id): ?array {
        if (!storage::is_valid_id($id)) {
            return null;
        }
        $path = storage::path(self::AREA, $id . '.json');
        if (!file_exists($path)) {
            return null;
        }
        $messages = json_decode((string) file_get_contents($path), true);
        return \is_array($messages) ? $messages : null;
    }

    /**
     * Replace the system message with this request's instructions. The Responses API does
     * not carry instructions over from the previous response, so each request sends them.
     *
     * @param array $messages Chat completions messages.
     * @param string $instructions Instructions from the request.
     * @return array
     */
    protected static function with_instructions(array $messages, string $instructions): array {
        $messages = array_values(array_filter($messages, fn(array $msg) => ($msg['role'] ?? '') !== 'system'));
        if ($instructions !== '') {
            array_unshift($messages, ['role' => 'system', 'content' => $instructions]);
        }
        return $messages;
    }

    /**
     * Convert the request's `input` to chat completions messages.
     *
     * A string becomes a user message; `message` items keep their role and content (their
     * `input_text`/`input_file` parts are understood by {@see script_runner});
     * `function_call_output` items become tool messages. Other item types are dropped.
     *
     * @param string|array $input The `input` of the request.
     * @return array
     */
    public static function input_to_messages(string|array $input): array {
        if (\is_string($input)) {
            return $input === '' ? [] : [['role' => 'user', 'content' => $input]];
        }
        $messages = [];
        foreach ($input as $item) {
            if (!\is_array($item)) {
                continue;
            }
            $type = $item['type'] ?? (isset($item['role']) ? 'message' : '');
            if ($type === 'message') {
                $messages[] = ['role' => (string) ($item['role'] ?? 'user'), 'content' => $item['content'] ?? ''];
            } else if ($type === 'function_call_output') {
                $output = $item['output'] ?? '';
                $messages[] = [
                    'role' => 'tool',
                    'tool_call_id' => (string) ($item['call_id'] ?? ''),
                    'content' => \is_string($output) ? $output : json_encode($output),
                ];
            }
        }
        return $messages;
    }

    /**
     * An assistant `message` output item with a single `output_text` part.
     *
     * @param string $text
     * @return array
     */
    protected static function message_item(string $text): array {
        return [
            'type' => 'message',
            'id' => storage::new_id('msg_'),
            'status' => 'completed',
            'role' => 'assistant',
            'content' => [[
                'type' => 'output_text',
                'text' => $text,
                'annotations' => [],
            ]],
        ];
    }
}
