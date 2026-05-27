<?php

/**
 * Self-contained POT generator for the wc-maya-gateway text domain.
 *
 * Output:  languages/wc-maya-gateway.pot
 *
 * We can't depend on wp-cli being installed everywhere, and the WordPress
 * tooling around `wp i18n make-pot` requires Composer + the WP test
 * scaffold. So this script does a focused regex extraction across `src/`
 * and `templates/` for the four call shapes we use:
 *
 *  - `__( 'string', 'wc-maya-gateway' )`
 *  - `_e( 'string', 'wc-maya-gateway' )` (we don't use this — listed for
 *    parity)
 *  - `esc_html__( 'string', 'wc-maya-gateway' )`
 *  - `esc_attr__( 'string', 'wc-maya-gateway' )`
 *  - `_n( 'singular', 'plural', $n, 'wc-maya-gateway' )`
 *  - `_x( 'string', 'context', 'wc-maya-gateway' )`
 *
 * Run:
 *
 *     php bin/make-pot.php
 *
 * The output is committed; CI fails if it drifts vs. the source.
 */

declare(strict_types=1);

const TEXT_DOMAIN = 'wc-maya-gateway';
const PLUGIN_NAME = 'WooCommerce Maya Gateway';
const PLUGIN_VER  = '1.0.0';

$root = dirname(__DIR__);
$dirs = [
    $root . '/src',
    $root . '/templates',
    $root . '/wc-maya-payment-gateway.php',
];

$strings = []; // msgid => ['references' => [...], 'plural' => ?, 'context' => ?]

foreach ($dirs as $entry) {
    if (is_file($entry)) {
        collect_from_file($entry, $root, $strings);
    } elseif (is_dir($entry)) {
        foreach (iterate_php($entry) as $file) {
            collect_from_file($file, $root, $strings);
        }
    }
}

ksort($strings);

$pot = build_pot_header() . "\n";
foreach ($strings as $info) {
    if (! empty($info['references'])) {
        foreach ($info['references'] as $ref) {
            $pot .= "#: $ref\n";
        }
    }
    if (isset($info['context'])) {
        $pot .= 'msgctxt ' . php_to_pot_string($info['context']) . "\n";
    }
    $pot .= 'msgid ' . php_to_pot_string($info['msgid']) . "\n";
    if (isset($info['plural'])) {
        $pot .= 'msgid_plural ' . php_to_pot_string($info['plural']) . "\n";
        $pot .= 'msgstr[0] ""' . "\n";
        $pot .= 'msgstr[1] ""' . "\n";
    } else {
        $pot .= 'msgstr ""' . "\n";
    }
    $pot .= "\n";
}

$out = $root . '/languages/' . TEXT_DOMAIN . '.pot';
if (! is_dir(dirname($out))) {
    mkdir(dirname($out), 0o755, true);
}
file_put_contents($out, $pot);

printf("Wrote %d unique strings to %s\n", count($strings), $out);

/**
 * @return iterable<string>
 */
function iterate_php(string $dir): iterable
{
    $rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($rii as $f) {
        if ($f->isFile() && 'php' === strtolower($f->getExtension())) {
            yield $f->getPathname();
        }
    }
}

function collect_from_file(string $path, string $root, array &$strings): void
{
    $contents = (string) file_get_contents($path);
    $rel      = ltrim(str_replace($root, '', $path), '/');

    // Simple-form pattern: __, esc_html__, esc_attr__, esc_html_e, esc_attr_e, _e.
    // Captures the FIRST string literal then the text domain.
    $single = '/\b(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*([\'"])' . preg_quote(TEXT_DOMAIN, '/') . '\3\s*,?\s*\)/s';
    if (preg_match_all($single, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[2] as $i => $match) {
            $msgid = unescape_php_string($match[0], $matches[1][ $i ][0]);
            register_string($strings, $msgid, $rel, line_for_offset($contents, (int) $match[1]));
        }
    }

    // _n($single, $plural, $count, $domain). The $count slot uses a lazy
    // quantifier (`.+?`) because it might itself be a function call with
    // parens; we anchor by the trailing quoted-domain right before an
    // optional comma + `)`.
    $plural = '/\b_n\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*([\'"])((?:\\\\.|(?!\3).)*)\3\s*,.+?,\s*([\'"])' . preg_quote(TEXT_DOMAIN, '/') . '\5\s*,?\s*\)/s';
    if (preg_match_all($plural, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[2] as $i => $match) {
            $msgid    = unescape_php_string($match[0], $matches[1][ $i ][0]);
            $msgid_pl = unescape_php_string($matches[4][ $i ][0], $matches[3][ $i ][0]);
            register_string($strings, $msgid, $rel, line_for_offset($contents, (int) $match[1]), $msgid_pl);
        }
    }

    // _x($text, $context, $domain)
    $context = '/\b(?:_x|esc_html_x|esc_attr_x)\(\s*([\'"])((?:\\\\.|(?!\1).)*)\1\s*,\s*([\'"])((?:\\\\.|(?!\3).)*)\3\s*,\s*([\'"])' . preg_quote(TEXT_DOMAIN, '/') . '\5\s*,?\s*\)/s';
    if (preg_match_all($context, $contents, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches[2] as $i => $match) {
            $msgid = unescape_php_string($match[0], $matches[1][ $i ][0]);
            $ctx   = unescape_php_string($matches[4][ $i ][0], $matches[3][ $i ][0]);
            register_string($strings, $msgid, $rel, line_for_offset($contents, (int) $match[1]), null, $ctx);
        }
    }
}

function register_string(array &$strings, string $msgid, string $rel, int $line, ?string $plural = null, ?string $context = null): void
{
    $key = ($context ?? '') . "\x04" . $msgid;
    if (! isset($strings[ $key ])) {
        $strings[ $key ] = [ 'msgid' => $msgid, 'references' => [], 'plural' => $plural, 'context' => $context ];
    }
    if (null !== $plural && null === $strings[ $key ]['plural']) {
        $strings[ $key ]['plural'] = $plural;
    }
    $ref = $rel . ':' . $line;
    if (! in_array($ref, $strings[ $key ]['references'], true)) {
        $strings[ $key ]['references'][] = $ref;
    }
}

function line_for_offset(string $haystack, int $offset): int
{
    return substr_count(substr($haystack, 0, $offset), "\n") + 1;
}

function unescape_php_string(string $raw, string $quote): string
{
    // PHP single-quoted strings: \\ and \' are the only escapes. Double-
    // quoted strings have richer escapes, but we don't use them in i18n
    // calls anyway. Handle both conservatively.
    if ("'" === $quote) {
        return strtr($raw, [ "\\'" => "'", '\\\\' => '\\' ]);
    }
    return stripcslashes($raw);
}

function php_to_pot_string(string $s): string
{
    $escaped = addcslashes($s, "\0..\37\"\\");
    if (str_contains($escaped, '\n')) {
        // Multi-line strings: each segment on its own line for readability.
        $parts = explode('\n', $escaped);
        $last  = array_pop($parts);
        $out   = '""' . "\n";
        foreach ($parts as $part) {
            $out .= '"' . $part . '\\n"' . "\n";
        }
        $out .= '"' . $last . '"';
        return $out;
    }
    return '"' . $escaped . '"';
}

function build_pot_header(): string
{
    $today  = date('Y-m-d H:iO');
    $plugin = PLUGIN_NAME;
    $ver    = PLUGIN_VER;
    $domain = TEXT_DOMAIN;

    $lines = [
        "# Copyright (C) {$plugin}",
        '# This file is distributed under the GPL-3.0 license.',
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: ' . $plugin . ' ' . $ver . '\n"',
        '"Report-Msgid-Bugs-To: https://github.com/TaniKyuun/woocommerce-maya-gateway/issues\n"',
        '"POT-Creation-Date: ' . $today . '\n"',
        '"MIME-Version: 1.0\n"',
        '"Content-Type: text/plain; charset=UTF-8\n"',
        '"Content-Transfer-Encoding: 8bit\n"',
        '"Plural-Forms: nplurals=2; plural=(n != 1);\n"',
        '"X-Generator: bin/make-pot.php (wc-maya-gateway)\n"',
        '"X-Domain: ' . $domain . '\n"',
    ];
    return implode("\n", $lines) . "\n";
}
