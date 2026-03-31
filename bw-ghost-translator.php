<?php
/**
 * Plugin Name: BW Empire Ghost Translator
 * Description: 100% Safe translations using DeepL API, Database Storage (Post Meta), and Rank Math Sitemap Integration.
 * Version: 2.3.0
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
// 1. SETTINGS PAGE & DB CLEANUP
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
        <h2>👻 BW Empire Ghost Translator (v2.0)</h2>
        <p>Translations are now securely saved in the WordPress Database (Post Meta) and injected into the Rank Math Sitemap.</p>
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
            <?php submit_button('Save Settings & Clear Database Cache'); ?>
        </form>
    </div>
    <?php
    // 🚀 NEW: Securely wipe the translated database rows if settings are saved
    if (isset($_GET['settings-updated'])) {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_bw_trans_html_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_bw_trans_opt_%'");
    }
}

// ==========================================
// 2. THE VIRTUAL URL ROUTER
// ==========================================
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
            $_SERVER['REQUEST_URI'] = substr($uri, 3); 
            break;
        }
    }
    return $do_parse;
}

// ==========================================
// 3. OUTPUT BUFFER & DEEPL API (DATABASE STORAGE)
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

    $target_lang = strtolower(BW_GHOST_TARGET_LANG);
    $is_single = is_singular();
    $obj_id = get_queried_object_id();
    $opt_key = '_bw_trans_opt_' . $target_lang . '_' . md5($_SERVER['REQUEST_URI']);

    // 🚀 NEW: Check Database instead of File System
    if ($is_single && $obj_id) {
        $cached_html = get_post_meta($obj_id, '_bw_trans_html_' . $target_lang, true);
    } else {
        $cached_html = get_option($opt_key);
    }

    if (!empty($cached_html)) {
        return $cached_html; // Serve instantly from Database!
    }

    // THE PAYLOAD STRIPPER
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

    if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
        status_header(503); 
        header('Retry-After: 600'); 
        return $html; 
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

        $translated_html = str_replace('<html lang="en-US">', '<html lang="' . $target_lang . '">', $translated_html);

        // THE "TRANSLATION BUBBLE"
        $host = $_SERVER['HTTP_HOST'];
        $lang_prefix = '/' . $target_lang . '/';

        $translated_html = preg_replace_callback(
            '/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*)>/is',
            function($matches) use ($host, $lang_prefix) {
                $before = $matches[1];
                $url = $matches[2];
                $after = $matches[3];

                if (strpos($url, '#') === 0 || strpos($url, 'mailto:') === 0 || strpos($url, 'tel:') === 0 || strpos($url, '/go/') !== false || strpos($url, '/wp-content/') !== false) {
                    return $matches[0];
                }

                $is_internal = false;
                $new_url = $url;

                if (strpos($url, 'http://' . $host) === 0 || strpos($url, 'https://' . $host) === 0) {
                    $is_internal = true;
                    $host_pos = strpos($url, $host) + strlen($host);
                    $path_and_query = substr($url, $host_pos);
                    
                    if (strpos($path_and_query, $lang_prefix) !== 0) {
                        $new_url = 'https://' . $host . $lang_prefix . ltrim($path_and_query, '/');
                    } else {
                        $new_url = 'https://' . $host . $path_and_query;
                    }
                } elseif (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
                    $is_internal = true;
                    if (strpos($url, $lang_prefix) !== 0) {
                        $new_url = $lang_prefix . ltrim($url, '/');
                    }
                }

                if ($is_internal) {
                    return '<a ' . $before . 'href="' . $new_url . '"' . $after . '>';
                }

                return $matches[0]; 
            },
            $translated_html
        );

        // 🚀 NEW: SAVE TO DATABASE PERMANENTLY
        if ($is_single && $obj_id) {
            update_post_meta($obj_id, '_bw_trans_html_' . $target_lang, $translated_html);
        } else {
            update_option($opt_key, $translated_html, false); // autoload = false to prevent bloat
        }

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

// ==========================================
// 5. RANK MATH SITEMAP INJECTION (THE BULLETPROOF FIX)
// ==========================================
$target_post_types =['post', 'page', 'seo_tool', 'glossary'];

foreach ($target_post_types as $pt) {
    add_filter("rank_math/sitemap/{$pt}_content", 'bw_ghost_inject_sitemap_urls_simple', 99, 1);
}

function bw_ghost_inject_sitemap_urls_simple($xml_content) {
    $langs_setting = get_option('bw_ghost_languages', '');
    if (empty($langs_setting) || empty($xml_content)) return $xml_content;

    $langs = array_map('trim', explode(',', strtolower($langs_setting)));

    // 1. Break the sitemap into individual <url> blocks
    $url_blocks = explode('</url>', $xml_content);
    $final_xml = '';

    foreach ($url_blocks as $block) {
        // Skip empty blocks
        if (trim($block) === '') continue;
        
        $block = $block . '</url>'; // Re-add the closing tag
        $final_xml .= $block; // Always keep the original English block

        // 2. Find the <loc> URL inside this block
        if (preg_match('/<loc>(.*?)<\/loc>/is', $block, $matches)) {
            $original_url = $matches[1];
            $parsed = parse_url($original_url);

            if (isset($parsed['host'])) {
                foreach ($langs as $lang) {
                    // 3. Inject the language code (e.g. /fr/)
                    $host_pos = strpos($original_url, $parsed['host']) + strlen($parsed['host']);
                    $ghost_url = substr($original_url, 0, $host_pos) . '/' . $lang . substr($original_url, $host_pos);
                    
                    // 4. Create the new block by simply replacing the <loc> string
                    $new_block = str_replace('<loc>' . $original_url . '</loc>', '<loc>' . $ghost_url . '</loc>', $block);
                    $final_xml .= "\n" . $new_block;
                }
            }
        }
    }

    return $final_xml;
}

// ==========================================
// 6. SITEMAP 301 REDIRECT (SEO CLEANUP)
// ==========================================
// Forces the weak native WordPress sitemap to redirect to the powerful Rank Math sitemap
add_action('template_redirect', 'bw_ghost_force_sitemap_redirect', 1);
function bw_ghost_force_sitemap_redirect() {
    $uri = $_SERVER['REQUEST_URI'];
    if (strpos($uri, 'wp-sitemap.xml') !== false) {
        wp_redirect(home_url('/sitemap_index.xml'), 301);
        exit;
    }
}