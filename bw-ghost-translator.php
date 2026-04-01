<?php
/**
 * Plugin Name: BW Empire Ghost Translator
 * Description: Elite Enterprise translations. Features Early-Exit, Mutex Locking, Global API Circuit Breaker, and SEO Failsafes.
 * Version: 3.1.0
 * Author: BW Empire
 */

if (!defined('ABSPATH')) exit;

// ==========================================
// 1. GITHUB AUTO-UPDATER
// ==========================================
try {
    if ( file_exists( __DIR__ . '/plugin-update-checker/plugin-update-checker.php' ) ) {
        require_once __DIR__ . '/plugin-update-checker/plugin-update-checker.php';
        if ( class_exists( '\YahnisElsts\PluginUpdateChecker\v5\PucFactory' ) ) {
            $myUpdateChecker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
                'https://github.com/ANXJaxx/Ghost-Translator',
                __FILE__,
                'Ghost-Translator'
            );
            $myUpdateChecker->setBranch('main');
        }
    }
} catch ( \Throwable $e ) {
    error_log("[Ghost Translator] Updater error: " . $e->getMessage());
}

// ==========================================
// 2. SETTINGS PAGE & DB CLEANUP
// ==========================================
if ( ! function_exists( 'bw_ghost_translator_menu' ) ) {
    add_action('admin_menu', 'bw_ghost_translator_menu');
    function bw_ghost_translator_menu() {
        add_options_page('Ghost Translator', 'Ghost Translator', 'manage_options', 'bw_ghost_translator', 'bw_ghost_settings_page');
    }
}

add_action('admin_init', 'bw_ghost_register_settings');
function bw_ghost_register_settings() {
    register_setting('bw_ghost_group', 'bw_ghost_deepl_key');
    register_setting('bw_ghost_group', 'bw_ghost_languages'); 
}

function bw_ghost_settings_page() {
    ?>
    <div class="wrap">
        <h2>👻 BW Empire Ghost Translator (v3.1 Elite Edition)</h2>
        <p>Enterprise Edge Cache + Global Circuit Breaker + SEO Failsafes.</p>
        <form method="post" action="options.php">
            <?php settings_fields('bw_ghost_group'); ?>
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">DeepL API Key</th>
                    <td><input type="text" name="bw_ghost_deepl_key" value="<?php echo esc_attr(get_option('bw_ghost_deepl_key')); ?>" style="width:400px;" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">Target Languages (Comma separated)</th>
                    <td><input type="text" name="bw_ghost_languages" value="<?php echo esc_attr(get_option('bw_ghost_languages', 'ES,FR,DE')); ?>" style="width:400px;" /></td>
                </tr>
            </table>
            <?php submit_button('Save Settings & Clear Database Cache'); ?>
        </form>
    </div>
    <?php
    if (isset($_GET['settings-updated'])) {
        try {
            global $wpdb;
            $wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE '_bw_trans_html_%'");
            $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_bw_trans_opt_%'");
            delete_transient('bw_ghost_api_cooldown'); // Reset circuit breaker
        } catch ( \Throwable $e ) {}
    }
}

// ==========================================
// 3. THE VIRTUAL URL ROUTER
// ==========================================
add_action('plugins_loaded', 'bw_ghost_force_ssl_early', 1);
function bw_ghost_force_ssl_early() {
    try {
        if (!is_ssl() && (!isset($_SERVER['HTTP_X_FORWARDED_PROTO']) || $_SERVER['HTTP_X_FORWARDED_PROTO'] !== 'https')) {
            $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
            $langs_setting = get_option('bw_ghost_languages', '');
            if (empty($langs_setting) || !is_string($langs_setting) || empty($uri)) return;

            $langs = array_map('trim', explode(',', strtoupper($langs_setting)));
            foreach ($langs as $lang) {
                if ($lang !== '' && strpos($uri, '/' . strtolower($lang) . '/') === 0) {
                    $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
                    header("Location: https://" . $host . $uri, true, 301);
                    exit;
                }
            }
        }
    } catch ( \Throwable $e ) {}
}

add_filter('do_parse_request', 'bw_ghost_catch_virtual_url', 1, 2);
function bw_ghost_catch_virtual_url($do_parse, $wp) {
    try {
        if (is_admin()) return $do_parse;

        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $langs_setting = get_option('bw_ghost_languages', '');
        if (empty($langs_setting) || !is_string($langs_setting) || empty($uri)) return $do_parse;

        $langs = array_map('trim', explode(',', strtoupper($langs_setting)));

        foreach ($langs as $lang) {
            if ($lang === '') continue;
            $prefix = '/' . strtolower($lang) . '/';
            if (strpos($uri, $prefix) === 0) {
                if (!defined('BW_GHOST_TARGET_LANG')) {
                    define('BW_GHOST_TARGET_LANG', $lang);
                }
                $_SERVER['REQUEST_URI'] = substr($uri, 3); 
                break;
            }
        }
    } catch ( \Throwable $e ) {}
    return $do_parse;
}

// ==========================================
// 4. ENTERPRISE EARLY-EXIT CACHE & API LOGIC
// ==========================================
add_action('template_redirect', 'bw_ghost_early_cache_check', 0);
function bw_ghost_early_cache_check() {
    if (!defined('BW_GHOST_TARGET_LANG')) return;

    try {
        $target_lang = strtolower(BW_GHOST_TARGET_LANG);
        $is_single = is_singular();
        $obj_id = get_queried_object_id();
        $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $opt_key = '_bw_trans_opt_' . $target_lang . '_' . md5($req_uri);

        if ($is_single && !empty($obj_id)) {
            $cached_html = get_post_meta($obj_id, '_bw_trans_html_' . $target_lang, true);
        } else {
            $cached_html = get_option($opt_key);
        }

        if (!empty($cached_html) && is_string($cached_html)) {
            echo $cached_html;
            exit; // Instant cache bypass
        }

        remove_action('template_redirect', 'redirect_canonical');
        ob_start('bw_ghost_translate_html');

    } catch (\Throwable $e) {
        error_log("[Ghost Translator] Early Cache Error: " . $e->getMessage());
    }
}

// HELPER: SEO Emergency Failsafe
function bw_ghost_seo_emergency_fallback($html) {
    if ( ! headers_sent() && ! is_admin() ) {
        status_header(503); 
        header('Retry-After: 600'); 
    } else {
        // Headers already sent! Inject NOINDEX into the HTML to protect SEO.
        if (is_string($html) && strpos($html, '<head>') !== false) {
            $html = str_replace('<head>', '<head>' . "\n" . '<meta name="robots" content="noindex, noarchive">' . "\n", $html);
        }
    }
    return is_string($html) ? $html : '';
}

function bw_ghost_translate_html($html) {
    $req_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
    $lock_key = 'bw_trans_lock_' . md5($req_uri);

    try {
        $api_key = get_option('bw_ghost_deepl_key');
        if (empty($api_key) || !is_string($api_key) || empty($html) || !is_string($html) || !defined('BW_GHOST_TARGET_LANG')) {
            return is_string($html) ? $html : '';
        }

        // 🛑 1. Memory Limit Protection
        if (strlen($html) > 1000000) { 
            error_log("[Ghost Translator] Page too large: " . strlen($html) . " bytes.");
            return bw_ghost_seo_emergency_fallback($html);
        }

        // 🛑 2. The Circuit Breaker (Global API Cooldown)
        if (get_transient('bw_ghost_api_cooldown')) {
            return bw_ghost_seo_emergency_fallback($html);
        }

        // 🛑 3. Mutex Lock (Stampede Protection)
        if (get_transient($lock_key)) {
            return bw_ghost_seo_emergency_fallback($html); 
        }
        set_transient($lock_key, true, 45);

        // --- PREPARE API PAYLOAD ---
        $target_lang = strtolower(BW_GHOST_TARGET_LANG);
        $is_single = is_singular();
        $obj_id = get_queried_object_id();
        $opt_key = '_bw_trans_opt_' . $target_lang . '_' . md5($req_uri);

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
            'body'  => $body_json,
            'timeout' => 30 
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            // Trigger the Circuit Breaker: Block API globally for 60 seconds
            set_transient('bw_ghost_api_cooldown', true, 60);
            error_log("[Ghost Translator] DeepL Failure. Circuit Breaker triggered for 60s.");
            return bw_ghost_seo_emergency_fallback($html);
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) return bw_ghost_seo_emergency_fallback($html);

        $data = json_decode($body, true);
        if (!empty($data['translations'][0]['text']) && is_string($data['translations'][0]['text'])) {
            $translated_html = $data['translations'][0]['text'];
             
            $translated_html = preg_replace_callback(
                '/<bwph id="(\d+)".*?<\/bwph>/is',
                function($matches) use ($placeholders) {
                    $index = intval($matches[1]);
                    return isset($placeholders[$index]) ? $placeholders[$index] : $matches[0];
                },
                $translated_html
            );

            $translated_html = str_replace('<html lang="en-US">', '<html lang="' . $target_lang . '">', $translated_html);
            $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
            $lang_prefix = '/' . $target_lang . '/';

            $translated_html = preg_replace_callback(
                '/<a\s+([^>]*?)href=["\']([^"\']+)["\']([^>]*)>/is',
                function($matches) use ($host, $lang_prefix) {
                    $before = $matches[1];
                    $url = $matches[2];
                    $after = $matches[3];

                    if (strpos($url, '#') === 0 || strpos($url, 'mailto:') === 0 || strpos($url, 'tel:') === 0 || strpos($url, '/go/') !== false || strpos($url, '/wp-content/') !== false) return $matches[0];

                    $is_internal = false;
                    $new_url = $url;

                    if (!empty($host) && (strpos($url, 'http://' . $host) === 0 || strpos($url, 'https://' . $host) === 0)) {
                        $is_internal = true;
                        $host_pos = strpos($url, $host) + strlen($host);
                        $path_and_query = substr($url, $host_pos);
                        $new_url = (strpos($path_and_query, $lang_prefix) !== 0) ? 'https://' . $host . $lang_prefix . ltrim($path_and_query, '/') : 'https://' . $host . $path_and_query;
                    } elseif (strpos($url, '/') === 0 && strpos($url, '//') !== 0) {
                        $is_internal = true;
                        if (strpos($url, $lang_prefix) !== 0) $new_url = $lang_prefix . ltrim($url, '/');
                    }

                    if ($is_internal) return '<a ' . $before . 'href="' . $new_url . '"' . $after . '>';
                    return $matches[0]; 
                },
                $translated_html
            );

            if ($is_single && !empty($obj_id)) {
                update_post_meta($obj_id, '_bw_trans_html_' . $target_lang, $translated_html);
            } else {
                update_option($opt_key, $translated_html, false);
            }

            return $translated_html;
        }

        return bw_ghost_seo_emergency_fallback($html);

    } catch (\Throwable $e) {
        error_log("[Ghost Translator] Fatal inside buffer: " . $e->getMessage());
        return bw_ghost_seo_emergency_fallback($html);
    } finally {
        // 🛑 4. Elite Cleanup: ALWAYS remove the Mutex Lock even if PHP crashes
        delete_transient($lock_key);
    }
}

// ==========================================
// 5. AUTOMATED SEO HREFLANG INJECTION
// ==========================================
add_action('wp_head', 'bw_ghost_hreflang_tags', 1);
function bw_ghost_hreflang_tags() {
    try {
        $langs_setting = get_option('bw_ghost_languages', '');
        if (empty($langs_setting) || !is_string($langs_setting)) return;

        $langs = array_map('trim', explode(',', strtolower($langs_setting)));
        $protocol = 'https://';
        $host = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $base_uri = defined('BW_GHOST_TARGET_LANG') && isset($_SERVER['REQUEST_URI']) ? substr((string)$_SERVER['REQUEST_URI'], 3) : (isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '');
        $base_url = $protocol . $host . $base_uri;

        echo '<link rel="alternate" hreflang="en" href="' . esc_url($base_url) . '" />' . "\n";
        echo '<link rel="alternate" hreflang="x-default" href="' . esc_url($base_url) . '" />' . "\n";

        foreach ($langs as $lang) {
            if ($lang === '') continue;
            $foreign_url = $protocol . $host . '/' . $lang . $base_uri;
            echo '<link rel="alternate" hreflang="' . esc_attr($lang) . '" href="' . esc_url($foreign_url) . '" />' . "\n";
        }
    } catch (\Throwable $e) {}
}

// ==========================================
// 6. RANK MATH SITEMAP (ULTRA-SAFE MODE)
// ==========================================
add_filter( 'rank_math/sitemap/url', 'bw_ghost_add_hreflang_to_sitemap', 10, 2 );
function bw_ghost_add_hreflang_to_sitemap( $output, $url ) {
    try {
        if ( ! is_string( $output ) ) return '';
        if ( ! is_array( $url ) || empty( $url['loc'] ) || ! is_string( $url['loc'] ) ) return $output;

        $langs_setting = get_option('bw_ghost_languages', '');
        if ( empty( $langs_setting ) || ! is_string( $langs_setting ) ) return $output;

        $langs = array_map( 'trim', explode( ',', strtolower( $langs_setting ) ) );
        $original_url = $url['loc'];
        
        $parsed = wp_parse_url( $original_url );
        if ( ! is_array( $parsed ) || empty( $parsed['host'] ) || empty( $parsed['path'] ) ) return $output;

        $scheme = isset( $parsed['scheme'] ) ? $parsed['scheme'] : 'https';
        $base_url = $scheme . '://' . $parsed['host'];
        if ( ! empty( $parsed['port'] ) ) $base_url .= ':' . $parsed['port'];

        $path_and_query = $parsed['path'];
        if ( ! empty( $parsed['query'] ) ) $path_and_query .= '?' . $parsed['query'];

        $hreflang_tags = "\n";
        $hreflang_tags .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"en\" href=\"" . esc_url( $original_url ) . "\" />\n";
        $hreflang_tags .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"x-default\" href=\"" . esc_url( $original_url ) . "\" />\n";

        $foreign_urls = [];
        foreach ( $langs as $lang ) {
            if ( $lang === '' ) continue;
            $foreign_url = $base_url . '/' . $lang . $path_and_query;
            $foreign_urls[$lang] = $foreign_url;
            $hreflang_tags .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"" . esc_attr( $lang ) . "\" href=\"" . esc_url( $foreign_url ) . "\" />\n";
        }

        if ( empty( $foreign_urls ) ) return $output;

        $new_output = str_replace( '</url>', $hreflang_tags . "\t</url>", $output );
        $escaped_original_loc = '<loc>' . esc_url( $original_url ) . '</loc>';

        foreach ( $foreign_urls as $lang => $foreign_url ) {
            $lang_output = str_replace( $escaped_original_loc, '<loc>' . esc_url( $foreign_url ) . '</loc>', $output );
            $lang_output = str_replace( '</url>', $hreflang_tags . "\t</url>", $lang_output );
            $new_output .= "\n" . $lang_output;
        }

        return $new_output;

    } catch ( \Throwable $e ) {
        return is_string( $output ) ? $output : '';
    }
}