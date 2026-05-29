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
 * Fake OpenAI-compatible chat completions endpoint.
 *
 * Test-only. Configure aiprovider_openaicompatible with API endpoint
 * "http(s)://<wwwroot>/local/fakeai/endpoint.php" — the provider will append
 * "chat/completions" as a PATH_INFO suffix, which Apache routes back to this
 * script. The actual sub-path is ignored. See classes/script_runner.php for
 * the script grammar.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('AJAX_SCRIPT', true);
define('NO_DEBUG_DISPLAY', true);

require(__DIR__ . '/../../config.php');

header('Content-Type: application/json');

// Accept both PATH_INFO ("endpoint.php/chat/completions") and slasharguments-off
// ("endpoint.php?file=/chat/completions") via the core helper. The provider also
// allows the full URL including "/chat/completions" to be entered as the
// apiendpoint, in which case nothing is appended and PATH_INFO is empty.
$path = (string) get_file_argument();
if ($path !== '' && !str_ends_with($path, 'chat/completions')) {
    http_response_code(404);
    echo json_encode(['error' => [
        'message' => "Unknown fakeai route: $path. Expected '/chat/completions' or no sub-path.",
        'type' => 'fakeai_error',
        'code' => '404',
    ]]);
    die;
}

$raw = file_get_contents('php://input');
$body = json_decode($raw, true);
if (!is_array($body)) {
    $body = [];
}

(new \local_fakeai\script_runner($body))->run();
