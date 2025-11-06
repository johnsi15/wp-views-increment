<?php
/**
 * Plugin Name: WPB Views Increment Pro
 * Plugin URI: https://github.com/johnsi15/wp-views-increment
 * Description: Sistema optimizado de contador de vistas con buffering real, trending score y configuración admin
 * Version: 2.1.0
 * Author: John Serrano
 * Author URI: https://johnserrano.co
 * License: MIT
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Constantes de configuración con fallbacks
if (!defined('WPB_VIEWS_META_KEY')) {
    define('WPB_VIEWS_META_KEY', 'wpb_post_views_count');
}
if (!defined('WPB_VIEWS_BUFFER_OPTION')) {
    define('WPB_VIEWS_BUFFER_OPTION', 'wpb_views_buffer');
}
if (!defined('WPB_VIEWS_TRANSIENT_TTL')) {
    define('WPB_VIEWS_TRANSIENT_TTL', 3600);
}

class WPB_Views_Counter_Pro {
    
    private static $instance = null;
    private $buffer_last_flush = 0;
    private $settings_option = 'wpb_views_settings';
    private $default_settings = array(
        'buffer_enabled' => true,
        'buffer_size' => 100,
        'buffer_timeout' => 300,
        'trending_weight' => 0.7,
        'retention_days' => 90,
        'debug_mode' => false,
        'use_external_cron' => false
    );
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // Hooks principales
        add_action('init', array($this, 'register_post_meta'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('rest_api_init', array($this, 'setup_cors'));
        
        // Cron solo si NO usa external cron
        if (!$this->get_setting('use_external_cron')) {
            add_action('wp', array($this, 'schedule_buffer_flush'));
            add_action('wp', array($this, 'schedule_trending_calculation'));
        }
        
        add_action('wpb_views_buffer_flush_event', array($this, 'flush_buffer'));
        add_action('wpb_views_calculate_trending_event', array($this, 'calculate_trending_scores'));
        add_filter('cron_schedules', array($this, 'add_cron_schedule'));
        
        // GraphQL (solo si está disponible)
        if (class_exists('WPGraphQL')) {
            add_action('graphql_register_types', array($this, 'register_graphql_types'));
        }
        
        // Admin
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        
        // Activación/Desactivación
        // register_activation_hook(__FILE__, array($this, 'activate'));
        // register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Inicializar timestamp
        // $this->buffer_last_flush = get_option('wpb_views_last_flush', time());
        $this->buffer_last_flush = get_option('wpb_views_last_flush', current_time('timestamp'));
    }
    
    /**
     * Obtener configuración
     */
    private function get_setting($key, $default = null) {
        $settings = get_option($this->settings_option, $this->default_settings);
        if (isset($settings[$key])) {
            return $settings[$key];
        }
        return $default !== null ? $default : $this->default_settings[$key];
    }
    
    /**
     * Log de errores/debug
     */
    private function log($message, $level = 'info') {
        if (!$this->get_setting('debug_mode')) {
            return;
        }
        
        $timestamp = current_time('Y-m-d H:i:s');
        $log_message = sprintf('[%s] [%s] %s', $timestamp, strtoupper($level), $message);
        error_log('WPB Views: ' . $log_message);
        
        // También guardar en option para admin
        $logs = get_option('wpb_views_logs', array());
        array_unshift($logs, $log_message);
        $logs = array_slice($logs, 0, 100); // Mantener últimos 100
        update_option('wpb_views_logs', $logs, false);
    }
    
    /**
     * Activación del plugin
     */
    public function activate() {
        global $wpdb;
        
        try {
            $table_name = $wpdb->prefix . 'post_views';
            $charset_collate = $wpdb->get_charset_collate();
            
            $sql = "CREATE TABLE IF NOT EXISTS $table_name (
                id bigint(20) NOT NULL AUTO_INCREMENT,
                post_id bigint(20) NOT NULL,
                view_date date NOT NULL,
                view_count int(11) NOT NULL DEFAULT 1,
                PRIMARY KEY  (id),
                UNIQUE KEY post_date (post_id, view_date),
                KEY post_id (post_id),
                KEY view_date (view_date)
            ) $charset_collate;";
            
            require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
            // dbDelta($sql);

            $result = $wpdb->query($sql);
            if ($result === false) {
                throw new Exception("Failed to create table $table_name: " . $wpdb->last_error);
            }
            
            // Verificar que la tabla se creó
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                throw new Exception("Failed to create table $table_name");
            }
            
            // Crear configuración inicial
            if (!get_option($this->settings_option)) {
                add_option($this->settings_option, $this->default_settings);
            }
            
            // Programar cron solo si no usa external cron
            if (!$this->get_setting('use_external_cron')) {
                if (!wp_next_scheduled('wpb_views_buffer_flush_event')) {
                    wp_schedule_event(time() + $this->get_setting('buffer_timeout'), 'wpb_views_flush_interval', 'wpb_views_buffer_flush_event');
                }
                if (!wp_next_scheduled('wpb_views_calculate_trending_event')) {
                    wp_schedule_event(strtotime('tomorrow 02:00'), 'daily', 'wpb_views_calculate_trending_event');
                }
            }
            
            $this->log('Plugin activated successfully', 'info');
            
        } catch (Exception $e) {
            $this->log('Activation error: ' . $e->getMessage(), 'error');
            wp_die('Error activating WPB Views Counter Pro: ' . $e->getMessage());
        }
    }
    
    /**
     * Desactivación del plugin
     */
    public function deactivate() {
        try {
            // Flush final del buffer
            $this->flush_buffer();
            
            // Remover cron jobs
            wp_clear_scheduled_hook('wpb_views_buffer_flush_event');
            wp_clear_scheduled_hook('wpb_views_calculate_trending_event');
            
            $this->log('Plugin deactivated successfully', 'info');
            
        } catch (Exception $e) {
            $this->log('Deactivation error: ' . $e->getMessage(), 'error');
        }
    }
    
    /**
     * Agregar menú de administración
     */
    public function add_admin_menu() {
        add_options_page(
            'WPB Views Settings',
            'WPB Views',
            'manage_options',
            'wpb-views-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Registrar configuraciones
     */
    public function register_settings() {
        register_setting('wpb_views_settings_group', $this->settings_option, array($this, 'sanitize_settings'));
    }
    
    /**
     * Sanitizar configuraciones
     */
    public function sanitize_settings($input) {
        $sanitized = array();
        
        $sanitized['buffer_enabled'] = isset($input['buffer_enabled']) ? (bool) $input['buffer_enabled'] : false;
        $sanitized['buffer_size'] = absint($input['buffer_size'] ?? 100);
        $sanitized['buffer_timeout'] = absint($input['buffer_timeout'] ?? 300);
        $sanitized['trending_weight'] = floatval($input['trending_weight'] ?? 0.7);
        $sanitized['retention_days'] = absint($input['retention_days'] ?? 90);
        $sanitized['debug_mode'] = isset($input['debug_mode']) ? (bool) $input['debug_mode'] : false;
        $sanitized['use_external_cron'] = isset($input['use_external_cron']) ? (bool) $input['use_external_cron'] : false;
        
        // Validaciones
        if ($sanitized['buffer_size'] < 10) $sanitized['buffer_size'] = 10;
        if ($sanitized['buffer_size'] > 1000) $sanitized['buffer_size'] = 1000;
        if ($sanitized['buffer_timeout'] < 60) $sanitized['buffer_timeout'] = 60;
        if ($sanitized['trending_weight'] < 0) $sanitized['trending_weight'] = 0;
        if ($sanitized['trending_weight'] > 1) $sanitized['trending_weight'] = 1;
        
        return $sanitized;
    }
    
    /**
     * Renderizar página de configuración
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }
        
        $settings = get_option($this->settings_option, $this->default_settings);
        $buffer = get_option(WPB_VIEWS_BUFFER_OPTION, array());
        // $buffer_size = is_array($buffer) ? count($buffer) : 0;
        $buffer_size = is_array($buffer) ? array_sum($buffer) : 0;
        
        ?>
        <div class="wrap">
            <h1>WPB Views Counter Pro - Settings</h1>
            
            <div class="notice notice-info">
                <p><strong>Buffer Status:</strong> <?php echo $buffer_size; ?> items pending flush</p>
                <p><strong>Last Flush:</strong> <?php echo date_i18n('Y-m-d H:i:s', $this->buffer_last_flush); ?></p>
            </div>
            
            <form method="post" action="options.php">
                <?php settings_fields('wpb_views_settings_group'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable Buffering</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo $this->settings_option; ?>[buffer_enabled]" value="1" <?php checked($settings['buffer_enabled'], true); ?> />
                                Buffer views before writing to database
                            </label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Buffer Size</th>
                        <td>
                            <input type="number" name="<?php echo $this->settings_option; ?>[buffer_size]" value="<?php echo esc_attr($settings['buffer_size']); ?>" min="10" max="1000" />
                            <p class="description">Flush buffer when this many views are accumulated (10-1000)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Buffer Timeout (seconds)</th>
                        <td>
                            <input type="number" name="<?php echo $this->settings_option; ?>[buffer_timeout]" value="<?php echo esc_attr($settings['buffer_timeout']); ?>" min="60" />
                            <p class="description">Flush buffer after this many seconds (minimum 60)</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Trending Weight</th>
                        <td>
                            <input type="number" name="<?php echo $this->settings_option; ?>[trending_weight]" value="<?php echo esc_attr($settings['trending_weight']); ?>" min="0" max="1" step="0.1" />
                            <p class="description">Weight for recent views (0-1). Higher = more weight to recent. Example: 0.7 = 70% recent, 30% total</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Data Retention (days)</th>
                        <td>
                            <input type="number" name="<?php echo $this->settings_option; ?>[retention_days]" value="<?php echo esc_attr($settings['retention_days']); ?>" min="30" />
                            <p class="description">Keep daily view data for this many days</p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Use External Cron</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo $this->settings_option; ?>[use_external_cron]" value="1" <?php checked($settings['use_external_cron'], true); ?> />
                                I'm using external cron (recommended for production)
                            </label>
                            <p class="description">
                                If enabled, WP-Cron will be disabled. Setup these URLs in cPanel/cron:<br>
                                <code><?php echo site_url('wp-json/wpb/v1/cron/flush-buffer'); ?></code> (every 5 minutes)<br>
                                <code><?php echo site_url('wp-json/wpb/v1/cron/calculate-trending'); ?></code> (daily at 2 AM)
                            </p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">Debug Mode</th>
                        <td>
                            <label>
                                <input type="checkbox" name="<?php echo $this->settings_option; ?>[debug_mode]" value="1" <?php checked($settings['debug_mode'], true); ?> />
                                Enable debug logging
                            </label>
                            <p class="description">Logs will appear in error_log and below</p>
                        </td>
                    </tr>
                </table>
                
                <?php submit_button(); ?>
            </form>
            
            <hr>
            
            <h2>Manual Actions</h2>
            <p>
                <button type="button" class="button" onclick="wpbFlushBuffer()">Flush Buffer Now</button>
                <button type="button" class="button" onclick="wpbCalculateTrending()">Calculate Trending Scores</button>
            </p>
            
            <?php if ($this->get_setting('debug_mode')): ?>
                <hr>
                <h2>Recent Logs</h2>
                <div style="background: #f5f5f5; padding: 10px; max-height: 300px; overflow-y: auto; font-family: monospace; font-size: 12px;">
                    <?php
                    $logs = get_option('wpb_views_logs', array());
                    if (empty($logs)) {
                        echo '<p>No logs yet</p>';
                    } else {
                        foreach ($logs as $log) {
                            echo esc_html($log) . '<br>';
                        }
                    }
                    ?>
                </div>
            <?php endif; ?>
        </div>
        
        <script>
        function wpbFlushBuffer() {
            if (!confirm('Flush buffer now?')) return;
            
            fetch('<?php echo rest_url('wpb/v1/flush-buffer'); ?>', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                }
            })
            .then(r => r.json())
            .then(data => {
                alert(data.message || 'Done');
                location.reload();
            })
            .catch(e => alert('Error: ' + e));
        }
        
        function wpbCalculateTrending() {
            if (!confirm('Calculate trending scores now? This may take a few seconds.')) return;
            
            fetch('<?php echo rest_url('wpb/v1/calculate-trending'); ?>', {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                }
            })
            .then(r => r.json())
            .then(data => {
                alert(data.message || 'Done');
                location.reload();
            })
            .catch(e => alert('Error: ' + e));
        }
        </script>
        <?php
    }
    
    public function register_post_meta() {
        register_post_meta('post', WPB_VIEWS_META_KEY, array(
            'type'              => 'integer',
            'single'            => true,
            'sanitize_callback' => 'absint',
            'show_in_rest'      => true,
            'default'           => 0
        ));
        
        register_post_meta('post', 'wpb_trending_score', array(
            'type'              => 'number',
            'single'            => true,
            'sanitize_callback' => function($value) {
                return is_numeric($value) ? (float) $value : 0.0;
            },
            'show_in_rest'      => true,
            'default'           => 0
        ));
    }
    
    public function register_rest_routes() {
        // Rutas públicas
        register_rest_route('wpb/v1', '/increment-view', array(
            'methods'             => array('POST', 'OPTIONS'),
            'callback'            => array($this, 'rest_increment_view'),
            'permission_callback' => '__return_true',
            'args'                => array(
                'post_id' => array('type' => 'integer', 'required' => false),
                'slug'    => array('type' => 'string', 'required' => false)
            )
        ));
        
        register_rest_route('wpb/v1', '/status', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_get_status'),
            'permission_callback' => '__return_true',
        ));
        
        // Rutas admin
        register_rest_route('wpb/v1', '/flush-buffer', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_flush_buffer'),
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ));
        
        register_rest_route('wpb/v1', '/calculate-trending', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_calculate_trending'),
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ));
        
        // Rutas para external cron (con secret token)
        register_rest_route('wpb/v1', '/cron/flush-buffer', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'cron_flush_buffer'),
            'permission_callback' => array($this, 'verify_cron_token'),
        ));
        
        register_rest_route('wpb/v1', '/cron/calculate-trending', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'cron_calculate_trending'),
            'permission_callback' => array($this, 'verify_cron_token'),
        ));
    }
    
    /**
     * Verificar token para external cron
     */
    public function verify_cron_token($request) {
        $token = $request->get_param('token');
        
        if (defined('WPB_VIEWS_CRON_TOKEN') && WPB_VIEWS_CRON_TOKEN) {
            return hash_equals((string) WPB_VIEWS_CRON_TOKEN, (string) $token);
        }

        $expected_token = get_option('wpb_views_cron_token');
        
        // Generar token si no existe
        if (!$expected_token) {
            $expected_token = wp_generate_password(32, false);
            update_option('wpb_views_cron_token', $expected_token);
        }
        
        return $token === $expected_token;
    }
    
    public function rest_get_status($request) {
        wp_cache_delete(WPB_VIEWS_BUFFER_OPTION, 'options');
        $buffer = get_option(WPB_VIEWS_BUFFER_OPTION, array());
        $this->log("Status buffer: " . json_encode($buffer) . " sum: " . array_sum($buffer));
        // $buffer_size = is_array($buffer) ? count($buffer) : 0;
        $buffer_size = is_array($buffer) ? array_sum($buffer) : 0;
        
        return rest_ensure_response(array(
            'version' => '2.1.0',
            'buffer_enabled' => $this->get_setting('buffer_enabled'),
            'buffer_size' => $buffer_size,
            'buffer_max' => $this->get_setting('buffer_size'),
            'last_flush' => $this->buffer_last_flush,
            'settings' => get_option($this->settings_option),
            'status' => 'ok'
        ));
    }
    
    public function rest_flush_buffer($request) {
        try {
            $result = $this->flush_buffer();
            return rest_ensure_response(array(
                'success' => $result,
                'message' => $result ? 'Buffer flushed successfully' : 'No items to flush'
            ));
        } catch (Exception $e) {
            return new WP_Error('flush_error', $e->getMessage(), array('status' => 500));
        }
    }
    
    public function rest_calculate_trending($request) {
        try {
            $this->calculate_trending_scores();
            return rest_ensure_response(array(
                'success' => true,
                'message' => 'Trending scores calculated successfully'
            ));
        } catch (Exception $e) {
            return new WP_Error('trending_error', $e->getMessage(), array('status' => 500));
        }
    }
    
    public function cron_flush_buffer($request) {
        try {
            $this->flush_buffer();
            return rest_ensure_response(array('success' => true));
        } catch (Exception $e) {
            $this->log('Cron flush error: ' . $e->getMessage(), 'error');
            return new WP_Error('cron_error', $e->getMessage(), array('status' => 500));
        }
    }
    
    public function cron_calculate_trending($request) {
        try {
            $this->calculate_trending_scores();
            return rest_ensure_response(array('success' => true));
        } catch (Exception $e) {
            $this->log('Cron trending error: ' . $e->getMessage(), 'error');
            return new WP_Error('cron_error', $e->getMessage(), array('status' => 500));
        }
    }
    
    public function setup_cors() {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', function($value) {
            $origin = get_http_origin();
            $allowed_origins = array(
                'http://54.189.168.166',
                'https://elpilon.com.co',
                'https://palegoldenrod-hedgehog-368669.hostingersite.com'
            );
            
            if ($origin && in_array(rtrim($origin, '/'), $allowed_origins, true)) {
                header('Access-Control-Allow-Origin: ' . esc_url_raw($origin));
            } else {
                header('Access-Control-Allow-Origin: ');
            }
            
            header('Access-Control-Allow-Methods: POST, GET');
            header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
            header('Access-Control-Allow-Credentials: true');
            
            return $value;
        });
    }
    
    public function rest_increment_view($request) {
        if ($request->get_method() === 'OPTIONS') {
            return rest_ensure_response(array('status' => 'ok'));
        }
        
        try {
            $body = $request->get_json_params() ?: $request->get_body_params();
            $post_id = 0;
            
            if (!empty($body['post_id'])) {
                $post_id = intval($body['post_id']);
            } elseif (!empty($body['slug'])) {
                $slug = sanitize_text_field($body['slug']);
                $post = get_page_by_path($slug, OBJECT, 'post');
                if ($post) {
                    $post_id = $post->ID;
                }
            }
            
            if (!$post_id) {
                return new WP_Error('no_post_id', 'post_id or slug required', array('status' => 400));
            }
            
            if (get_post_status($post_id) !== 'publish') {
                return new WP_Error('invalid_post', 'Post not found or not published', array('status' => 404));
            }
            
            // Anti-spam
            $client_ip = $this->get_client_ip();
            $user_agent = $request->get_header('user-agent') ?: 'unknown';
            $viewer_key = 'wpb_view_' . $post_id . '_' . md5($client_ip . $user_agent);
            
            if (get_transient($viewer_key)) {
                $count = (int) get_post_meta($post_id, WPB_VIEWS_META_KEY, true) ?: 0;
                return rest_ensure_response(array(
                    'count' => $count,
                    'incremented' => false,
                    'reason' => 'already_viewed'
                ));
            }
            
            // Detectar bots
            if ($this->is_bot($user_agent)) {
                $count = (int) get_post_meta($post_id, WPB_VIEWS_META_KEY, true) ?: 0;
                return rest_ensure_response(array(
                    'count' => $count,
                    'incremented' => false,
                    'reason' => 'bot_detected'
                ));
            }
            
            // Buffering o directo
            if ($this->get_setting('buffer_enabled')) {
                $this->add_to_buffer($post_id);
                $count = (int) get_post_meta($post_id, WPB_VIEWS_META_KEY, true) ?: 0;

                if (!$this->get_setting('use_external_cron')) {
                    $this->maybe_auto_flush();
                }

                set_transient($viewer_key, true, WPB_VIEWS_TRANSIENT_TTL);
                
                return rest_ensure_response(array(
                    'count' => $count + 1,
                    'incremented' => true,
                    'buffered' => true
                ));
            }
            
            // Sin buffering
            $count = (int) get_post_meta($post_id, WPB_VIEWS_META_KEY, true) ?: 0;
            $count++;
            update_post_meta($post_id, WPB_VIEWS_META_KEY, $count);
            $this->record_daily_view($post_id);
            set_transient($viewer_key, true, WPB_VIEWS_TRANSIENT_TTL);
            
            return rest_ensure_response(array(
                'count' => $count,
                'incremented' => true,
                'buffered' => false
            ));
            
        } catch (Exception $e) {
            $this->log('Increment view error: ' . $e->getMessage(), 'error');
            return new WP_Error('increment_error', 'Internal error', array('status' => 500));
        }
    }
    
    private function add_to_buffer($post_id) {
        try {
            $buffer = get_option(WPB_VIEWS_BUFFER_OPTION, array());
            
            if (!is_array($buffer)) {
                $buffer = array();
            }
            
            if (!isset($buffer[$post_id])) {
                $buffer[$post_id] = 0;
            }
            $buffer[$post_id]++;
            
            update_option(WPB_VIEWS_BUFFER_OPTION, $buffer, false);
            wp_cache_delete(WPB_VIEWS_BUFFER_OPTION, 'options');
            $this->log("Buffer updated: " . json_encode($buffer) . " sum: " . array_sum($buffer));
            $this->log("Added post $post_id to buffer. Buffer size: " . count($buffer));
            
        } catch (Exception $e) {
            $this->log('Buffer add error: ' . $e->getMessage(), 'error');
        }
    }
    
    private function maybe_auto_flush() {
        try {
            $buffer = get_option(WPB_VIEWS_BUFFER_OPTION, array());
            // $buffer_size = is_array($buffer) ? count($buffer) : 0;
            $buffer_size = is_array($buffer) ? array_sum($buffer) : 0;
            $time_elapsed = time() - $this->buffer_last_flush;
            
            if ($buffer_size >= $this->get_setting('buffer_size') || $time_elapsed >= $this->get_setting('buffer_timeout')) {
                $this->flush_buffer();
            }
        } catch (Exception $e) {
            $this->log('Auto-flush check error: ' . $e->getMessage(), 'error');
        }
    }
    
    public function flush_buffer() {
        try {
            $buffer = get_option(WPB_VIEWS_BUFFER_OPTION, array());
            
            if (empty($buffer) || !is_array($buffer)) {
                return false;
            }
            
            $this->log("Flushing buffer with " . count($buffer) . " items");
            
            foreach ($buffer as $post_id => $views_to_add) {
                $post_id = intval($post_id);
                $views_to_add = intval($views_to_add);
                
                if ($post_id <= 0 || $views_to_add <= 0) {
                    continue;
                }
                
                $current_count = (int) get_post_meta($post_id, WPB_VIEWS_META_KEY, true) ?: 0;
                $new_count = $current_count + $views_to_add;
                update_post_meta($post_id, WPB_VIEWS_META_KEY, $new_count);
                
                $this->record_daily_view($post_id, $views_to_add);
                
                $this->log("Post $post_id: $current_count -> $new_count (+$views_to_add)");
            }
            
            delete_option(WPB_VIEWS_BUFFER_OPTION);
            $this->log("Buffer deleted. Checking: " . json_encode(get_option(WPB_VIEWS_BUFFER_OPTION, array())));
            // $this->buffer_last_flush = time();
            $this->buffer_last_flush = current_time('timestamp');
            update_option('wpb_views_last_flush', $this->buffer_last_flush, false);
            
            return true;
            
        } catch (Exception $e) {
            $this->log('Flush buffer error: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    private function record_daily_view($post_id, $count = 1) {
        global $wpdb;
        
        try {
            $table_name = $wpdb->prefix . 'post_views';
            
            // Verificar que la tabla existe
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                $this->log("Table $table_name does not exist", 'error');
                return false;
            }
            
            $today = current_time('Y-m-d');
            
            $result = $wpdb->query($wpdb->prepare(
                "INSERT INTO $table_name (post_id, view_date, view_count) 
                 VALUES (%d, %s, %d)
                 ON DUPLICATE KEY UPDATE view_count = view_count + %d",
                $post_id, $today, $count, $count
            ));
            
            if ($result === false) {
                $this->log("Failed to record daily view for post $post_id: " . $wpdb->last_error, 'error');
            }
            
            return $result;
            
        } catch (Exception $e) {
            $this->log('Record daily view error: ' . $e->getMessage(), 'error');
            return false;
        }
    }
    
    public function calculate_trending_scores() {
        global $wpdb;
        
        try {
            $table_name = $wpdb->prefix . 'post_views';
            
            // Verificar que la tabla existe
            if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") != $table_name) {
                throw new Exception("Table $table_name does not exist");
            }
            
            $this->log("Starting trending score calculation");
            
            $posts = $wpdb->get_results("
                SELECT 
                    post_id,
                    SUM(CASE WHEN view_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN view_count ELSE 0 END) as views_1d,
                    SUM(CASE WHEN view_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN view_count ELSE 0 END) as views_7d,
                    SUM(CASE WHEN view_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN view_count ELSE 0 END) as views_30d,
                    SUM(view_count) as views_total,
                    SUM(view_count * POW(0.7, DATEDIFF(CURDATE(), view_date))) as decayed_score
                FROM $table_name
                WHERE view_date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY)
                GROUP BY post_id
                HAVING decayed_score > 0
                ORDER BY decayed_score DESC
                LIMIT 100
            ");
            
            if ($wpdb->last_error) {
                throw new Exception("Query error: " . $wpdb->last_error);
            }
            
            $recent_weight = $this->get_setting('trending_weight');
            $total_weight = 1 - $recent_weight;
            
            foreach ($posts as $post) {
                $trending_score = ($post->decayed_score * $recent_weight) + ($post->views_total * $total_weight);
                update_post_meta($post->post_id, 'wpb_trending_score', $trending_score);
                // Guardar stats por rangos (para GraphQL viewStats)
                update_post_meta($post->post_id, 'wpb_views_1d', $post->views_1d);
                update_post_meta($post->post_id, 'wpb_views_7d', $post->views_7d);
                update_post_meta($post->post_id, 'wpb_views_30d', $post->views_30d);
                update_post_meta($post->post_id, 'wpb_views_total', $post->views_total);
            }
            
            // Limpiar datos antiguos
            $retention_days = $this->get_setting('retention_days');
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $table_name WHERE view_date < DATE_SUB(CURDATE(), INTERVAL %d DAY)",
                $retention_days
            ));
            
            $this->log("Calculated trending scores for " . count($posts) . " posts");
            
        } catch (Exception $e) {
            $this->log('Calculate trending error: ' . $e->getMessage(), 'error');
            throw $e;
        }
    }
    
    public function register_graphql_types() {
        register_graphql_field('Post', 'viewCount', array(
            'type'    => 'Int',
            'resolve' => function($post) {
                return (int) get_post_meta($post->databaseId, WPB_VIEWS_META_KEY, true) ?: 0;
            }
        ));
        
        register_graphql_field('Post', 'trendingScore', array(
            'type'    => 'Float',
            'resolve' => function($post) {
                return (float) get_post_meta($post->databaseId, 'wpb_trending_score', true) ?: 0;
            }
        ));
        
        register_graphql_connection(array(
            'fromType'           => 'RootQuery',
            'toType'             => 'Post',
            'fromFieldName'      => 'popularPosts',
            'connectionTypeName' => 'RootQueryToPopularPostsConnection',
            'connectionArgs'     => \WPGraphQL\Connection\PostObjects::get_connection_args(),
            'resolve'            => function($root, $args, $context, $info) {
                $resolver = new \WPGraphQL\Data\Connection\PostObjectConnectionResolver($root, $args, $context, $info);
                $resolver->set_query_arg('meta_key', WPB_VIEWS_META_KEY);
                $resolver->set_query_arg('orderby', 'meta_value_num');
                $resolver->set_query_arg('order', 'DESC');
                return $resolver->get_connection();
            }
        ));
        
        register_graphql_connection(array(
            'fromType'           => 'RootQuery',
            'toType'             => 'Post',
            'fromFieldName'      => 'trendingPosts',
            'connectionTypeName' => 'RootQueryToTrendingPostsConnection',
            'connectionArgs'     => \WPGraphQL\Connection\PostObjects::get_connection_args(),
            'resolve'            => function($root, $args, $context, $info) {
                $resolver = new \WPGraphQL\Data\Connection\PostObjectConnectionResolver($root, $args, $context, $info);
                $resolver->set_query_arg('meta_key', 'wpb_trending_score');
                $resolver->set_query_arg('orderby', 'meta_value_num');
                $resolver->set_query_arg('order', 'DESC');
                return $resolver->get_connection();
            }
        ));
    }
    
    public function add_cron_schedule($schedules) {
        $schedules['wpb_views_flush_interval'] = array(
            'interval' => $this->get_setting('buffer_timeout'),
            'display'  => sprintf('WPB Views Flush (%d sec)', $this->get_setting('buffer_timeout')),
        );
        return $schedules;
    }
    
    public function schedule_buffer_flush() {
        if (!wp_next_scheduled('wpb_views_buffer_flush_event')) {
            wp_schedule_event(time() + $this->get_setting('buffer_timeout'), 'wpb_views_flush_interval', 'wpb_views_buffer_flush_event');
        }
    }
    
    public function schedule_trending_calculation() {
        if (!wp_next_scheduled('wpb_views_calculate_trending_event')) {
            wp_schedule_event(strtotime('tomorrow 02:00'), 'daily', 'wpb_views_calculate_trending_event');
        }
    }
    
    private function get_client_ip() {
        $ip_headers = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR');
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field($_SERVER[$header]);
                if (strpos($ip, ',') !== false) {
                    $ip = trim(explode(',', $ip)[0]);
                }
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }
    
    private function is_bot($user_agent) {
        $bot_patterns = array('bot', 'crawl', 'spider', 'scraper', 'curl', 'wget', 'slurp', 'mediapartners');
        $user_agent_lower = strtolower($user_agent);
        
        foreach ($bot_patterns as $pattern) {
            if (strpos($user_agent_lower, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}

// Inicializar plugin
function wpb_views_counter_init() {
    return WPB_Views_Counter_Pro::get_instance();
}
add_action('plugins_loaded', 'wpb_views_counter_init');

register_activation_hook(__FILE__, function() {
    WPB_Views_Counter_Pro::get_instance()->activate();
});
register_deactivation_hook(__FILE__, function() {
    WPB_Views_Counter_Pro::get_instance()->deactivate();
});