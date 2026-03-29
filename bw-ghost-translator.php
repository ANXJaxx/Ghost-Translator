<?php
/**
 * Plugin Name: BW Empire Ghost Translator
 * Description: 100% Safe, 0 DB-Bloat translations using DeepL API, Static Disk Caching, and Translation Bubbles.
 * Version: 1.4.6
 * Author: BW Empire
 */

if (!defined('ABSPATH')) exit;

// ==========================================
// GITHUB AUTO-UPDATER
// ==========================================
require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
    'https://github.com/ANXJaxx/Ghost-Translator',
    __FILE__,
    'Ghost-Translator'
);
$myUpdateChecker->setBranch('main');

// ==========================================
// 1. SETTINGS PAGE
// ==========================================
add_action('admin_menu', 'bw_ghost_translator_menu');
function bw_ghost_translator_menu() {
    add_options_page('Ghost Translator', 'Ghost Translator', 'manage_options', 'bw_ghost_translator', 'bw_ghost_settings_page');
}

add_action('admin_init', 'bw_ghost_register_settings');
function bw_ghost_register_settings() {
    register_setting('bw_ghost_group', 'bw_ghost_deepl_key');
    register_setting('bw_ghost_group', 'bw_ghost_languages'); 
}

function bw_ghost_settings_page() {
    ?>
    <div class="wrap">
        <h2>👻 BW Empire Ghost Translator</h2>
        <p>100% database-free translations. Creates virtual URLs (e.g. <code>/es/</code>) and caches translated HTML to disk.</p>
        <form method="post" action="options.php">
            <?php settings_fields('bw_ghost_group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">DeepL API Key</th>
                    <td><input type="text" name="bw_ghost_deepl_key" value="<?php echo esc_attr(get_option('bw_ghost_deepl_key')); ?>" style="width:400px;" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Target Languages (Comma separated)</th>
                    <td><input type="text" name="bw_ghost_languages" value="<?php echo esc_attr(get_option('bw_ghost_languages', 'ES,FR,DE')); ?>" style="width:400px;" /><br><small>Use standard 2-letter codes. E.g., ES for Spanish.</small></td>
                </tr>
            </table>
            <?php submit_button('Save Settings & Clear Cache'); ?>
        </form>
    </div>
    <?php
    if (isset($_GET['settings-updated'])) {
        $cache_dir = WP_CONTENT_DIR . '/bw-translations/';
        bw_ghost_delete_dir($cache_dir);
    }
}

function bw_ghost_delete_dir($dirPath) {
    if (!is_dir($dirPath)) return;
    $files = glob($dirPath . '*', GLOB_MARK);
    foreach ($files as $file) {
        if (is_dir($file)) bw_ghost_delete_dir($file);
        else unlink($file);
    }
    rmdir($dirPath);
}

// ==========================================
// 2. THE 100% SAFE VIRTUAL URL ROUTER
// ==========================================

// 🚀 RAW PHP REDIRECT: Catch HTTP before WordPress even boots up
add_action('plugins_loaded', 'bw_ghost_force_ssl_early', 1);
function bw_ghost_force_ssl_early() {
    if (!is_ssl() && (!isset($_SERVER['HTTP_X_FORWARDED_PROTO']) || $_SERVER['HTTP_X_FORWARDED_PROTO'] !== 'https')) {
        $uri = $_SERVER['REQUEST_URI'];
        $langs_setting = get_option('bw_ghost_languages', '');
        if (empty($langs_setting)) return;
        
        $langs = array_map('trim', explode(',', strtoupper($langs_setting)));
        foreach ($langs as $lang) {
            if (strpos($uri, '/' . strtolower($lang) . '/') === 0) {
                header("Location: https://" . $_SERVER['HTTP_HOST'] . $uri, true, 301);
                exit;
            }
        }
    }
}

add_filter('do_parse_request', 'bw_ghost_catch_virtual_url', 1, 2);
function bw_ghost_catch_virtual_url($do_parse, $wp) {
    if (is_admin()) return $do_parse;

    $uri = $_SERVER['REQUEST_URI'];
    $langs_setting = get_option('bw_ghost_languages', '');
    if (empty($langs_setting)) return $do_parse;

    $langs = array_map('trim', explode(',', strtoupper($langs_setting)));

    foreach ($langs as $lang) {
        $prefix = '/' . strtolower($lang) . '/';
        if (strpos($uri, $prefix) === 0) {
            define('BW_GHOST_TARGET_LANG', $lang);
            $_SERVER['REQUEST_URI'] = substr($uri, 3); // Strip language code so WP loads English page
            break;
        }
    }
    return $do_parse;
}

// ==========================================
// 3. OUTPUT BUFFER INTERCEPTOR & DEEPL API
// ==========================================
add_action('template_redirect', 'bw_ghost_start_buffer', 0);
function bw_ghost_start_buffer() {
    if (defined('BW_GHOST_TARGET_LANG')) {
        remove_action('template_redirect', 'redirect_canonical');
        ob_start('bw_ghost_translate_html');
    }
}

function bw_ghost_translate_html($html) {
    $api_key = get_option('bw_ghost_deepl_key');
    if (empty($api_key) || empty($html)) return $html;

    $target_lang = BW_GHOST_TARGET_LANG;
    $cache_dir = WP_CONTENT_DIR . '/bw-translations/' . strtolower($target_lang) . '/';
    if (!file_exists($cache_dir)) wp_mkdir_p($cache_dir);
    
    $url_hash = md5($_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);
    $cache_file = $cache_dir . $url_hash . '.html';

    // Serve 0ms static file if we already translated it
    if (file_exists($cache_file)) {
        return file_get_contents($cache_file);
    }

    // THE PAYLOAD STRIPPER (Bypasses DeepL 128KB Limit)
    $placeholders = array();
    $stripped_html = preg_replace_callback(
        '/<(style|script|svg)[^>]*>.*?<\/\1>/is',
        function($matches) use (&$placeholders) {
            $index = count($placeholders);
            $placeholders[$index] = $matches[0];
            return '<bwph id="' . $index . '"></bwph>';
        },
        $html
    );

    $endpoint = strpos($api_key, ':fx') !== false ? 'https://api-free.deepl.com/v2/translate' : 'https://api.deepl.com/v2/translate';

    // STRICT JSON PAYLOAD
    $body_json = wp_json_encode(array(
        'text' => array($stripped_html),
        'target_lang' => strtoupper($target_lang),
        'tag_handling' => 'html', 
        'ignore_tags' => array('bwph', 'code', 'pre') 
    ));

    $response = wp_remote_post($endpoint, array(
        'headers' => array(
            'Authorization' => 'DeepL-Auth-Key ' . trim($api_key), 
            'Content-Type'  => 'application/json'
        ),
        'body'    => $body_json,
        'timeout' => 30 
    ));

    // ULTIMATE SEO FAIL-SAFE: 503 ERROR
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        status_header(503); 
        header('Retry-After: 600'); 
        return $html; // Return English HTML silently
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($data['translations'][0]['text'])) {
        $translated_html = $data['translations'][0]['text'];
        
        // RE-INJECT THE FAT
        $translated_html = preg_replace_callback(
            '/<bwph id="(\d+)".*?<\/bwph>/is',
            function($matches) use ($placeholders) {
                $index = intval($matches[1]);
                return isset($placeholders[$index]) ? $placeholders[$index] : $matches[0];
            },
            $translated_html
        );

        // Swap out the lang tag for SEO
        $translated_html = str_replace('<html lang="en-US">', '<html lang="' . strtolower($target_lang) . '">', $translated_html);

        // ==========================================
        // 🚀 THE "TRANSLATION BUBBLE" (HTTPS FORCED)
        // ==========================================
        $host = $_SERVER['HTTP_HOST'];
        $lang_prefix = '/' . strtolower($target_lang) . '/';

        $translated_html = preg_replace_callback(
            '/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*)>/is',
            function($matches) use ($host, $lang_prefix) {
                $before = $matches[1];
                $url = $matches[2];
                $after = $matches[3];

                // Ignore anchors, emails, phone numbers, and affiliate links (/go/)
                if (strpos($url, '#') === 0 || strpos($url, 'mailto:') === 0 || strpos($url, 'tel:') === 0 || strpos($url, '/go/') !== false || strpos($url, '/wp-content/') !== false) {
                    return $matches[0];
                }

                $is_internal = false;
                $new_url = $url;

                // Check 1: Is it an Absolute Internal Link? (Force HTTPS)
                if (strpos($url, 'http://' . $host) === 0 || strpos($url, 'https://' . $host) === 0) {
                    $is_internal = true;
                    $host_pos = strpos($url, $host) + strlen($host);
                    $path_and_query = substr($url, $host_pos);
                    
                    if (strpos($path_and_query, $lang_prefix) !== 0) {
                        $new_url = 'https://' . $host . $lang_prefix . ltrim($path_and_query, '/');
                    } else {
                        // Even if it has the prefix, force it to HTTPS just in case it was HTTP
                        $new_url = 'https://' . $host . $path_and_query;
                    }
                } 
                // Check 2: Is it a Relative Internal Link? (/about)
                elseif (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
                    $is_internal = true;
                    if (strpos($url, $lang_prefix) !== 0) {
                        $new_url = $lang_prefix . ltrim($url, '/');
                    }
                }

                // If internal, rewrite the HTML tag safely
                if ($is_internal) {
                    return '<a ' . $before . 'href="' . $new_url . '"' . $after . '>';
                }

                return $matches[0]; // External link, do not touch
            },
            $translated_html
        );

        // Save to static disk so we NEVER pay for this translation again
        file_put_contents($cache_file, $translated_html);

        return $translated_html;
    }

    return $html;
}

// ==========================================
// 4. AUTOMATED SEO HREFLANG INJECTION
// ==========================================
add_action('wp_head', 'bw_ghost_hreflang_tags', 1);
function bw_ghost_hreflang_tags() {
    $langs_setting = get_option('bw_ghost_languages', '');
    if (empty($langs_setting)) return;

    $langs = array_map('trim', explode(',', strtolower($langs_setting)));
    
    // 🚀 FORCE HTTPS for SEO Hreflang tags
    $protocol = 'https://';
    $host = $_SERVER['HTTP_HOST'];
    $base_uri = defined('BW_GHOST_TARGET_LANG') ? substr($_SERVER['REQUEST_URI'], 3) : $_SERVER['REQUEST_URI'];
    $base_url = $protocol . $host . $base_uri;

    echo '<link rel="alternate" hreflang="en" href="' . esc_url($base_url) . '" />' . "\n";
    echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($base_url) . '" />' . "\n";

    foreach ($langs as $lang) {
        $foreign_url = $protocol . $host . '/' . $lang . $base_uri;
        echo '<link rel="alternate" hreflang="' . esc_attr($lang) . '" href="' . esc_url($foreign_url) . '" />' . "\n";
    }
}