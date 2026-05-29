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
 * Fake feedback endpoint for behat tests.
 *
 * Receives JSON POSTs from {@see \tool_aiagent\local\api\feedback::submit()},
 * writes the raw body to `$CFG->dataroot/local_fakeai/last_feedback.json`, and
 * always replies 200 OK. Behat steps can read the captured file to assert what
 * the chat sent. Disabled outside the test harness so production sites never
 * accidentally point feedback here.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('NO_MOODLE_COOKIES', true);
define('AJAX_SCRIPT', true);

require(__DIR__ . '/../../config.php');

// No production guard — local_fakeai is documented as test-only and this
// endpoint is harmless beyond writing to a per-site capture file. The same
// posture as the chat-completions endpoint next door.

$dir = $CFG->dataroot . '/local_fakeai';
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
file_put_contents($dir . '/last_feedback.json', file_get_contents('php://input'));

echo json_encode(['ok' => true]);
