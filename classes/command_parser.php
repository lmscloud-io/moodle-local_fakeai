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
 * Convert a user-message text blob into a list of fakeai command steps.
 *
 * Currently understands the `[[FAKEAI: <json> ]]` marker whose body is a JSON
 * array of step objects. New command syntaxes (shorthand DSL, multiple markers,
 * etc.) should land here so {@see script_runner} can stay focused on dispatch.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class command_parser {
    /** Regex matching the marker and capturing the JSON body. */
    public const MARKER_PATTERN = '/\[\[FAKEAI:\s*(.*?)\]\]/s';

    /**
     * Parse a text blob into a list of command step arrays.
     *
     * Returns an empty array when no marker is present or the JSON body is
     * malformed / not a top-level array. If multiple markers are present,
     * only the first is decoded — callers wanting different semantics should
     * preprocess before calling.
     *
     * @param string $text
     * @return array<int,array> List of step objects (associative arrays).
     */
    public function parse(string $text): array {
        if (!preg_match(self::MARKER_PATTERN, $text, $m)) {
            return [];
        }
        $decoded = json_decode(trim($m[1]), true);
        if (!\is_array($decoded)) {
            return [];
        }
        // Reject {"action":"..."} (associative) — a single step object isn't a list.
        if (!array_is_list($decoded)) {
            return [];
        }
        return $decoded;
    }
}
