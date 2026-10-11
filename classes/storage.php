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
 * Locations in dataroot where the fake keeps state between requests (uploaded files,
 * stored Responses API conversations). Nothing is ever cleaned up: the plugin is
 * test-only and PHPUnit/Behat start each run with a fresh dataroot.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class storage {
    /**
     * Full path of an object stored in the given area, creating the area directory on demand.
     *
     * @param string $area Subdirectory name, e.g. 'files' or 'responses'.
     * @param string $name File name inside the area. Callers validate ids with {@see is_valid_id()}.
     * @return string
     */
    public static function path(string $area, string $name): string {
        global $CFG;
        $dir = $CFG->dataroot . '/local_fakeai/' . $area;
        if (!is_dir($dir)) {
            make_writable_directory($dir);
        }
        return $dir . '/' . $name;
    }

    /**
     * Whether an id taken from a request URL is safe to use as a file name.
     *
     * @param string $id
     * @return bool
     */
    public static function is_valid_id(string $id): bool {
        return (bool) preg_match('/^[A-Za-z0-9_-]{1,100}$/', $id);
    }

    /**
     * Generate a new random id with the given prefix, e.g. 'file-' or 'resp_'.
     *
     * @param string $prefix
     * @return string
     */
    public static function new_id(string $prefix): string {
        return $prefix . bin2hex(random_bytes(12));
    }
}
