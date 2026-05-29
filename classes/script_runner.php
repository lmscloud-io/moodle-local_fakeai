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
 * Reads an OpenAI-shaped chat completions request, extracts a scripted set of
 * steps from the first user message, and emits a deterministic response.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class script_runner {

    /** Regex used to locate the embedded script in the user message. */
    public const SCRIPT_PATTERN = '/\[\[FAKEAI:\s*(.*?)\]\]/s';

    /** Default text returned when the script is exhausted or absent. */
    public const DEFAULT_FINAL_TEXT = '[fakeai] script complete';

    /** @var array Parsed JSON request body. */
    protected array $request;

    public function __construct(array $request) {
        $this->request = $request;
    }

    /**
     * Top-level dispatch: extract script, locate current position, emit response.
     */
    public function run(): void {
        $messages = $this->request['messages'] ?? [];
        [$script, $scriptindex] = $this->find_script($messages);
        $yielded = $this->count_assistant_messages_after($messages, $scriptindex);
        error_log("[fakeai] scriptindex=$scriptindex yielded=$yielded script="
            . json_encode($script));
        $this->execute_from($script, $yielded);
    }

    /**
     * Find the most recent user message containing a [[FAKEAI: ... ]] block.
     * The latest one wins so a chat session can run several independent scripts;
     * counting yields is then scoped to assistant messages emitted after this point.
     *
     * @return array{0: array, 1: int} [script steps, message index] — index -1 if not found
     */
    protected function find_script(array $messages): array {
        $foundscript = [];
        $foundindex = -1;
        foreach ($messages as $i => $msg) {
            if (($msg['role'] ?? '') !== 'user') {
                continue;
            }
            $content = $this->message_content_as_string($msg['content'] ?? '');
            if (preg_match(self::SCRIPT_PATTERN, $content, $m)) {
                $decoded = json_decode(trim($m[1]), true);
                if (\is_array($decoded)) {
                    $foundscript = $decoded;
                    $foundindex = $i;
                }
            }
        }
        return [$foundscript, $foundindex];
    }

    /**
     * OpenAI allows message content to be either a string or an array of parts.
     * Flatten any structure to a single string for script extraction.
     */
    protected function message_content_as_string(mixed $content): string {
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
     * Number of assistant messages after the given index — equal to the number
     * of yielding steps the fake has already emitted for the current script.
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
     */
    protected function is_yielding_step(array $step): bool {
        $action = $step['action'] ?? '';
        return \in_array($action, ['tool_call', 'tool_calls', 'http_error'], true);
    }

    /**
     * Emit a final assistant text response shaped like OpenAI chat completions.
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
