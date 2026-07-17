<?php
// Usage: php find-named-args.php <file1> [file2] ...
// Finds PHP 8 named-argument call sites (identifier: value inside a call)
// using the real tokenizer, so multi-line calls aren't missed by regex.
// Prints file:line and the argument name found.

foreach (array_slice($argv, 1) as $file) {
    $tokens = token_get_all(file_get_contents($file));
    $n = count($tokens);

    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (!is_array($t) || $t[0] !== T_STRING) {
            continue;
        }

        // look ahead past whitespace for ':' that is NOT '::'
        $j = $i + 1;
        while ($j < $n && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if ($j >= $n || $tokens[$j] !== ':') continue;
        // make sure previous non-ws token isn't part of a case/default/label context
        $p = $i - 1;
        while ($p >= 0 && is_array($tokens[$p]) && $tokens[$p][0] === T_WHITESPACE) $p--;
        $prev = $p >= 0 ? $tokens[$p] : null;
        $prevIsCallBoundary = $prev === '(' || $prev === ',';
        if (!$prevIsCallBoundary) continue;

        echo "$file:" . $t[2] . " -> $t[1]\n";
    }
}