<?php
/**
 * Plugin Name: BW Empire Ghost Translator
 * Description: 100% Safe, 0 DB-Bloat translations using DeepL API and Static Disk Caching.
 * Version: 1.2.0
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

    // 🚀 NEW: THE PAYLOAD STRIPPER (Bypasses DeepL 128KB Limit)
    // Temporarily extract all heavy CSS, Scripts, and SVG icons.
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

    $body = array(
        'auth_key' => $api_key,
        'text' => $stripped_html, // Send the skinny version!
        'target_lang' => $target_lang,
        'tag_handling' => 'html', 
        'ignore_tags' => 'bwph,code,pre' // Tell DeepL to ignore our placeholders and code blocks
    );

    $response = wp_remote_post($endpoint, array(
        'body' => $body,
        'timeout' => 30 // Give it 30 seconds just to be safe
    ));

    // 🚀 ULTIMATE SEO FAIL-SAFE: 503 ERROR ON TIMEOUT OR 413 LIMIT
    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        status_header(503); 
        header('Retry-After: 600'); 
        
        // Debug mode so you can see if anything else fails
        $error_msg = is_wp_error($response) ? $response->get_error_message() : 'HTTP Code: ' . wp_remote_retrieve_response_code($response);
        return "<!-- BW GHOST ERROR: " . $error_msg . " -->\n" . $html; 
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    if (!empty($data['translations'][0]['text'])) {
        $translated_html = $data['translations'][0]['text'];
        
        // 🚀 NEW: RE-INJECT THE FAT
        // Put the heavy CSS and SVG icons back exactly where they belong
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
    
    $protocol = isset($_SERVER['HTTPS']) ? 'https://' : 'http://';
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