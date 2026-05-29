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
 * Tests for the command_parser class.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_fakeai\command_parser
 */
final class command_parser_test extends \advanced_testcase {
    /**
     * Data provider for {@see test_parse()}.
     *
     * @return array<string,array{0:string,1:array}>
     */
    public static function parse_provider(): array {
        $stepslist = [
            ['action' => 'wait', 'seconds' => 3],
            ['action' => 'tool_call', 'name' => 'read_file', 'arguments' => ['path' => '/tmp/x']],
        ];
        $stepsjson = json_encode($stepslist);

        return [
            'single line marker' => [
                "prose [[FAKEAI: $stepsjson ]] more prose",
                $stepslist,
            ],
            'multiline JSON' => [
                "prefix\n[[FAKEAI:\n[\n  {\"action\": \"wait\", \"seconds\": 1}\n]\n]]\nsuffix",
                [['action' => 'wait', 'seconds' => 1]],
            ],
            'no marker' => [
                'just regular chat text, no commands here',
                [],
            ],
            'empty input' => [
                '',
                [],
            ],
            'invalid JSON inside marker' => [
                '[[FAKEAI: {not json ]]',
                [],
            ],
            'object instead of array' => [
                '[[FAKEAI: {"action":"wait","seconds":2} ]]',
                [],
            ],
            'empty array' => [
                '[[FAKEAI: [] ]]',
                [],
            ],
            'first marker wins when multiple present' => [
                '[[FAKEAI: [{"action":"wait","seconds":1}] ]] then [[FAKEAI: [{"action":"http_error","status":500}] ]]',
                [['action' => 'wait', 'seconds' => 1]],
            ],
            'marker with surrounding whitespace inside' => [
                "[[FAKEAI:    \n  [{\"action\":\"http_error\",\"status\":429}]   \n]]",
                [['action' => 'http_error', 'status' => 429]],
            ],
        ];
    }

    /**
     * Parser turns a text blob into the expected command list.
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
