<?php

/**
 * Admin "Maya events" tab under WooCommerce → Status.
 *
 * @package TaniKyuun\MayaGateway\Admin\EventLog
 */

declare(strict_types=1);

namespace TaniKyuun\MayaGateway\Admin\EventLog;

use TaniKyuun\MayaGateway\Util\Logger;

/**
 * Reads the wc-maya-gateway log channel and renders a parsed view of the
 * latest entries — timestamps, levels, messages, decoded context.
 *
 * Designed as a *viewer*, not a search index: we tail the most recent log
 * file (or one the merchant picks from a dropdown) and parse up to N
 * entries. Heavy querying is the job of WC's built-in Logs page.
 *
 * The point of the dedicated tab is signal density: the global WC log page
 * mixes Maya entries with every other extension's logs and forces the
 * merchant to remember the source slug.
 */
final class EventLogPage
{
    public const TAB_SLUG    = 'maya-events';
    public const MAX_ENTRIES = 500;

    /**
     * @var list<string> Filter chip values the form offers.
     */
    public const LEVEL_OPTIONS = [ 'error', 'warning', 'info', 'debug' ];

    public static function register(): void
    {
        add_filter('woocommerce_admin_status_tabs', [ self::class, 'register_tab' ]);
        add_action('woocommerce_admin_status_content_' . self::TAB_SLUG, [ self::class, 'render' ]);
    }

    /**
     * @param array<string,string> $tabs
     * @return array<string,string>
     */
    public static function register_tab(array $tabs): array
    {
        $tabs[ self::TAB_SLUG ] = __('Maya events', 'wc-maya-gateway');
        return $tabs;
    }

    public static function render(): void
    {
        if (! current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('You do not have permission to view this page.', 'wc-maya-gateway'));
        }

        $available_files = self::list_log_files();
        $selected_file   = self::resolve_selected_file($available_files);
        $level_filter    = self::resolve_level_filter();
        $search          = self::resolve_search();

        $entries = [];
        if (null !== $selected_file && is_readable($selected_file)) {
            $contents = (string) file_get_contents($selected_file);
            $entries  = EventLogParser::parse_lines($contents);
            $entries  = EventLogParser::filter_by_level($entries, $level_filter);
            $entries  = EventLogParser::filter_by_search($entries, $search);
            $entries  = self::tail($entries, self::MAX_ENTRIES);
        }

        self::render_html($available_files, $selected_file, $level_filter, $search, $entries);
    }

    /**
     * @return list<string> Absolute file paths, newest first.
     */
    public static function list_log_files(): array
    {
        $dir = self::log_directory();
        if ('' === $dir || ! is_dir($dir)) {
            return [];
        }

        $files = glob(rtrim($dir, '/') . '/' . Logger::SOURCE . '-*.log');
        if (false === $files || [] === $files) {
            return [];
        }

        usort($files, static fn(string $a, string $b): int => strcmp($b, $a));

        return array_values($files);
    }

    /**
     * @param list<array{timestamp: string, level: string, message: string, context: ?array<string,mixed>}> $entries
     * @return list<array{timestamp: string, level: string, message: string, context: ?array<string,mixed>}>
     */
    public static function tail(array $entries, int $count): array
    {
        if ($count <= 0 || count($entries) <= $count) {
            return $entries;
        }
        return array_values(array_slice($entries, -$count));
    }

    /**
     * Resolves which file to show. Honors `?maya_log_file=<basename>` when
     * the basename matches one of the available files; otherwise picks the
     * newest.
     *
     * @param list<string> $available
     */
    private static function resolve_selected_file(array $available): ?string
    {
        if ([] === $available) {
            return null;
        }

        $requested = isset($_GET['maya_log_file']) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            ? sanitize_file_name((string) wp_unslash($_GET['maya_log_file'])) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            : '';

        if ('' !== $requested) {
            foreach ($available as $path) {
                if (basename($path) === $requested) {
                    return $path;
                }
            }
        }

        return $available[0];
    }

    /**
     * @return list<string>
     */
    private static function resolve_level_filter(): array
    {
        if (! isset($_GET['maya_log_levels'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return [];
        }

        $raw = array_map('sanitize_key', (array) wp_unslash($_GET['maya_log_levels'])); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $out = [];
        foreach ($raw as $value) {
            $value = is_string($value) ? strtolower(sanitize_key($value)) : '';
            if (in_array($value, self::LEVEL_OPTIONS, true)) {
                $out[] = $value;
            }
        }
        return array_values(array_unique($out));
    }

    private static function resolve_search(): string
    {
        if (! isset($_GET['maya_log_search'])) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            return '';
        }
        return trim(sanitize_text_field(wp_unslash($_GET['maya_log_search']))); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
    }

    private static function log_directory(): string
    {
        if (defined('WC_LOG_DIR')) {
            return (string) constant('WC_LOG_DIR');
        }

        if (function_exists('wp_upload_dir')) {
            $upload = wp_upload_dir();
            if (is_array($upload) && isset($upload['basedir']) && is_string($upload['basedir'])) {
                return rtrim($upload['basedir'], '/') . '/wc-logs/';
            }
        }

        return '';
    }

    /**
     * @param list<string> $available_files
     * @param list<string> $level_filter
     * @param list<array{timestamp: string, level: string, message: string, context: ?array<string,mixed>}> $entries
     */
    private static function render_html(
        array $available_files,
        ?string $selected_file,
        array $level_filter,
        string $search,
        array $entries,
    ): void {
        echo '<h2>' . esc_html__('Maya events', 'wc-maya-gateway') . '</h2>';
        echo '<p>' . esc_html__('Parsed view of the wc-maya-gateway log channel. Lines that do not match the expected format are skipped.', 'wc-maya-gateway') . '</p>';

        if ([] === $available_files) {
            echo '<p><em>' . esc_html__('No Maya log files yet. Enable Debug log on the Maya settings screen to start capturing events.', 'wc-maya-gateway') . '</em></p>';
            return;
        }

        echo '<form method="get" action="">';
        echo '<input type="hidden" name="page" value="wc-status" />';
        echo '<input type="hidden" name="tab" value="' . esc_attr(self::TAB_SLUG) . '" />';

        echo '<p>';
        echo '<label for="maya_log_file">' . esc_html__('Log file:', 'wc-maya-gateway') . '</label> ';
        echo '<select name="maya_log_file" id="maya_log_file">';
        foreach ($available_files as $path) {
            $basename = basename($path);
            echo '<option value="' . esc_attr($basename) . '"'
                . selected($selected_file, $path, false)
                . '>' . esc_html($basename) . '</option>';
        }
        echo '</select> ';

        echo '<label for="maya_log_search">' . esc_html__('Search:', 'wc-maya-gateway') . '</label> ';
        echo '<input type="search" id="maya_log_search" name="maya_log_search" value="' . esc_attr($search) . '" /> ';

        echo '<input type="submit" class="button" value="' . esc_attr__('Filter', 'wc-maya-gateway') . '" />';
        echo '</p>';

        echo '<p>';
        foreach (self::LEVEL_OPTIONS as $level) {
            echo '<label style="margin-right: 1em">';
            echo '<input type="checkbox" name="maya_log_levels[]" value="' . esc_attr($level) . '" '
                . checked(in_array($level, $level_filter, true), true, false)
                . ' /> ';
            echo esc_html(strtoupper($level));
            echo '</label>';
        }
        echo '</p>';
        echo '</form>';

        if ([] === $entries) {
            echo '<p><em>' . esc_html__('No entries match the current filter.', 'wc-maya-gateway') . '</em></p>';
            return;
        }

        echo '<table class="widefat striped" style="margin-top:1em">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__('Timestamp', 'wc-maya-gateway') . '</th>';
        echo '<th>' . esc_html__('Level', 'wc-maya-gateway') . '</th>';
        echo '<th>' . esc_html__('Message', 'wc-maya-gateway') . '</th>';
        echo '<th>' . esc_html__('Context', 'wc-maya-gateway') . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            echo '<tr>';
            echo '<td><code>' . esc_html($entry['timestamp']) . '</code></td>';
            echo '<td>' . esc_html(strtoupper($entry['level'])) . '</td>';
            echo '<td>' . esc_html($entry['message']) . '</td>';
            echo '<td>';
            if (null !== $entry['context']) {
                $pretty = wp_json_encode($entry['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                echo '<pre style="white-space:pre-wrap;margin:0;font-size:11px">' . esc_html(is_string($pretty) ? $pretty : '') . '</pre>';
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }
}
