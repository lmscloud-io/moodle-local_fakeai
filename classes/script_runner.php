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
 * Reads an OpenAI-shaped chat completions request, parses commands from the
 * most recent user message, and emits a deterministic response. Conversation
 * inspection (last user text, attached files) lives here so {@see command_parser}
 * stays focused on text-to-step conversion.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class script_runner {
    /** Default text returned when the script is exhausted or absent. */
    public const DEFAULT_FINAL_TEXT = '[fakeai] script complete';

    /** Marker tool_aiagent uses to append attachment metadata to a message. */
    public const ATTACHED_FILES_MARKER = "\n\nAttached files:";

    /** @var array Parsed JSON request body. */
    protected array $request;

    /**
     * Constructor.
     *
     * @param array $request Decoded OpenAI chat completions request body.
     */
    public function __construct(array $request) {
        $this->request = $request;
    }

    /**
     * Top-level dispatch: extract commands, locate current position, emit response.
     */
    public function run(): void {
        $messages = $this->request['messages'] ?? [];
        $lastuserindex = $this->find_last_user_message_index($messages);
        $usertext = self::extract_last_user_message($messages);
        $script = (new command_parser())->parse($usertext);
        $yielded = $this->count_assistant_messages_after($messages, $lastuserindex);
        $this->execute_from($script, $yielded);
    }

    /**
     * Return the text of the most recent user message with the trailing
     * "Attached files:" section (appended by tool_aiagent's
     * `message::get_content_for_provider()`) stripped off.
     *
     * @param array $messages OpenAI-shaped messages array.
     * @return string Empty string if no user message is present.
     */
    public static function extract_last_user_message(array $messages): string {
        for ($i = \count($messages) - 1; $i >= 0; $i--) {
            $msg = $messages[$i];
            if (($msg['role'] ?? '') !== 'user') {
                continue;
            }
            $content = self::message_content_as_string($msg['content'] ?? '');
            return self::strip_attached_files_section($content);
        }
        return '';
    }

    /**
     * Extract all attached files referenced in the conversation, in chronological order.
     *
     * For `role=user` messages: parses the lines following the
     * "Attached files:" marker — format `- name (mime, size[, dims]): url`.
     * For `role=tool` messages: matches any http(s) URL inside the raw content.
     *
     * @param array $messages OpenAI-shaped messages array.
     * @return array<int,array> List of `{role, url, name?, mime?, size?, dimensions?}`.
     */
    public static function extract_files_from_conversation(array $messages): array {
        $files = [];
        foreach ($messages as $msg) {
            $role = $msg['role'] ?? '';
            $content = self::message_content_as_string($msg['content'] ?? '');
            if ($role === 'user') {
                foreach (self::parse_attached_files_section($content) as $file) {
                    $file['role'] = 'user';
                    $files[] = $file;
                }
            } else if ($role === 'tool') {
                foreach (self::extract_urls_from_text($content) as $url) {
                    $files[] = ['role' => 'tool', 'url' => $url];
                }
            }
        }
        return $files;
    }

    /**
     * Strip everything from the "Attached files:" marker onward.
     *
     * @param string $content Raw message content.
     * @return string Content with the attachments section removed.
     */
    protected static function strip_attached_files_section(string $content): string {
        $pos = strpos($content, self::ATTACHED_FILES_MARKER);
        if ($pos === false) {
            return $content;
        }
        return substr($content, 0, $pos);
    }

    /**
     * Parse the "- name (meta): url" lines from a user message's attached-files section.
     *
     * @param string $content Raw message content.
     * @return array<int,array> Entries with `name`, `url`, `mime`, `size`, optional `dimensions`.
     */
    protected static function parse_attached_files_section(string $content): array {
        $pos = strpos($content, self::ATTACHED_FILES_MARKER);
        if ($pos === false) {
            return [];
        }
        $tail = substr($content, $pos + \strlen(self::ATTACHED_FILES_MARKER));
        $result = [];
        foreach (preg_split('/\r?\n/', $tail) as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] !== '-') {
                continue;
            }
            if (!preg_match('/^-\s*(.+?)\s*\((.+?)\):\s*(\S+)$/', $line, $m)) {
                continue;
            }
            $meta = array_map('trim', explode(',', $m[2]));
            $entry = [
                'name' => $m[1],
                'url' => $m[3],
                'mime' => $meta[0] ?? '',
                'size' => $meta[1] ?? '',
            ];
            if (isset($meta[2])) {
                $entry['dimensions'] = $meta[2];
            }
            $result[] = $entry;
        }
        return $result;
    }

    /**
     * Match all http(s) URLs in a free-form text blob.
     *
     * Stops at whitespace and common JSON delimiters so URLs embedded in JSON
     * tool-result payloads come out clean.
     *
     * @param string $text Free-form text to scan.
     * @return array<int,string> Unique URLs in order of first occurrence.
     */
    protected static function extract_urls_from_text(string $text): array {
        if (!preg_match_all('#https?://[^\s"\',)\]}]+#', $text, $m)) {
            return [];
        }
        return array_values(array_unique($m[0]));
    }

    /**
     * OpenAI allows message content to be either a string or an array of parts.
     * Flatten any structure to a single string for parsing.
     *
     * @param mixed $content Raw `content` value from a message object.
     * @return string Flattened text representation.
     */
    protected static function message_content_as_string(mixed $content): string {
        if (\is_string($content)) {
            return $content;
        }
        if (\is_array($content)) {
            $parts = [];
            foreach ($content as $part) {
                if (\is_string($part)) {
                    $parts[] = $part;
                } else if (\is_array($part) && isset($part['text'])) {
                    $parts[] = (string)$part['text'];
                }
            }
            return implode("\n", $parts);
        }
        return '';
    }

    /**
     * Index of the most recent user message, or -1 if none.
     *
     * @param array $messages OpenAI-shaped messages array.
     * @return int
     */
    protected function find_last_user_message_index(array $messages): int {
        for ($i = \count($messages) - 1; $i >= 0; $i--) {
            if (($messages[$i]['role'] ?? '') === 'user') {
                return $i;
            }
        }
        return -1;
    }

    /**
     * Number of assistant messages after the given index — equal to the number
     * of yielding steps the fake has already emitted for the current script.
     *
     * @param array $messages OpenAI-shaped messages array.
     * @param int $afterindex Position of the current script's user message.
     * @return int
     */
    protected function count_assistant_messages_after(array $messages, int $afterindex): int {
        $count = 0;
        foreach ($messages as $i => $msg) {
            if ($i <= $afterindex) {
                continue;
            }
            if (($msg['role'] ?? '') === 'assistant') {
                $count++;
            }
        }
        return $count;
    }

    /**
     * Walk the script, locate the yielding step this turn should emit, apply
     * any side-effects between the previous yield and that step, then yield.
     *
     * @param array $script Parsed list of step objects.
     * @param int $alreadyyielded Number of yields already emitted in prior round-trips.
     */
    protected function execute_from(array $script, int $alreadyyielded): void {
        $yieldcount = 0;
        $startfrom = 0;
        $yieldindex = -1;
        $count = \count($script);
        for ($i = 0; $i < $count; $i++) {
            if ($this->is_yielding_step($script[$i])) {
                if ($yieldcount === $alreadyyielded) {
                    $yieldindex = $i;
                    break;
                }
                $yieldcount++;
                $startfrom = $i + 1;
            }
        }

        $end = ($yieldindex >= 0) ? $yieldindex : $count;
        for ($j = $startfrom; $j < $end; $j++) {
            $this->apply_side_effect($script[$j]);
        }

        if ($yieldindex < 0) {
            $this->emit_text(self::DEFAULT_FINAL_TEXT);
            return;
        }
        $this->apply_yield($script[$yieldindex]);
    }

    /**
     * Run a non-yielding step (e.g. wait). Unknown steps no-op.
     *
     * @param array $step Step object.
     */
    protected function apply_side_effect(array $step): void {
        $action = $step['action'] ?? '';
        if ($action === 'wait') {
            $seconds = max(0, (int)($step['seconds'] ?? 0));
            if ($seconds > 0) {
                sleep($seconds);
            }
        }
    }

    /**
     * Run a yielding step (tool_call, tool_calls, http_error) and emit response.
     *
     * @param array $step Step object.
     */
    protected function apply_yield(array $step): void {
        $action = $step['action'] ?? '';
        switch ($action) {
            case 'tool_call':
                $this->emit_tool_calls([[
                    'name' => (string)($step['name'] ?? 'unknown_tool'),
                    'arguments' => $step['arguments'] ?? [],
                ]]);
                return;

            case 'tool_calls':
                $calls = $step['calls'] ?? [];
                if (!\is_array($calls) || empty($calls)) {
                    $this->emit_text(self::DEFAULT_FINAL_TEXT);
                    return;
                }
                $this->emit_tool_calls($calls);
                return;

            case 'http_error':
                $this->emit_http_error(
                    (int)($step['status'] ?? 500),
                    (string)($step['message'] ?? 'Simulated error'),
                );
                return;
        }
    }

    /**
     * Yielding steps end the turn and trigger another round-trip (or terminate).
     *
     * @param array $step Step object.
     * @return bool
     */
    protected function is_yielding_step(array $step): bool {
        $action = $step['action'] ?? '';
        return \in_array($action, ['tool_call', 'tool_calls', 'http_error'], true);
    }

    /**
     * Emit a final assistant text response shaped like OpenAI chat completions.
     *
     * @param string $content Assistant message body.
     */
    protected function emit_text(string $content): void {
        $body = [
            'id' => 'fakeai-' . uniqid('', true),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $this->request['model'] ?? 'fakeai',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => $content,
                ],
                'finish_reason' => 'stop',
            ]],
            'usage' => [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ],
        ];
        echo json_encode($body);
    }

    /**
     * Emit one or more tool_calls in a single assistant message.
     *
     * @param array $calls list of ['name' => string, 'arguments' => array]
     */
    protected function emit_tool_calls(array $calls): void {
        $toolcalls = [];
        foreach (array_values($calls) as $i => $call) {
            $args = $call['arguments'] ?? [];
            $toolcalls[] = [
                'id' => 'call_' . uniqid('', true) . '_' . $i,
                'type' => 'function',
                'function' => [
                    'name' => (string)($call['name'] ?? 'unknown_tool'),
                    'arguments' => json_encode(\is_array($args) ? $args : []),
                ],
            ];
        }
        $body = [
            'id' => 'fakeai-' . uniqid('', true),
            'object' => 'chat.completion',
            'created' => time(),
            'model' => $this->request['model'] ?? 'fakeai',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => $toolcalls,
                ],
                'finish_reason' => 'tool_calls',
            ]],
            'usage' => [
                'prompt_tokens' => 0,
                'completion_tokens' => 0,
                'total_tokens' => 0,
            ],
        ];
        echo json_encode($body);
    }

    /**
     * Emit an OpenAI-shaped error response with a non-2xx status.
     *
     * @param int $status HTTP status code to emit.
     * @param string $message Error message echoed to the client.
     */
    protected function emit_http_error(int $status, string $message): void {
        http_response_code($status);
        $body = [
            'error' => [
                'message' => $message,
                'type' => 'fakeai_error',
                'code' => (string)$status,
            ],
        ];
        echo json_encode($body);
    }
}
