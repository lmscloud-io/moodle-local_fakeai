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
 * Fake OpenAI Files API: upload, retrieve metadata, download content, delete.
 *
 * Files are kept in dataroot ({@see storage}) as `<id>.bin` (bytes) and `<id>.json`
 * (metadata). Every upload is reported as already processed. For uploads with the
 * Moonshot `file-extract` purpose, the content endpoint returns Moonshot's JSON envelope
 * with the extracted text instead of the raw bytes.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class files_api {
    /** Storage area for uploaded files. */
    public const AREA = 'files';

    /** Upload purpose that Moonshot (Kimi) uses for text extraction. */
    public const PURPOSE_EXTRACT = 'file-extract';

    /**
     * Store an uploaded file (POST /files).
     *
     * @param string $content File bytes.
     * @param string $filename Original file name.
     * @param string $mimetype MIME type sent with the file part.
     * @param string $purpose Upload purpose (user_data, assistants, file-extract, image, ...).
     * @return reply
     */
    public static function create(string $content, string $filename, string $mimetype, string $purpose): reply {
        $id = storage::new_id('file-');
        $metadata = [
            'id' => $id,
            'object' => 'file',
            'bytes' => \strlen($content),
            'created_at' => time(),
            'filename' => $filename,
            'purpose' => $purpose,
            'status' => 'processed',
            'status_details' => null,
            'mimetype' => $mimetype,
        ];
        file_put_contents(storage::path(self::AREA, $id . '.bin'), $content);
        file_put_contents(storage::path(self::AREA, $id . '.json'), json_encode($metadata));
        return reply::json(self::public_metadata($metadata));
    }

    /**
     * Retrieve file metadata (GET /files/{id}).
     *
     * @param string $id File id.
     * @return reply
     */
    public static function retrieve(string $id): reply {
        $metadata = self::get_metadata($id);
        if ($metadata === null) {
            return self::not_found($id);
        }
        return reply::json(self::public_metadata($metadata));
    }

    /**
     * Download file content (GET /files/{id}/content).
     *
     * @param string $id File id.
     * @return reply
     */
    public static function content(string $id): reply {
        $metadata = self::get_metadata($id);
        if ($metadata === null) {
            return self::not_found($id);
        }
        $content = (string) file_get_contents(storage::path(self::AREA, $id . '.bin'));
        if ($metadata['purpose'] !== self::PURPOSE_EXTRACT) {
            return new reply(200, $content, $metadata['mimetype'] ?: 'application/octet-stream');
        }
        return reply::json([
            'content' => self::extract_text($content, $metadata),
            'file_type' => $metadata['mimetype'],
            'filename' => $metadata['filename'],
            'title' => '',
            'type' => 'file',
        ]);
    }

    /**
     * Delete a file (DELETE /files/{id}).
     *
     * @param string $id File id.
     * @return reply
     */
    public static function delete(string $id): reply {
        if (self::get_metadata($id) === null) {
            return self::not_found($id);
        }
        @unlink(storage::path(self::AREA, $id . '.bin'));
        @unlink(storage::path(self::AREA, $id . '.json'));
        return reply::json(['id' => $id, 'object' => 'file', 'deleted' => true]);
    }

    /**
     * Stored metadata of a file, or null when the id is unknown.
     *
     * @param string $id File id.
     * @return array|null
     */
    public static function get_metadata(string $id): ?array {
        if (!storage::is_valid_id($id)) {
            return null;
        }
        $path = storage::path(self::AREA, $id . '.json');
        if (!file_exists($path)) {
            return null;
        }
        $metadata = json_decode((string) file_get_contents($path), true);
        return \is_array($metadata) ? $metadata : null;
    }

    /**
     * Text that the fake "extracts" from a file: the content itself for text files,
     * a fixed placeholder for anything else.
     *
     * @param string $content File bytes.
     * @param array $metadata Stored metadata.
     * @return string
     */
    protected static function extract_text(string $content, array $metadata): string {
        $mimetype = (string) $metadata['mimetype'];
        $istext = str_starts_with($mimetype, 'text/')
            || \in_array($mimetype, ['application/json', 'application/xml', 'application/csv'], true);
        if ($istext && mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }
        return script_runner::TEST_PREFIX . 'Extracted text of ' . $metadata['filename'] .
            ' (' . $metadata['bytes'] . ' bytes)';
    }

    /**
     * Metadata as returned by the API (without the fake's internal keys).
     *
     * @param array $metadata Stored metadata.
     * @return array
     */
    protected static function public_metadata(array $metadata): array {
        unset($metadata['mimetype']);
        return $metadata;
    }

    /**
     * OpenAI-shaped 404 for an unknown file id.
     *
     * @param string $id File id.
     * @return reply
     */
    protected static function not_found(string $id): reply {
        return reply::error(404, "No such File object: $id");
    }
}
