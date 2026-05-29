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
 * Grammar (comma-separated, top-level commas only split commands — commas
 * inside `[]`, `{}`, or strings are ignored):
 *
 *   sleep N             pause for N seconds (also: `sleep Ns`, `wait Ns`)
 *   /name               invoke tool with no arguments
 *   /name{args}         invoke tool with JSON5 arguments
 *   [/t1, /t2{...}, …]  invoke several tools in parallel
 *   error CODE          return HTTP CODE with a default message
 *   error CODE "msg"    return HTTP CODE with a custom message
 *
 * Unknown commands and trailing garbage are silently ignored so partial typos
 * don't blow up the whole script. Adding a new command shape means adding one
 * branch to {@see parse_segment()} and one shape mapping in the returned step.
 *
 * @package    local_fakeai
 * @copyright  2026 Marina Glancy
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class command_parser {
    /**
     * Parse a text blob into a list of command step arrays.
     *
     * @param string $text
     * @return array<int,array> List of step objects (associative arrays).
     */
    public function parse(string $text): array {
        $segments = $this->split_top_level($text, ',');
        $commands = [];
        foreach ($segments as $seg) {
            $cmd = $this->parse_segment(trim($seg));
            if ($cmd !== null) {
                $commands[] = $cmd;
            }
        }
        return $commands;
    }

    /**
     * Split a string on $sep, but only when the separator appears at depth 0
     * (outside `[]`, `{}`, `()` and outside string literals).
     *
     * @param string $s
     * @param string $sep Single-character separator.
     * @return array<int,string>
     */
    protected function split_top_level(string $s, string $sep): array {
        $segments = [];
        $current = '';
        $depth = 0;
        $instring = false;
        $quote = '';
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $ch = $s[$i];
            if ($instring) {
                $current .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $current .= $s[++$i];
                    continue;
                }
                if ($ch === $quote) {
                    $instring = false;
                }
                continue;
            }
            if ($ch === '"' || $ch === "'") {
                $instring = true;
                $quote = $ch;
                $current .= $ch;
                continue;
            }
            if ($ch === '[' || $ch === '{' || $ch === '(') {
                $depth++;
            } else if ($ch === ']' || $ch === '}' || $ch === ')') {
                $depth--;
            } else if ($ch === $sep && $depth === 0) {
                $segments[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }
        $segments[] = $current;
        return $segments;
    }

    /**
     * Dispatch on the shape of a single trimmed segment.
     *
     * @param string $segment
     * @return array|null Step object, or null to drop the segment silently.
     */
    protected function parse_segment(string $segment): ?array {
        if ($segment === '') {
            return null;
        }
        // Pause command — accepts both sleep and wait keywords, with an optional s suffix.
        if (preg_match('/^(?:sleep|wait)\s+(\d+(?:\.\d+)?)s?$/i', $segment, $m)) {
            return ['action' => 'wait', 'seconds' => (float) $m[1]];
        }
        // HTTP error: `error CODE`, optionally followed by a quoted or bare message.
        if (preg_match('/^error\s+(\d{3})(?:\s+(.+))?$/is', $segment, $m)) {
            $message = isset($m[2]) ? $this->unquote(trim($m[2])) : 'Simulated error';
            return [
                'action' => 'http_error',
                'status' => (int) $m[1],
                'message' => $message,
            ];
        }
        // Parallel batch in square brackets.
        if ($segment[0] === '[' && substr($segment, -1) === ']') {
            return $this->parse_batch($segment);
        }
        // Single tool call, e.g. `/name` or `/name{args}`.
        if ($segment[0] === '/') {
            $call = $this->parse_tool_call($segment);
            if ($call === null) {
                return null;
            }
            return ['action' => 'tool_call', 'name' => $call['name'], 'arguments' => $call['arguments']];
        }
        return null;
    }

    /**
     * Parse `[/t1, /t2{...}, ...]` into a `tool_calls` step.
     *
     * @param string $segment
     * @return array
     */
    protected function parse_batch(string $segment): array {
        $inner = substr($segment, 1, -1);
        $calls = [];
        foreach ($this->split_top_level($inner, ',') as $item) {
            $item = trim($item);
            if ($item === '') {
                continue;
            }
            $call = $this->parse_tool_call($item);
            if ($call !== null) {
                $calls[] = $call;
            }
        }
        return ['action' => 'tool_calls', 'calls' => $calls];
    }

    /**
     * Parse a single `/name` or `/name{args}` token.
     *
     * @param string $segment
     * @return array|null `{name, arguments}` or null if unparseable.
     */
    protected function parse_tool_call(string $segment): ?array {
        if (!preg_match('/^\/([A-Za-z_][\w]*)\s*(\{.*\})?\s*$/s', $segment, $m)) {
            return null;
        }
        $arguments = [];
        if (!empty($m[2])) {
            $arguments = $this->parse_json5($m[2]);
        }
        return ['name' => $m[1], 'arguments' => $arguments];
    }

    /**
     * Lenient JSON parser: accepts unquoted keys, single-quoted strings,
     * trailing commas, and `//` / `/* *\/` comments (the common JSON5
     * relaxations). Returns `[]` on any decode failure.
     *
     * @param string $s
     * @return array
     */
    protected function parse_json5(string $s): array {
        $out = '';
        $len = strlen($s);
        $i = 0;
        while ($i < $len) {
            $ch = $s[$i];

            // Line comment.
            if ($ch === '/' && $i + 1 < $len && $s[$i + 1] === '/') {
                while ($i < $len && $s[$i] !== "\n") {
                    $i++;
                }
                continue;
            }
            // Block comment.
            if ($ch === '/' && $i + 1 < $len && $s[$i + 1] === '*') {
                $i += 2;
                while ($i + 1 < $len && !($s[$i] === '*' && $s[$i + 1] === '/')) {
                    $i++;
                }
                $i += 2;
                continue;
            }
            // Trailing comma — drop if next non-whitespace is `}` or `]`.
            if ($ch === ',') {
                $j = $i + 1;
                while ($j < $len && ctype_space($s[$j])) {
                    $j++;
                }
                if ($j < $len && ($s[$j] === '}' || $s[$j] === ']')) {
                    $i++;
                    continue;
                }
            }
            // Double-quoted string — pass through verbatim.
            if ($ch === '"') {
                $out .= $ch;
                $i++;
                while ($i < $len) {
                    $c = $s[$i++];
                    $out .= $c;
                    if ($c === '\\' && $i < $len) {
                        $out .= $s[$i++];
                        continue;
                    }
                    if ($c === '"') {
                        break;
                    }
                }
                continue;
            }
            // Single-quoted string — convert to double-quoted, re-escaping internals.
            if ($ch === "'") {
                $out .= '"';
                $i++;
                while ($i < $len) {
                    $c = $s[$i++];
                    if ($c === '\\' && $i < $len) {
                        $next = $s[$i++];
                        if ($next === "'") {
                            $out .= "'";
                        } else {
                            $out .= '\\' . $next;
                        }
                        continue;
                    }
                    if ($c === '"') {
                        $out .= '\\"';
                        continue;
                    }
                    if ($c === "'") {
                        $out .= '"';
                        break;
                    }
                    $out .= $c;
                }
                continue;
            }
            // Bare identifier — quote it if it's a key (followed by `:`).
            if (ctype_alpha($ch) || $ch === '_' || $ch === '$') {
                $start = $i;
                while ($i < $len && (ctype_alnum($s[$i]) || $s[$i] === '_' || $s[$i] === '$')) {
                    $i++;
                }
                $ident = substr($s, $start, $i - $start);
                $j = $i;
                while ($j < $len && ctype_space($s[$j])) {
                    $j++;
                }
                if ($j < $len && $s[$j] === ':') {
                    $out .= '"' . $ident . '"';
                } else {
                    $out .= $ident;
                }
                continue;
            }
            $out .= $ch;
            $i++;
        }
        $decoded = json_decode($out, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Strip a single pair of matching outer quotes (single or double).
     *
     * @param string $s
     * @return string
     */
    protected function unquote(string $s): string {
        if (strlen($s) >= 2) {
            $first = $s[0];
            $last = substr($s, -1);
            if (($first === '"' || $first === "'") && $first === $last) {
                return substr($s, 1, -1);
            }
        }
        return $s;
    }
}
