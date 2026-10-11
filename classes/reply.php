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
 * HTTP reply produced by {@see files_api} and {@see responses_api}. Handlers return it
 * instead of echoing so tests can inspect status and body; endpoint.php sends it.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class reply {
    /**
     * Constructor.
     *
     * @param int $status HTTP status code.
     * @param string $body Raw response body.
     * @param string $contenttype Value of the Content-Type header.
     */
    public function __construct(
        /** @var int HTTP status code. */
        public readonly int $status,
        /** @var string Raw response body. */
        public readonly string $body,
        /** @var string Value of the Content-Type header. */
        public readonly string $contenttype = 'application/json',
    ) {
    }

    /**
     * A JSON reply.
     *
     * @param array $data Data to encode.
     * @param int $status HTTP status code.
     * @return self
     */
    public static function json(array $data, int $status = 200): self {
        return new self($status, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    /**
     * An OpenAI-shaped error reply.
     *
     * @param int $status HTTP status code.
     * @param string $message Error message.
     * @return self
     */
    public static function error(int $status, string $message): self {
        return self::json(['error' => [
            'message' => $message,
            'type' => 'fakeai_error',
            'code' => (string) $status,
        ]], $status);
    }

    /**
     * The decoded JSON body, or null when the body is not JSON.
     *
     * @return array|null
     */
    public function get_json(): ?array {
        $decoded = json_decode($this->body, true);
        return \is_array($decoded) ? $decoded : null;
    }

    /**
     * Send the reply to the client.
     */
    public function send(): void {
        if (!headers_sent()) {
            http_response_code($this->status);
            header('Content-Type: ' . $this->contenttype);
        }
        echo $this->body;
    }
}
