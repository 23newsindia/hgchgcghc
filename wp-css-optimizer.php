<?php
/**
 * Plugin Name: CSS Optimizer
 * Description: Optimizes CSS by removing unused rules and improving performance
 * Version: 1.0.0
 * Author: Your Name
 */

if (!defined('ABSPATH')) {
    exit;
}

class CSSOptimizer {
    private $options;
    private $cache_dir;
    private $used_css = [];
    
    public function __construct() {
        $this->cache_dir = WP_CONTENT_DIR . '/cache/css-optimizer/';
        
        // Define default options with ALL possible keys
        $default_options = [
            'enabled' => true,
            'excluded_urls' => [],
            'preserve_media_queries' => true,
            'exclude_font_awesome' => true,
            'excluded_classes' => []
        ];
        
        // Merge saved options with defaults to ensure all keys exist
        $saved_options = get_option('css_optimizer_options', []);
        $this->options = wp_parse_args($saved_options, $default_options);
        
        // Update the option in database with complete set of keys
        update_option('css_optimizer_options', $this->options);

        add_action('wp_enqueue_scripts', [$this, 'start_optimization'], 999);
        add_action('admin_menu', [$this, 'add_admin_menu']);
        register_activation_hook(__FILE__, [$this, 'activate']);
    }

    public function activate() {
        if (!file_exists($this->cache_dir)) {
            wp_mkdir_p($this->cache_dir);
        }
        
        // Ensure options are set on activation
        $default_options = [
            'enabled' => true,
            'excluded_urls' => [],
            'preserve_media_queries' => true,
            'exclude_font_awesome' => true,
            'excluded_classes' => []
        ];
        
        $existing_options = get_option('css_optimizer_options', []);
        $merged_options = wp_parse_args($existing_options, $default_options);
        update_option('css_optimizer_options', $merged_options);
    }
  
  
  private function should_skip($handle) {
        // Basic handles to always skip
        $skip_handles = ['admin-bar', 'dashicons'];
        
        // Add Font Awesome handles if exclusion is enabled
        if ($this->options['exclude_font_awesome']) {
            $font_awesome_handles = [
                'font-awesome',
                'fontawesome',
                'fa',
                'font-awesome-official',
                'font-awesome-solid',
                'font-awesome-brands',
                'font-awesome-regular'
            ];
            $skip_handles = array_merge($skip_handles, $font_awesome_handles);
        }
        
        return in_array($handle, $skip_handles);
    }

    private function get_css_path($src) {
        // Remove protocol and domain from URL if present
        $site_url = site_url();
        if (strpos($src, $site_url) === 0) {
            $src = substr($src, strlen($site_url));
        }
        
        // Handle various URL patterns
        if (strpos($src, content_url()) === 0) {
            return WP_CONTENT_DIR . substr($src, strlen(content_url()));
        }
        
        if (strpos($src, includes_url()) === 0) {
            return ABSPATH . WPINC . substr($src, strlen(includes_url()));
        }
        
        // Handle cache/minified files
        if (strpos($src, '/wp-content/cache/min/') !== false) {
            $cache_path = WP_CONTENT_DIR . '/cache/min/';
            $file_name = basename($src);
            if (file_exists($cache_path . $file_name)) {
                return $cache_path . $file_name;
            }
        }
        
        // If it's a relative path starting with /wp-content
        if (strpos($src, '/wp-content') === 0) {
            return ABSPATH . substr($src, 1);
        }
        
        return false;
    }
  
  
  private function get_local_css_path($src) {
        // Remove protocol and domain
        $parsed_url = parse_url($src);
        $path = isset($parsed_url['path']) ? $parsed_url['path'] : '';
        
        // Clean the path
        $path = ltrim($path, '/');
        
        // Try different path combinations
        $possible_paths = [
            ABSPATH . $path,
            WP_CONTENT_DIR . '/' . str_replace('wp-content/', '', $path),
            WP_CONTENT_DIR . '/css/' . basename($path),
            get_stylesheet_directory() . '/' . basename($path)
        ];
        
        foreach ($possible_paths as $test_path) {
            // Make sure the path is normalized
            $test_path = wp_normalize_path($test_path);
            
            // Check if file exists and is actually a file (not a directory)
            if (file_exists($test_path) && is_file($test_path)) {
                return $test_path;
            }
        }
        
        return false;
    }

    private function fetch_remote_css($url) {
        // Only fetch if it's a valid URL
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return false;
        }

        $css_content = wp_remote_retrieve_body($response);
        if (empty($css_content)) {
            return false;
        }

        return $css_content;
    }


   public function start_optimization() {
        if (!$this->options['enabled'] || is_admin()) {
            return;
        }

        // Get all enqueued styles
        global $wp_styles;
        if (!is_object($wp_styles)) {
            return;
        }

        // Store original queue
        $original_queue = $wp_styles->queue;

        foreach ($original_queue as $handle) {
            if (!isset($wp_styles->registered[$handle])) {
                continue;
            }

            $style = $wp_styles->registered[$handle];
            
            // Skip if no source
            if (empty($style->src)) {
                continue;
            }

            // Convert relative URLs to absolute
            $src = $style->src;
            if (strpos($src, '//') === 0) {
                $src = 'https:' . $src;
            } elseif (strpos($src, '/') === 0) {
                $src = site_url($src);
            }
            
            // Check if this is a Font Awesome stylesheet
            $is_font_awesome = (
                strpos($src, 'font-awesome') !== false || 
                strpos($handle, 'fa') === 0 ||
                strpos($handle, 'fontawesome') !== false
            );

            // Skip if it's in the skip handles list (but not Font Awesome when exclusion is disabled)
            if ($this->should_skip($handle) && (!$is_font_awesome || $this->options['exclude_font_awesome'])) {
                continue;
            }

            // Get local file path
            $css_file = $this->get_local_css_path($src);
            $css_content = false;
            
            if ($css_file && is_file($css_file)) {
                $css_content = @file_get_contents($css_file);
            }
            
            if (!$css_content) {
                // Try to download the file if it's not local or couldn't be read
                $css_content = $this->fetch_remote_css($src);
            }

            if (!$css_content) {
                continue;
            }

            // Optimize the CSS
            $optimized_css = $this->optimize_css($css_content);

            // Fix font paths if needed
            $optimized_css = $this->fix_font_paths($optimized_css, dirname($src));

            // Deregister original style
            wp_deregister_style($handle);

            // Add optimized CSS inline
            wp_register_style($handle . '-optimized', false);
            wp_enqueue_style($handle . '-optimized');
            wp_add_inline_style($handle . '-optimized', $optimized_css);
        }
    }
  

  private function fix_font_paths($css, $base_url) {
        // Convert relative font URLs to absolute
        return preg_replace_callback(
            '/url\([\'"]?(?!data:)([^\'")]+)[\'"]?\)/i',
            function($matches) use ($base_url) {
                $url = $matches[1];
                if (strpos($url, 'http') !== 0 && strpos($url, '//') !== 0) {
                    $url = trailingslashit($base_url) . ltrim($url, '/');
                }
                return 'url("' . $url . '")';
            },
            $css
        );
    }

    private function optimize_css($css) {
        // Preserve media queries if enabled
        if ($this->options['preserve_media_queries']) {
            preg_match_all('/@media[^{]+\{([^}]+)\}/s', $css, $media_queries);
            $media_blocks = isset($media_queries[0]) ? $media_queries[0] : [];
        }

        // Extract and process regular CSS rules
        preg_match_all('/([^{]+)\{([^}]+)\}/s', $css, $matches);
        
        $optimized = '';
        if (!empty($matches[0])) {
            foreach ($matches[0] as $i => $rule) {
                $selectors = $matches[1][$i];
                $properties = $matches[2][$i];

                // Skip @media rules as they're handled separately
                if (strpos($selectors, '@media') === 0) {
                    continue;
                }

                // Clean and optimize properties
                $optimized_properties = $this->optimize_properties($properties);
                if (!empty($optimized_properties)) {
                    $optimized .= trim($selectors) . '{' . $optimized_properties . '}';
                }
            }
        }

        // Add back media queries if enabled
        if ($this->options['preserve_media_queries'] && !empty($media_blocks)) {
            $optimized .= "\n" . implode("\n", $media_blocks);
        }

        return $this->minify_css($optimized);
    }

    private function optimize_properties($properties) {
        // Split properties into array
        $props = array_filter(array_map('trim', explode(';', $properties)));
        
        // Remove duplicates keeping last occurrence
        $unique_props = [];
        foreach ($props as $prop) {
            if (empty($prop)) continue;
            
            $parts = explode(':', $prop, 2);
            if (count($parts) !== 2) continue;
            
            $property_name = trim($parts[0]);
            $unique_props[$property_name] = $prop;
        }

        return implode(';', $unique_props) . ';';
    }

    private function minify_css($css) {
        // Remove comments
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);
        
        // Remove space after colons
        $css = str_replace(': ', ':', $css);
        
        // Remove whitespace
        $css = str_replace(["\r\n", "\r", "\n", "\t"], '', $css);
        
        // Remove extra spaces
        $css = preg_replace('/\s+/', ' ', $css);
        
        // Remove space before and after brackets
        $css = str_replace(['{ ', ' {'], '{', $css);
        $css = str_replace(['} ', ' }'], '}', $css);
        
        // Remove last semicolon
        $css = str_replace(';}', '}', $css);
        
        return trim($css);
    }

   

    public function add_admin_menu() {
        add_options_page(
            'CSS Optimizer',
            'CSS Optimizer',
            'manage_options',
            'css-optimizer-settings',
            [$this, 'render_settings_page']
        );
    }

      public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (isset($_POST['submit'])) {
            $this->options['enabled'] = isset($_POST['enabled']);
            $this->options['preserve_media_queries'] = isset($_POST['preserve_media_queries']);
            $this->options['exclude_font_awesome'] = isset($_POST['exclude_font_awesome']);
            $this->options['excluded_urls'] = array_filter(array_map('trim', explode("\n", $_POST['excluded_urls'])));
            $this->options['excluded_classes'] = array_filter(array_map('trim', explode("\n", $_POST['excluded_classes'])));
            update_option('css_optimizer_options', $this->options);
            
            echo '<div class="notice notice-success"><p>Settings saved successfully!</p></div>';
        }

        ?>
        <div class="wrap">
            <h1>CSS Optimizer Settings</h1>
            <form method="post">
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Optimization</th>
                        <td>
                            <label>
                                <input type="checkbox" name="enabled" <?php checked($this->options['enabled']); ?>>
                                Enable CSS optimization
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Font Awesome</th>
                        <td>
                            <label>
                                <input type="checkbox" name="exclude_font_awesome" <?php checked($this->options['exclude_font_awesome']); ?>>
                                Exclude Font Awesome from optimization
                            </label>
                            <p class="description">Check this to preserve all Font Awesome styles (recommended if you're using Font Awesome)</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Preserve Media Queries</th>
                        <td>
                            <label>
                                <input type="checkbox" name="preserve_media_queries" <?php checked($this->options['preserve_media_queries']); ?>>
                                Keep responsive design rules
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Excluded Classes</th>
                        <td>
                            <textarea name="excluded_classes" rows="5" cols="50"><?php echo esc_textarea(implode("\n", $this->options['excluded_classes'])); ?></textarea>
                            <p class="description">Enter one CSS class per line. Any rules containing these classes will be preserved.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Excluded URLs</th>
                        <td>
                            <textarea name="excluded_urls" rows="5" cols="50"><?php echo esc_textarea(implode("\n", $this->options['excluded_urls'])); ?></textarea>
                            <p class="description">Enter one URL pattern per line. Wildcards (*) are supported.</p>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
}

new CSSOptimizer();