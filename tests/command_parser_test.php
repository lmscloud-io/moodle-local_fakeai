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
 * Tests for the command_parser grammar.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_fakeai\command_parser
 */
final class command_parser_test extends \advanced_testcase {
    /**
     * Cases for {@see test_parse()}.
     *
     * @return array<string,array{0:string,1:array}>
     */
    public static function parse_provider(): array {
        return [
            'empty string' => ['', []],
            'whitespace only' => ['   ', []],
            'plain prose that matches no command' => ['hello there, how are you', []],

            // Sleep / wait commands.
            'sleep with seconds suffix' => [
                'sleep 2s',
                [['action' => 'wait', 'seconds' => 2.0]],
            ],
            'sleep without suffix' => [
                'sleep 3',
                [['action' => 'wait', 'seconds' => 3.0]],
            ],
            'wait alias' => [
                'wait 5s',
                [['action' => 'wait', 'seconds' => 5.0]],
            ],
            'fractional sleep' => [
                'sleep 1.5s',
                [['action' => 'wait', 'seconds' => 1.5]],
            ],

            // Single tool call.
            'tool without defaults, no args' => [
                '/no_default_tool',
                [['action' => 'tool_call', 'name' => 'no_default_tool', 'arguments' => []]],
            ],
            'tool with default args picks up the default' => [
                '/get_unix_timestamp',
                [[
                    'action' => 'tool_call',
                    'name' => 'get_unix_timestamp',
                    'arguments' => ['iso8601' => '2026-01-01'],
                ]],
            ],
            'tool with json5 args (unquoted key)' => [
                '/get_unix_timestamp{iso8601:"2026-01-01"}',
                [[
                    'action' => 'tool_call',
                    'name' => 'get_unix_timestamp',
                    'arguments' => ['iso8601' => '2026-01-01'],
                ]],
            ],
            'tool with default args, user override wins' => [
                '/get_unix_timestamp{iso8601:"2027-02-15"}',
                [[
                    'action' => 'tool_call',
                    'name' => 'get_unix_timestamp',
                    'arguments' => ['iso8601' => '2027-02-15'],
                ]],
            ],
            'partial user args merge with defaults' => [
                '/crop_image{x:50}',
                [[
                    'action' => 'tool_call',
                    'name' => 'crop_image',
                    'arguments' => [
                        'file_url' => '@LASTFILE@',
                        'x' => 50,
                        'y' => 0,
                        'width' => 100,
                        'height' => 100,
                    ],
                ]],
            ],
            'bare LASTFILE token becomes sentinel' => [
                '/crop_image{file_url:LASTFILE,x:0,y:0,width:50,height:50}',
                [[
                    'action' => 'tool_call',
                    'name' => 'crop_image',
                    'arguments' => [
                        'file_url' => '@LASTFILE@',
                        'x' => 0,
                        'y' => 0,
                        'width' => 50,
                        'height' => 50,
                    ],
                ]],
            ],
            'bare CURRENTUSER token in defaults' => [
                '/core_enrol_get_users_courses',
                [[
                    'action' => 'tool_call',
                    'name' => 'core_enrol_get_users_courses',
                    'arguments' => ['userid' => '@CURRENTUSER@'],
                ]],
            ],
            // Shortcuts.
            '/read expands to core_enrol_get_users_courses with CURRENTUSER' => [
                '/read',
                [[
                    'action' => 'tool_call',
                    'name' => 'core_enrol_get_users_courses',
                    'arguments' => ['userid' => '@CURRENTUSER@'],
                ]],
            ],
            '/readfail expands with userid -1 overriding the default' => [
                '/readfail',
                [[
                    'action' => 'tool_call',
                    'name' => 'core_enrol_get_users_courses',
                    'arguments' => ['userid' => -1],
                ]],
            ],
            '/safe expands to create_files with one canned file' => [
                '/safe',
                [[
                    'action' => 'tool_call',
                    'name' => 'create_files',
                    'arguments' => ['files' => [['filename' => 'a.txt', 'content' => 'a']]],
                ]],
            ],
            '/safefail expands to create_files with empty files array' => [
                '/safefail',
                [[
                    'action' => 'tool_call',
                    'name' => 'create_files',
                    'arguments' => ['files' => []],
                ]],
            ],
            'user arg overrides shortcut canned arg' => [
                '/read{userid:5}',
                [[
                    'action' => 'tool_call',
                    'name' => 'core_enrol_get_users_courses',
                    'arguments' => ['userid' => 5],
                ]],
            ],
            '/write expands to core_course_update_courses with COURSEID' => [
                '/write',
                [[
                    'action' => 'tool_call',
                    'name' => 'core_course_update_courses',
                    'arguments' => ['courses' => [['id' => '@COURSEID@', 'visible' => true]]],
                ]],
            ],
            '/writefail expands to x_mod_folder_update_module with no args' => [
                '/writefail',
                [[
                    'action' => 'tool_call',
                    'name' => 'x_mod_folder_update_module',
                    'arguments' => [],
                ]],
            ],
            'shortcut works inside a parallel batch' => [
                '[/read,/safe]',
                [[
                    'action' => 'tool_calls',
                    'calls' => [
                        ['name' => 'core_enrol_get_users_courses', 'arguments' => ['userid' => '@CURRENTUSER@']],
                        ['name' => 'create_files', 'arguments' => ['files' => [['filename' => 'a.txt', 'content' => 'a']]]],
                    ],
                ]],
            ],

            'unknown bare identifier stays as JSON literal (fails decode)' => [
                // FOO isn't a placeholder and isn't true/false/null, so the
                // JSON5 parser passes it through unquoted, json_decode rejects
                // the whole blob, and arguments fall back to empty.
                '/tool{x:FOO}',
                [['action' => 'tool_call', 'name' => 'tool', 'arguments' => []]],
            ],
            'tool with single-quoted string' => [
                "/tool{msg:'hello world'}",
                [['action' => 'tool_call', 'name' => 'tool', 'arguments' => ['msg' => 'hello world']]],
            ],
            'tool with trailing comma in args' => [
                '/tool{a:1,b:2,}',
                [['action' => 'tool_call', 'name' => 'tool', 'arguments' => ['a' => 1, 'b' => 2]]],
            ],
            'tool with nested object args' => [
                '/tool{outer:{inner:"value"}}',
                [[
                    'action' => 'tool_call',
                    'name' => 'tool',
                    'arguments' => ['outer' => ['inner' => 'value']],
                ]],
            ],
            'tool with comma inside string arg is not split' => [
                '/tool{message:"hello, world"}',
                [[
                    'action' => 'tool_call',
                    'name' => 'tool',
                    'arguments' => ['message' => 'hello, world'],
                ]],
            ],

            // The example from the spec.
            'sleep + tool + sleep sequence' => [
                'sleep 2s,/toolname,sleep 3s',
                [
                    ['action' => 'wait', 'seconds' => 2.0],
                    ['action' => 'tool_call', 'name' => 'toolname', 'arguments' => []],
                    ['action' => 'wait', 'seconds' => 3.0],
                ],
            ],

            // Parallel batches.
            'batch of two bare tools' => [
                '[/t1,/t2]',
                [[
                    'action' => 'tool_calls',
                    'calls' => [
                        ['name' => 't1', 'arguments' => []],
                        ['name' => 't2', 'arguments' => []],
                    ],
                ]],
            ],
            'batch with mixed args from spec example' => [
                'sleep 1s,[/toolname1{param:"value"},/toolname2]',
                [
                    ['action' => 'wait', 'seconds' => 1.0],
                    [
                        'action' => 'tool_calls',
                        'calls' => [
                            ['name' => 'toolname1', 'arguments' => ['param' => 'value']],
                            ['name' => 'toolname2', 'arguments' => []],
                        ],
                    ],
                ],
            ],
            'batch with comma inside a tool arg' => [
                '[/t1{msg:"a,b"},/t2{x:1}]',
                [[
                    'action' => 'tool_calls',
                    'calls' => [
                        ['name' => 't1', 'arguments' => ['msg' => 'a,b']],
                        ['name' => 't2', 'arguments' => ['x' => 1]],
                    ],
                ]],
            ],

            // HTTP errors.
            'error code only' => [
                'error 500',
                [['action' => 'http_error', 'status' => 500, 'message' => 'Simulated error']],
            ],
            'error code with quoted message' => [
                'error 429 "rate limited"',
                [['action' => 'http_error', 'status' => 429, 'message' => 'rate limited']],
            ],
            'error code with unquoted message' => [
                'error 401 unauthorized',
                [['action' => 'http_error', 'status' => 401, 'message' => 'unauthorized']],
            ],

            // Self-healing errorfix command.
            'errorfix code only' => [
                'errorfix 500',
                [['action' => 'errorfix', 'status' => 500, 'message' => 'Simulated error']],
            ],
            'errorfix with quoted message' => [
                'errorfix 429 "transient failure"',
                [['action' => 'errorfix', 'status' => 429, 'message' => 'transient failure']],
            ],
            'errorfix with bare message' => [
                'errorfix 401 unauthorized',
                [['action' => 'errorfix', 'status' => 401, 'message' => 'unauthorized']],
            ],

            // Whitespace tolerance.
            'spaces around commas' => [
                'sleep 1s , /tool , sleep 2s',
                [
                    ['action' => 'wait', 'seconds' => 1.0],
                    ['action' => 'tool_call', 'name' => 'tool', 'arguments' => []],
                    ['action' => 'wait', 'seconds' => 2.0],
                ],
            ],
            'newlines between commands' => [
                "sleep 1s,\n/tool,\nsleep 2s",
                [
                    ['action' => 'wait', 'seconds' => 1.0],
                    ['action' => 'tool_call', 'name' => 'tool', 'arguments' => []],
                    ['action' => 'wait', 'seconds' => 2.0],
                ],
            ],

            // Failure modes that should not blow up.
            'unparseable segments are dropped silently' => [
                'sleep 1s, garbage here, /tool',
                [
                    ['action' => 'wait', 'seconds' => 1.0],
                    ['action' => 'tool_call', 'name' => 'tool', 'arguments' => []],
                ],
            ],
            'malformed json5 args fall back to empty arguments' => [
                '/tool{not valid:::}',
                [['action' => 'tool_call', 'name' => 'tool', 'arguments' => []]],
            ],
        ];
    }

    /**
     * Parser turns a text blob into the expected list of command steps.
     *
     * @dataProvider parse_provider
     * @param string $input Text blob to parse.
     * @param array $expected Expected command list.
     */
    public function test_parse(string $input, array $expected): void {
        $parser = new command_parser();
        $this->assertSame($expected, $parser->parse($input));
    }
}
