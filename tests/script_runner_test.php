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
 * @covers     \local_fakeai\script_runner::extract_last_user_message
 * @covers     \local_fakeai\script_runner::extract_files_from_conversation
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
}
