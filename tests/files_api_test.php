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

#[\PHPUnit\Framework\Attributes\CoversClass(files_api::class)]
/**
 * Tests for the fake Files API.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_fakeai\files_api
 */
final class files_api_test extends \advanced_testcase {
    /**
     * Upload returns processed metadata that can be retrieved again; content returns the bytes.
     */
    public function test_create_retrieve_and_content(): void {
        $this->resetAfterTest();
        $reply = files_api::create('%PDF-1.4', 'report.pdf', 'application/pdf', 'user_data');
        $this->assertSame(200, $reply->status);
        $created = $reply->get_json();
        $this->assertStringStartsWith('file-', $created['id']);
        $this->assertSame('file', $created['object']);
        $this->assertSame(8, $created['bytes']);
        $this->assertSame('report.pdf', $created['filename']);
        $this->assertSame('user_data', $created['purpose']);
        $this->assertSame('processed', $created['status']);
        $this->assertArrayNotHasKey('mimetype', $created);

        $this->assertSame($created, files_api::retrieve($created['id'])->get_json());

        $content = files_api::content($created['id']);
        $this->assertSame(200, $content->status);
        $this->assertSame('%PDF-1.4', $content->body);
        $this->assertSame('application/pdf', $content->contenttype);
    }

    /**
     * Moonshot extraction returns the text of a text file in a JSON envelope.
     */
    public function test_content_of_text_file_for_extraction(): void {
        $this->resetAfterTest();
        $id = files_api::create('hello', 'notes.txt', 'text/plain', files_api::PURPOSE_EXTRACT)->get_json()['id'];
        $this->assertSame([
            'content' => 'hello',
            'file_type' => 'text/plain',
            'filename' => 'notes.txt',
            'title' => '',
            'type' => 'file',
        ], files_api::content($id)->get_json());
    }

    /**
     * Moonshot extraction of a binary file returns a placeholder text naming the file.
     */
    public function test_content_of_binary_file_for_extraction(): void {
        $this->resetAfterTest();
        $id = files_api::create("\x89PNG", 'photo.png', 'image/png', files_api::PURPOSE_EXTRACT)->get_json()['id'];
        $this->assertSame(
            script_runner::TEST_PREFIX . 'Extracted text of photo.png (4 bytes)',
            files_api::content($id)->get_json()['content'],
        );
    }

    /**
     * Delete removes the file; later requests for it get 404.
     */
    public function test_delete(): void {
        $this->resetAfterTest();
        $id = files_api::create('x', 'x.txt', 'text/plain', 'user_data')->get_json()['id'];
        $this->assertSame(['id' => $id, 'object' => 'file', 'deleted' => true], files_api::delete($id)->get_json());
        $this->assertSame(404, files_api::retrieve($id)->status);
        $this->assertSame(404, files_api::content($id)->status);
        $this->assertSame(404, files_api::delete($id)->status);
    }

    /**
     * Ids that are not safe file names are treated as unknown.
     */
    public function test_invalid_id_is_not_found(): void {
        $this->assertNull(files_api::get_metadata('../config'));
        $this->assertSame(404, files_api::retrieve('../config')->status);
    }
}
