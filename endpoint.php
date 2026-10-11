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

/**
 * Fake OpenAI API endpoint: chat completions, Responses API and Files API.
 *
 * Test-only. Configure an OpenAI provider with the base URL
 * "http(s)://<wwwroot>/local/fakeai/endpoint.php/v1" or the chat completions URL
 * "http(s)://<wwwroot>/local/fakeai/endpoint.php/v1/chat/completions". The API path
 * arrives as PATH_INFO and selects the route; anything before the known API path
 * (such as "/v1") is ignored. See classes/script_runner.php for the script grammar.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');

use local_fakeai\files_api;
use local_fakeai\reply;
use local_fakeai\responses_api;
use local_fakeai\script_runner;

header('Content-Type: application/json');

// Accept both PATH_INFO ("endpoint.php/chat/completions") and slasharguments-off
// ("endpoint.php?file=/chat/completions") via the core helper. The full URL including
// "/chat/completions" can also be entered as the endpoint, in which case nothing is
// appended and PATH_INFO is empty.
$path = rtrim((string) get_file_argument(), '/');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'POST');

/**
 * Decoded JSON request body, or an empty array.
 *
 * @return array
 */
function local_fakeai_json_body(): array {
    $body = json_decode((string) file_get_contents('php://input'), true);
    return is_array($body) ? $body : [];
}

if ($path === '' || str_ends_with($path, 'chat/completions')) {
    (new script_runner(local_fakeai_json_body()))->run();
    die;
}

if (preg_match('#(?:^|/)responses(?:/([^/]+))?$#', $path, $m)) {
    $id = $m[1] ?? '';
    if ($id === '' && $method === 'POST') {
        $reply = responses_api::create(local_fakeai_json_body());
    } else if ($id !== '' && $method === 'DELETE') {
        $reply = responses_api::delete($id);
    } else {
        $reply = reply::error(405, "Method $method is not supported on $path.");
    }
} else if (preg_match('#(?:^|/)files(?:/([^/]+)(/content)?)?$#', $path, $m)) {
    $id = $m[1] ?? '';
    $content = !empty($m[2]);
    if ($id === '' && $method === 'POST') {
        $upload = $_FILES['file'] ?? null;
        if (!is_array($upload) || ($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $reply = reply::error(400, "Missing multipart field 'file'.");
        } else {
            $reply = files_api::create(
                (string) file_get_contents($upload['tmp_name']),
                clean_param((string) $upload['name'], PARAM_FILE),
                clean_param((string) ($upload['type'] ?? ''), PARAM_NOTAGS),
                optional_param('purpose', '', PARAM_ALPHANUMEXT),
            );
        }
    } else if ($id !== '' && $content && $method === 'GET') {
        $reply = files_api::content($id);
    } else if ($id !== '' && !$content && $method === 'GET') {
        $reply = files_api::retrieve($id);
    } else if ($id !== '' && !$content && $method === 'DELETE') {
        $reply = files_api::delete($id);
    } else {
        $reply = reply::error(405, "Method $method is not supported on $path.");
    }
} else {
    $reply = reply::error(404, "Unknown fakeai route: $path. Expected '/chat/completions', '/responses' or '/files'.");
}
$reply->send();
