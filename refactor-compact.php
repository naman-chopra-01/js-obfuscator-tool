<?php
// Usage: php refactor-compact.php <file1> [file2] ...
// Replaces compact('a','b',...) calls with ['a' => $a, 'b' => $b, ...] using
// PHP's own tokenizer, so multi-line argument lists, comments inside the
// call, and quoting style are all handled correctly instead of guessed at
// with regex. Only rewrites calls where every argument is a literal string
// (which is the only form compact() is used with in this codebase, verified
// by grep beforehand) - anything else is left completely untouched.

function tokenText($t)
{
    return is_array($t) ? $t[1] : $t;
}

foreach (array_slice($argv, 1) as $file) {
    $code = file_get_contents($file);
    $tokens = token_get_all($code);
    $output = '';
    $i = 0;
    $n = count($tokens);
    $changedCount = 0;

    while ($i < $n) {
        $token = $tokens[$i];
        $isCompactName = is_array($token) && $token[0] === T_STRING && strtolower($token[1]) === 'compact';

        if ($isCompactName) {
            // Reject if preceded by -> or :: (method/static call, not the global function).
            $p = $i - 1;
            while ($p >= 0 && is_array($tokens[$p]) && in_array($tokens[$p][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) $p--;
            $precededByAccessor = $p >= 0 && ($tokens[$p] === '->' || $tokens[$p] === '::' || (is_array($tokens[$p]) && in_array($tokens[$p][0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true)));

            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
            $hasParen = $j < $n && $tokens[$j] === '(';

            if (!$precededByAccessor && $hasParen) {
                $depth = 1;
                $k = $j + 1;
                $argTokens = [];
                while ($k < $n && $depth > 0) {
                    if ($tokens[$k] === '(') $depth++;
                    elseif ($tokens[$k] === ')') {
                        $depth--;
                        if ($depth === 0) break;
                    }
                    $argTokens[] = $tokens[$k];
                    $k++;
                }
                // $k now indexes the matching ')'. Span to replace/re-emit is i..k inclusive.

                $names = [];
                $allSimpleStrings = true;
                foreach ($argTokens as $t) {
                    if (is_array($t)) {
                        if ($t[0] === T_CONSTANT_ENCAPSED_STRING) {
                            $raw = $t[1];
                            $inner = substr($raw, 1, -1);
                            if ($raw[0] === "'") {
                                $inner = str_replace(["\\\\", "\\'"], ["\\", "'"], $inner);
                            } else {
                                $inner = str_replace(['\\"', '\\\\'], ['"', '\\'], $inner);
                            }
                            $names[] = $inner;
                        } elseif (in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                            // ignore
                        } else {
                            $allSimpleStrings = false;
                        }
                    } else {
                        if ($t !== ',') $allSimpleStrings = false;
                    }
                }

                if ($allSimpleStrings && count($names) > 0) {
                    $pairs = array_map(static fn($name) => "'$name' => \$$name", $names);
                    $output .= '[' . implode(', ', $pairs) . ']';
                    $changedCount++;
                } else {
                    // Not a pure literal-args compact() call - re-emit the original span untouched.
                    for ($m = $i; $m <= $k; $m++) {
                        $output .= tokenText($tokens[$m]);
                    }
                }

                $i = $k + 1;
                continue;
            }
        }

        $output .= tokenText($token);
        $i++;
    }

    if ($changedCount > 0) {
        file_put_contents($file, $output);
    }
    echo "$file: replaced $changedCount compact() call(s)" . PHP_EOL;
}