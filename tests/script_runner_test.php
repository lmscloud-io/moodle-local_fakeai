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
 * Tests for the script_runner conversation-inspection helpers.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_fakeai\script_runner
 */
final class script_runner_test extends \advanced_testcase {
    /**
     * Data provider for {@see test_extract_last_user_message()}.
     *
     * @return array<string,array{0:array,1:string}>
     */
    public static function extract_last_user_message_provider(): array {
        global $CFG;
        $root = $CFG->wwwroot;
        return [
            'single user message, no files' => [
                [
                    ['role' => 'system', 'content' => 'sys'],
                    ['role' => 'user', 'content' => 'hello'],
                ],
                'hello',
            ],
            'last user message wins' => [
                [
                    ['role' => 'user', 'content' => 'first'],
                    ['role' => 'assistant', 'content' => 'reply'],
                    ['role' => 'user', 'content' => 'second'],
                ],
                'second',
            ],
            'files section stripped' => [
                [
                    [
                        'role' => 'user',
                        'content' => "look at this\n\nAttached files:\n- a.png (image/png, 1 KB): $root/a.png",
                    ],
                ],
                'look at this',
            ],
            'files section stripped, trailing assistant ignored' => [
                [
                    [
                        'role' => 'user',
                        'content' => "see\n\nAttached files:\n- b.txt (text/plain, 1 B): $root/b",
                    ],
                    ['role' => 'assistant', 'content' => 'ok'],
                    ['role' => 'tool', 'content' => '{"x":1}'],
                ],
                'see',
            ],
            'no user message present' => [
                [
                    ['role' => 'system', 'content' => 'sys'],
                    ['role' => 'assistant', 'content' => 'a'],
                ],
                '',
            ],
            'content as array of parts' => [
                [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => 'first part'],
                            ['type' => 'text', 'text' => 'second part'],
                        ],
                    ],
                ],
                "first part\nsecond part",
            ],
            'attached-files marker without trailing newline keeps text intact' => [
                [
                    // No "\n\nAttached files:" marker — single colon match should not strip anything.
                    ['role' => 'user', 'content' => 'mentions Attached files: but not as marker'],
                ],
                'mentions Attached files: but not as marker',
            ],
            'empty conversation' => [
                [],
                '',
            ],
        ];
    }

    /**
     * Last user message is returned with the trailing "Attached files:" section stripped.
     *
     * @dataProvider extract_last_user_message_provider
     * @param array $messages OpenAI-shaped messages array.
     * @param string $expected Expected stripped text.
     */
    public function test_extract_last_user_message(array $messages, string $expected): void {
        $this->assertSame($expected, script_runner::extract_last_user_message($messages));
    }

    /**
     * Inline cases for {@see test_extract_files_from_conversation()}. The
     * mixed-source fixture lives in tests/fixtures/ — see {@see test_extract_files_from_fixture()}.
     *
     * @return array<string,array{0:array,1:array}>
     */
    public static function extract_files_from_conversation_provider(): array {
        global $CFG;
        $root = $CFG->wwwroot;
        return [
            'empty conversation' => [
                [],
                [],
            ],
            'user with no files, assistant with URL' => [
                [
                    ['role' => 'user', 'content' => 'plain question'],
                    ['role' => 'assistant', 'content' => "see $root/pluginfile.php/x.pdf"],
                ],
                [],
            ],
            'user-only attachments, two files' => [
                [
                    [
                        'role' => 'user',
                        'content' => "hi\n\nAttached files:\n"
                            . "- a.png (image/png, 1 KB, 100x50): $root/a.png\n"
                            . "- b.txt (text/plain, 200 B): $root/b.txt",
                    ],
                ],
                [
                    [
                        'name' => 'a.png',
                        'url' => "$root/a.png",
                        'mime' => 'image/png',
                        'size' => '1 KB',
                        'dimensions' => '100x50',
                        'role' => 'user',
                    ],
                    [
                        'name' => 'b.txt',
                        'url' => "$root/b.txt",
                        'mime' => 'text/plain',
                        'size' => '200 B',
                        'role' => 'user',
                    ],
                ],
            ],
            'tool result with URL inside JSON' => [
                [
                    ['role' => 'tool', 'content' => json_encode(['url' => "$root/foo.pdf", 'size' => 12], JSON_UNESCAPED_SLASHES)],
                ],
                [
                    ['role' => 'tool', 'url' => "$root/foo.pdf"],
                ],
            ],
            'duplicate URLs in same tool message deduped' => [
                [
                    ['role' => 'tool', 'content' => json_encode(['a' => "$root/y", 'b' => "$root/y"], JSON_UNESCAPED_SLASHES)],
                ],
                [
                    ['role' => 'tool', 'url' => "$root/y"],
                ],
            ],
            'multiple distinct URLs preserved in order' => [
                [
                    ['role' => 'tool', 'content' => json_encode([
                        'a' => "$root/1", 'b' => "$root/2", 'c' => "$root/3",
                    ], JSON_UNESCAPED_SLASHES)],
                ],
                [
                    ['role' => 'tool', 'url' => "$root/1"],
                    ['role' => 'tool', 'url' => "$root/2"],
                    ['role' => 'tool', 'url' => "$root/3"],
                ],
            ],
            'tool URLs come after the user attachments that precede them' => [
                [
                    [
                        'role' => 'user',
                        'content' => "first\n\nAttached files:\n- a.txt (text/plain, 1 B): $root/a",
                    ],
                    ['role' => 'tool', 'content' => json_encode(['url' => "$root/b"], JSON_UNESCAPED_SLASHES)],
                    [
                        'role' => 'user',
                        'content' => "second\n\nAttached files:\n- c.txt (text/plain, 1 B): $root/c",
                    ],
                ],
                [
                    ['name' => 'a.txt', 'url' => "$root/a", 'mime' => 'text/plain', 'size' => '1 B', 'role' => 'user'],
                    ['role' => 'tool', 'url' => "$root/b"],
                    ['name' => 'c.txt', 'url' => "$root/c", 'mime' => 'text/plain', 'size' => '1 B', 'role' => 'user'],
                ],
            ],
        ];
    }

    /**
     * Files from user attachments and tool-result URLs are returned chronologically.
     *
     * @dataProvider extract_files_from_conversation_provider
     * @param array $messages OpenAI-shaped messages array.
     * @param array $expected Expected list of file entries in chronological order.
     */
    public function test_extract_files_from_conversation(array $messages, array $expected): void {
        $this->assertSame($expected, script_runner::extract_files_from_conversation($messages));
    }

    /**
     * Mixed-source case loaded from a JSON fixture: user attachments + tool URLs +
     * an assistant URL (which must be ignored). The fixture uses `{wwwroot}` as a
     * placeholder so it doesn't hardcode any particular site URL.
     */
    public function test_extract_files_from_fixture(): void {
        global $CFG;
        $root = $CFG->wwwroot;
        $raw = file_get_contents(__DIR__ . '/fixtures/conversation_with_files_and_tool_urls.json');
        $fixture = json_decode(str_replace('{wwwroot}', $root, $raw), true);

        $files = script_runner::extract_files_from_conversation($fixture['messages']);

        $expected = [
            [
                'name' => 'diagram.png',
                'url' => "$root/pluginfile.php/1/tool_aiagent/attachments/42/diagram.png?token=abc",
                'mime' => 'image/png',
                'size' => '12.3 KB',
                'dimensions' => '800x600',
                'role' => 'user',
            ],
            [
                'name' => 'notes.txt',
                'url' => "$root/pluginfile.php/1/tool_aiagent/attachments/42/notes.txt?token=def",
                'mime' => 'text/plain',
                'size' => '1.2 KB',
                'role' => 'user',
            ],
            [
                'role' => 'tool',
                'url' => "$root/pluginfile.php/1/tool_aiagent/attachments/42/notes.txt?token=def",
            ],
            [
                'role' => 'tool',
                'url' => "$root/pluginfile.php/1/tool_aiagent/attachments/55/report.pdf",
            ],
            [
                'role' => 'tool',
                'url' => "$root/pluginfile.php/1/tool_aiagent/attachments/55/cover.png",
            ],
        ];
        $this->assertSame($expected, $files);
    }

    /**
     * Last attachment URL is the URL of the final file in the chronological list.
     */
    public function test_resolve_last_file_url(): void {
        global $CFG;
        $root = $CFG->wwwroot;
        $messages = [
            ['role' => 'user', 'content' => "first\n\nAttached files:\n- a.txt (text/plain, 1 B): $root/a"],
            ['role' => 'tool', 'content' => '{"url":"' . $root . '/b"}'],
        ];
        $this->assertSame("$root/b", script_runner::resolve_last_file_url($messages));
    }

    /**
     * resolve_last_file_url returns empty string when no file is referenced anywhere.
     */
    public function test_resolve_last_file_url_returns_empty_when_no_files(): void {
        $messages = [
            ['role' => 'user', 'content' => 'plain text'],
            ['role' => 'assistant', 'content' => 'plain reply'],
        ];
        $this->assertSame('', script_runner::resolve_last_file_url($messages));
    }

    /**
     * Current user id is parsed from tool_aiagent's system prompt.
     */
    public function test_resolve_current_user_id(): void {
        $messages = [
            ['role' => 'system', 'content' => "Some preface.\nThe current user ID is: 42\nMore preface."],
            ['role' => 'user', 'content' => 'hi'],
        ];
        $this->assertSame(42, script_runner::resolve_current_user_id($messages));
    }

    /**
     * resolve_current_user_id returns 0 when no system prompt mentions a user id.
     */
    public function test_resolve_current_user_id_returns_zero_when_absent(): void {
        $messages = [
            ['role' => 'system', 'content' => 'No user id here.'],
            ['role' => 'user', 'content' => 'hi'],
        ];
        $this->assertSame(0, script_runner::resolve_current_user_id($messages));
    }

    /**
     * resolve_course_id returns 0 when no visible non-site course exists,
     * picks the first qualifying course otherwise, and skips hidden courses
     * and the site (front page) course.
     */
    public function test_resolve_course_id(): void {
        global $SITE;
        $this->resetAfterTest(true);

        // No courses yet — only the front page exists, which is excluded.
        $this->assertSame(0, script_runner::resolve_course_id());

        // A hidden course must not satisfy the resolver.
        $this->getDataGenerator()->create_course(['visible' => 0]);
        $this->assertSame(0, script_runner::resolve_course_id());

        // The first visible non-site course wins.
        $visible = $this->getDataGenerator()->create_course(['visible' => 1]);
        $resolved = script_runner::resolve_course_id();
        $this->assertSame((int) $visible->id, $resolved);
        $this->assertNotSame((int) $SITE->id, $resolved);
    }

    /**
     * COURSEID sentinel resolves to a real course id inside an arguments structure.
     */
    public function test_resolve_placeholders_with_courseid(): void {
        $this->resetAfterTest(true);
        $course = $this->getDataGenerator()->create_course(['visible' => 1]);

        $args = ['courses' => [['id' => '@COURSEID@', 'visible' => true]]];
        $expected = ['courses' => [['id' => (int) $course->id, 'visible' => true]]];
        $this->assertSame($expected, script_runner::resolve_placeholders($args, []));
    }

    /**
     * Capture the JSON response emitted by run() into a decoded array.
     *
     * @param array $request OpenAI-shaped request body.
     * @return array
     */
    protected function run_and_capture(array $request): array {
        ob_start();
        (new script_runner($request))->run();
        $output = ob_get_clean();
        return json_decode((string) $output, true) ?? [];
    }

    /**
     * First-time errorfix yields an HTTP error and stamps the flag file so a
     * subsequent retry within the TTL window can recover.
     */
    public function test_errorfix_emits_error_on_first_run(): void {
        $this->resetAfterTest();
        $script = 'errorfix 503 "boom"';
        // Make sure no leftover flag confuses the test.
        script_runner::consume_errorfix_flag($script);

        $body = $this->run_and_capture([
            'messages' => [['role' => 'user', 'content' => $script]],
        ]);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('503', $body['error']['code']);
        $this->assertSame('boom', $body['error']['message']);
        $this->assertFileExists(script_runner::errorfix_flag_path($script));
    }

    /**
     * When the flag exists from a recent attempt, errorfix is skipped and the
     * next yield in the script runs instead.
     */
    public function test_errorfix_is_skipped_when_flag_is_fresh_and_next_step_runs(): void {
        $this->resetAfterTest();
        $script = 'errorfix 500,/get_unix_timestamp';
        script_runner::record_errorfix_attempt($script);

        $body = $this->run_and_capture([
            'messages' => [['role' => 'user', 'content' => $script]],
        ]);
        $this->assertArrayNotHasKey('error', $body);
        $this->assertSame('tool_calls', $body['choices'][0]['finish_reason']);
        $this->assertSame(
            'get_unix_timestamp',
            $body['choices'][0]['message']['tool_calls'][0]['function']['name'],
        );
    }

    /**
     * Flag present and errorfix is the only yielding command — fall through to
     * the default "script complete" text response.
     */
    public function test_errorfix_alone_with_flag_emits_default_final_text(): void {
        $this->resetAfterTest();
        $script = 'errorfix 500';
        script_runner::record_errorfix_attempt($script);

        $body = $this->run_and_capture([
            'messages' => [['role' => 'user', 'content' => $script]],
        ]);
        $this->assertArrayNotHasKey('error', $body);
        $this->assertSame('stop', $body['choices'][0]['finish_reason']);
        $this->assertSame(script_runner::DEFAULT_FINAL_TEXT, $body['choices'][0]['message']['content']);
    }

    /**
     * A flag older than ERRORFIX_FLAG_TTL is stale, so errorfix re-fires.
     */
    public function test_errorfix_refires_when_flag_is_stale(): void {
        $this->resetAfterTest();
        $script = 'errorfix 500';
        script_runner::record_errorfix_attempt($script);
        // Backdate the flag so it's treated as expired.
        touch(script_runner::errorfix_flag_path($script), time() - (script_runner::ERRORFIX_FLAG_TTL + 10));

        $body = $this->run_and_capture([
            'messages' => [['role' => 'user', 'content' => $script]],
        ]);
        $this->assertArrayHasKey('error', $body);
        $this->assertSame('500', $body['error']['code']);
    }

    /**
     * consume_errorfix_flag returns true exactly once after a record_errorfix_attempt,
     * and the flag is always removed afterwards so the next call starts fresh.
     */
    public function test_consume_errorfix_flag_returns_true_once_and_clears(): void {
        $this->resetAfterTest();
        $key = 'test_key_' . uniqid();
        script_runner::record_errorfix_attempt($key);
        $this->assertTrue(script_runner::consume_errorfix_flag($key));
        $this->assertFalse(script_runner::consume_errorfix_flag($key));
    }

    /**
     * Attached file metadata changes between the first run and the retry must
     * not break the flag match (the flag key is derived from the stripped text).
     */
    public function test_errorfix_retry_with_different_attached_files_still_matches(): void {
        $this->resetAfterTest();
        $script = 'errorfix 500,/get_unix_timestamp';
        // First attempt — different attachments than the retry.
        $first = $this->run_and_capture([
            'messages' => [[
                'role' => 'user',
                'content' => "$script\n\nAttached files:\n- a.png (image/png, 1 KB): http://example.com/a.png",
            ]],
        ]);
        $this->assertArrayHasKey('error', $first);

        // Retry — different attachments, same script text.
        $second = $this->run_and_capture([
            'messages' => [[
                'role' => 'user',
                'content' => "$script\n\nAttached files:\n- b.png (image/png, 2 KB): http://example.com/b.png",
            ]],
        ]);
        $this->assertArrayNotHasKey('error', $second);
        $this->assertSame('tool_calls', $second['choices'][0]['finish_reason']);
    }

    /**
     * Sentinel strings are substituted recursively in tool argument arrays.
     */
    public function test_resolve_placeholders(): void {
        global $CFG;
        $root = $CFG->wwwroot;
        $messages = [
            ['role' => 'system', 'content' => 'The current user ID is: 7'],
            ['role' => 'user', 'content' => "see\n\nAttached files:\n- a.png (image/png, 1 KB): $root/last.png"],
        ];
        $args = [
            'file_url' => '@LASTFILE@',
            'x' => 0,
            'nested' => ['userid' => '@CURRENTUSER@', 'note' => 'literal'],
            'plain' => 'no token here',
        ];
        $expected = [
            'file_url' => "$root/last.png",
            'x' => 0,
            'nested' => ['userid' => 7, 'note' => 'literal'],
            'plain' => 'no token here',
        ];
        $this->assertSame($expected, script_runner::resolve_placeholders($args, $messages));
    }
}
