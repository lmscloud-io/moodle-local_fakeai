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

#[\PHPUnit\Framework\Attributes\CoversClass(responses_api::class)]
/**
 * Tests for the fake Responses API.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_fakeai\responses_api
 */
final class responses_api_test extends \advanced_testcase {
    /**
     * A script runs across chained responses: the follow-up carries only the tool output and
     * previous_response_id, and the stored conversation supplies the script and position.
     */
    public function test_script_continues_across_chained_responses(): void {
        $this->resetAfterTest();
        $first = responses_api::create([
            'model' => 'gpt-5',
            'instructions' => 'The current user ID is: 7',
            'input' => 'say:Checking,/read,/get_unix_timestamp',
        ]);
        $this->assertSame(200, $first->status);
        $body = $first->get_json();
        $this->assertStringStartsWith('resp_', $body['id']);
        $this->assertSame('response', $body['object']);
        $this->assertCount(2, $body['output']);
        $this->assertSame('message', $body['output'][0]['type']);
        $this->assertSame('Checking', $body['output'][0]['content'][0]['text']);
        $call = $body['output'][1];
        $this->assertSame('function_call', $call['type']);
        $this->assertSame('core_enrol_get_users_courses', $call['name']);
        $this->assertSame('{"userid":7}', $call['arguments']);

        $second = responses_api::create([
            'model' => 'gpt-5',
            'instructions' => 'The current user ID is: 7',
            'previous_response_id' => $body['id'],
            'input' => [
                ['type' => 'function_call_output', 'call_id' => $call['call_id'], 'output' => '{"ok":true}'],
            ],
        ])->get_json();
        $this->assertSame($body['id'], $second['previous_response_id']);
        $this->assertCount(1, $second['output']);
        $this->assertSame('get_unix_timestamp', $second['output'][0]['name']);

        $third = responses_api::create([
            'previous_response_id' => $second['id'],
            'input' => [
                ['type' => 'function_call_output', 'call_id' => $second['output'][0]['call_id'], 'output' => '{}'],
            ],
        ])->get_json();
        $this->assertSame('message', $third['output'][0]['type']);
        $this->assertStringStartsWith(script_runner::TEST_PREFIX, $third['output'][0]['content'][0]['text']);
    }

    /**
     * Files delivered as input_file parts in a user message are listed by `files`.
     */
    public function test_files_command_lists_input_files(): void {
        $this->resetAfterTest();
        $fileid = files_api::create('%PDF', 'report.pdf', 'application/pdf', 'user_data')->get_json()['id'];
        $first = responses_api::create(['input' => '/read_file,files'])->get_json();
        $second = responses_api::create([
            'previous_response_id' => $first['id'],
            'input' => [
                ['type' => 'function_call_output', 'call_id' => $first['output'][0]['call_id'], 'output' => '{}'],
                ['type' => 'message', 'role' => 'user', 'content' => [
                    ['type' => 'input_text', 'text' => 'Contents of report.pdf:'],
                    ['type' => 'input_file', 'file_id' => $fileid],
                ]],
            ],
        ])->get_json();
        $this->assertSame(
            script_runner::TEST_PREFIX . 'Received files: report.pdf',
            $second['output'][0]['content'][0]['text'],
        );
    }

    /**
     * `api` names the Responses API when run through it.
     */
    public function test_api_command(): void {
        $this->resetAfterTest();
        $body = responses_api::create(['input' => 'api'])->get_json();
        $this->assertSame(
            script_runner::TEST_PREFIX . script_runner::API_RESPONSES,
            $body['output'][0]['content'][0]['text'],
        );
    }

    /**
     * Scripted errors are returned with their status and are not stored as responses.
    public function test_error_is_returned_and_not_stored(): void {
        $this->resetAfterTest();
        $reply = responses_api::create(['input' => 'error 429 "slow down"']);
        $this->assertSame(429, $reply->status);
        $this->assertSame('slow down', $reply->get_json()['error']['message']);
    }

    /**
     * An unknown previous_response_id is rejected.
     */
    public function test_unknown_previous_response(): void {
        $reply = responses_api::create(['previous_response_id' => 'resp_missing', 'input' => 'files']);
        $this->assertSame(400, $reply->status);
    }

    /**
     * Delete removes the stored conversation, so it can no longer be continued.
     */
    public function test_delete(): void {
        $this->resetAfterTest();
        $id = responses_api::create(['input' => 'hello'])->get_json()['id'];
        $this->assertSame(['id' => $id, 'object' => 'response', 'deleted' => true], responses_api::delete($id)->get_json());
        $this->assertNull(responses_api::load_messages($id));
        $this->assertSame(404, responses_api::delete($id)->status);
        $this->assertSame(400, responses_api::create(['previous_response_id' => $id, 'input' => 'x'])->status);
    }

    /**
     * Input items map to chat completions messages; unknown item types are dropped.
     */
    public function test_input_to_messages(): void {
        $this->assertSame([], responses_api::input_to_messages(''));
        $this->assertSame([['role' => 'user', 'content' => 'hi']], responses_api::input_to_messages('hi'));
        $this->assertSame([
            ['role' => 'user', 'content' => 'next'],
            ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => '{"a":1}'],
        ], responses_api::input_to_messages([
            ['type' => 'message', 'role' => 'user', 'content' => 'next'],
            ['type' => 'function_call_output', 'call_id' => 'c1', 'output' => '{"a":1}'],
            ['type' => 'reasoning', 'summary' => []],
        ]));
    }
}
