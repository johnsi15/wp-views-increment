<?php
/**
 * Plugin Name: WPB Views Increment Pro
 * Plugin URI: https://github.com/johnsi15/wp-views-increment
 * Description: Sistema optimizado de contador de vistas con buffering real, trending score y tabla personalizada
 * Version: 2.0.0
 * Author: John Serrano
 * Author URI: https://johnserrano.co
 * License: MIT
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Constantes de configuración
if (!defined('WPB_VIEWS_META_KEY')) {
    define('WPB_VIEWS_META_KEY', 'wpb_post_views_count');
}
if (!defined('WPB_VIEWS_USE_BUFFER')) {
    define('WPB_VIEWS_USE_BUFFER', true);
}
if (!defined('WPB_VIEWS_BUFFER_SIZE')) {
    define('WPB_VIEWS_BUFFER_SIZE', 100); // Flush cada 100 vistas
}
if (!defined('WPB_VIEWS_BUFFER_TIMEOUT')) {
    define('WPB_VIEWS_BUFFER_TIMEOUT', 300); // Flush cada 5 minutos
}
if (!defined('WPB_VIEWS_BUFFER_OPTION')) {
    define('WPB_VIEWS_BUFFER_OPTION', 'wpb_views_buffer');
}
if (!defined('WPB_VIEWS_TRANSIENT_TTL')) {
    define('WPB_VIEWS_TRANSIENT_TTL', 3600); // 1 hora
}
if (!defined('WPB_VIEWS_TRENDING_WEIGHT')) {
    define('WPB_VIEWS_TRENDING_WEIGHT', 0.7); // 70% peso para vistas recientes
}
if (!defined('WPB_VIEWS_DEBUG')) {
    define('WPB_VIEWS_DEBUG', false);
}

class WPB_Views_Counter_Pro {
    
    private static $instance = null;
    private $buffer_last_flush = 0;
    
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
        
        // Cron para flush del buffer
        add_action('wp', array($this, 'schedule_buffer_flush'));
        add_action('wpb_views_buffer_flush_event', array($this, 'flush_buffer'));
        add_filter('cron_schedules', array($this, 'add_cron_schedule'));
        
        // Cron diario para calcular trending score
        add_action('wp', array($this, 'schedule_trending_calculation'));
        add_action('wpb_views_calculate_trending_event', array($this, 'calculate_trending_scores'));
        
        // GraphQL (solo si está disponible)
        if (class_exists('WPGraphQL')) {
            add_action('graphql_register_types', array($this, 'register_graphql_types'));
        }
        
        // Activación/Desactivación
        register_activation_hook(__FILE__, array($this, 'activate'));
        register_deactivation_hook(__FILE__, array($this, 'deactivate'));
        
        // Inicializar timestamp de último flush
        $this->buffer_last_flush = get_option('wpb_views_last_flush', time());
    }
    
    /**
     * Activación del plugin - crear tabla personalizada
     */
    public function activate() {
        global $wpdb;
        
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
        dbDelta($sql);
        
        // Crear índice en postmeta si no existe
        $wpdb->query("CREATE INDEX IF NOT EXISTS idx_wpb_views ON {$wpdb->postmeta} (meta_key, meta_value)");
        
        // Programar eventos cron
        if (!wp_next_scheduled('wpb_views_buffer_flush_event')) {
            wp_schedule_event(time(), 'wpb_views_flush_interval', 'wpb_views_buffer_flush_event');
        }
        if (!wp_next_scheduled('wpb_views_calculate_trending_event')) {
            wp_schedule_event(time(), 'daily', 'wpb_views_calculate_trending_event');
        }
    }
    
    /**
     * Desactivación del plugin
     */
    public function deactivate() {
        // Flush final del buffer
        $this->flush_buffer();
        
        // Remover cron jobs
        wp_clear_scheduled_hook('wpb_views_buffer_flush_event');
        wp_clear_scheduled_hook('wpb_views_calculate_trending_event');
    }
    
    /**
     * Registrar meta del post
     */
    public function register_post_meta() {
        register_post_meta('post', WPB_VIEWS_META_KEY, array(
            'type'              => 'integer',
            'single'            => true,
            'sanitize_callback' => 'absint',
            'show_in_rest'      => true,
            'default'           => 0
        ));
        
        // Meta para trending score (combinación de vistas totales + recientes)
        register_post_meta('post', 'wpb_trending_score', array(
            'type'              => 'number',
            'single'            => true,
            'sanitize_callback' => 'floatval',
            'show_in_rest'      => true,
            'default'           => 0
        ));
    }
    
    /**
     * Registrar rutas REST
     */
    public function register_rest_routes() {
        register_rest_route('wpb/v1', '/increment-view', array(
            'methods'             => array('POST', 'OPTIONS'),
            'callback'            => array($this, 'rest_increment_view'),
            'permission_callback' => '__return_true',
            'args'                => array(
                'post_id' => array(
                    'type'     => 'integer',
                    'required' => false
                ),
                'slug' => array(
                    'type'     => 'string',
                    'required' => false
                )
            )
        ));
        
        register_rest_route('wpb/v1', '/status', array(
            'methods'             => 'GET',
            'callback'            => array($this, 'rest_get_status'),
            'permission_callback' => '__return_true',
        ));
        
        register_rest_route('wpb/v1', '/flush-buffer', array(
            'methods'             => 'POST',
            'callback'            => array($this, 'rest_flush_buffer'),
            'permission_callback' => function() {
                return current_user_can('manage_options');
            },
        ));
    }
    
    /**
     * Status del plugin
     */
    public function rest_get_status($request) {
        $buffer = get_option(WPB_VIEWS_BUFFER_OPTION, array());
        $buffer_size = is_array($buffer) ? count($buffer) : 0;
        
        return rest_ensure_response(array(
            'version' => '2.0.0',
            'meta_key' => WPB_VIEWS_META_KEY,
            'buffer_enabled' => WPB_VIEWS_USE_BUFFER,
            'buffer_size' => $buffer_size,
            'buffer_max' => WPB_VIEWS_BUFFER_SIZE,
            'last_flush' => $this->buffer_last_flush,
            'status' => 'ok'
        ));
    }
    
    /**
     * Flush manual del buffer (admin)
     */
    public function rest_flush_buffer($request) {
        $result = $this->flush_buffer();
        return rest_ensure_response(array(
            'success' => $result,
            'message' => $result ? 'Buffer flushed successfully' : 'No items to flush'
        ));
    }
    
    /**
     * Setup CORS
     */
    public function setup_cors() {
        remove_filter('rest_pre_serve_request', 'rest_send_cors_headers');
        add_filter('rest_pre_serve_request', function($value) {
            $origin = get_http_origin();
            $allowed_origins = array(
                'http://54.189.168.166',
                'https://elpilon.com.co'
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
    
    /**
     * Incrementar vista con buffering
     */
    public function rest_increment_view($request) {
        if ($request->get_method() === 'OPTIONS') {
            return rest_ensure_response(array('status' => 'ok'));
        }
        
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
        
        // PROTECCIÓN ANTI-SPAM
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
        
        // DETECTAR BOTS
        if ($this->is_bot($user_agent)) {
            $count = (int) get_post_meta($post_id, WPB_VIEWS_META_KEY, true) ?: 0;
            return rest_ensure_response(array(
                'count' => $count,
                'incremented' => false,
                'reason' => 'bot_detected'
            ));
        }
        
        // BUFFERING: Agregar al buffer en lugar de escribir inmediatamente
        if (WPB_VIEWS_USE_BUFFER) {
            $this->add_to_buffer($post_id);
            $count = (int) get_post_meta($post_id, WPB_VIEWS_META_KEY, true) ?: 0;
            
            // Auto-flush si el buffer está lleno o ha pasado el tiempo
            $this->maybe_auto_flush();
            
            set_transient($viewer_key, true, WPB_VIEWS_TRANSIENT_TTL);
            
            return rest_ensure_response(array(
                'count' => $count + 1, // Estimado (incluye buffer)
                'incremented' => true,
                'buffered' => true
            ));
        }
        
        // Sin buffering: actualizar directamente
        $count = (int) get_post_meta($post_id, WPB_VIEWS_META_KEY, true) ?: 0;
        $count++;
        update_post_meta($post_id, WPB_VIEWS_META_KEY, $count);
        
        // Registrar en tabla de vistas diarias
        $this->record_daily_view($post_id);
        
        set_transient($viewer_key, true, WPB_VIEWS_TRANSIENT_TTL);
        
        return rest_ensure_response(array(
            'count' => $count,
            'incremented' => true,
            'buffered' => false
        ));
    }
    
    /**
     * Agregar vista al buffer
     */
    private function add_to_buffer($post_id) {
        $buffer = get_option(WPB_VIEWS_BUFFER_OPTION, array());
        
        if (!is_array($buffer)) {
            $buffer = array();
        }
        
        // Incrementar contador en el buffer
        if (!isset($buffer[$post_id])) {
            $buffer[$post_id] = 0;
        }
        $buffer[$post_id]++;
        
        update_option(WPB_VIEWS_BUFFER_OPTION, $buffer, false);
        
        if (WPB_VIEWS_DEBUG) {
            error_log("WPB Views: Added post $post_id to buffer. Buffer size: " . count($buffer));
        }
    }
    
    /**
     * Auto-flush del buffer si se cumplen condiciones
     */
    private function maybe_auto_flush() {
        $buffer = get_option(WPB_VIEWS_BUFFER_OPTION, array());
        $buffer_size = is_array($buffer) ? count($buffer) : 0;
        $time_elapsed = time() - $this->buffer_last_flush;
        
        // Flush si el buffer está lleno o ha pasado el tiempo
        if ($buffer_size >= WPB_VIEWS_BUFFER_SIZE || $time_elapsed >= WPB_VIEWS_BUFFER_TIMEOUT) {
            $this->flush_buffer();
        }
    }
    
    /**
     * Flush del buffer a la base de datos
     */
    public function flush_buffer() {
        $buffer = get_option(WPB_VIEWS_BUFFER_OPTION, array());
        
        if (empty($buffer) || !is_array($buffer)) {
            return false;
        }
        
        if (WPB_VIEWS_DEBUG) {
            error_log("WPB Views: Flushing buffer with " . count($buffer) . " items");
        }
        
        // Procesar cada post en el buffer
        foreach ($buffer as $post_id => $views_to_add) {
            $post_id = intval($post_id);
            $views_to_add = intval($views_to_add);
            
            if ($post_id <= 0 || $views_to_add <= 0) {
                continue;
            }
            
            // Actualizar contador total
            $current_count = (int) get_post_meta($post_id, WPB_VIEWS_META_KEY, true) ?: 0;
            $new_count = $current_count + $views_to_add;
            update_post_meta($post_id, WPB_VIEWS_META_KEY, $new_count);
            
            // Registrar vistas diarias (una sola operación por post)
            $this->record_daily_view($post_id, $views_to_add);
            
            if (WPB_VIEWS_DEBUG) {
                error_log("WPB Views: Post $post_id updated from $current_count to $new_count (+$views_to_add)");
            }
        }
        
        // Limpiar buffer
        delete_option(WPB_VIEWS_BUFFER_OPTION);
        $this->buffer_last_flush = time();
        update_option('wpb_views_last_flush', $this->buffer_last_flush, false);
        
        return true;
    }
    
    /**
     * Registrar vista diaria en tabla personalizada
     */
    private function record_daily_view($post_id, $count = 1) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'post_views';
        $today = current_time('Y-m-d');
        
        // INSERT ON DUPLICATE KEY UPDATE (más eficiente)
        $wpdb->query($wpdb->prepare(
            "INSERT INTO $table_name (post_id, view_date, view_count) 
             VALUES (%d, %s, %d)
             ON DUPLICATE KEY UPDATE view_count = view_count + %d",
            $post_id, $today, $count, $count
        ));
    }
    
    /**
     * Calcular trending scores (ejecutado diariamente)
     */
    public function calculate_trending_scores() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'post_views';
        
        // Obtener posts con vistas en los últimos 30 días
        $posts = $wpdb->get_results("
            SELECT 
                post_id,
                SUM(CASE WHEN view_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN view_count ELSE 0 END) as views_7d,
                SUM(CASE WHEN view_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN view_count ELSE 0 END) as views_30d,
                SUM(view_count) as views_total
            FROM $table_name
            WHERE view_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
            GROUP BY post_id
        ");
        
        foreach ($posts as $post) {
            // Fórmula de trending: 70% vistas recientes + 30% vistas totales
            // Normalizado con decay temporal
            $recent_weight = WPB_VIEWS_TRENDING_WEIGHT;
            $total_weight = 1 - $recent_weight;
            
            $trending_score = 
                ($post->views_7d * 2) * $recent_weight +  // Últimos 7 días con doble peso
                ($post->views_30d) * $recent_weight +     // Últimos 30 días
                ($post->views_total) * $total_weight;     // Total histórico
            
            // Actualizar trending score
            update_post_meta($post->post_id, 'wpb_trending_score', $trending_score);
        }
        
        // Limpiar datos antiguos (opcional, mantener solo últimos 90 días)
        $wpdb->query("DELETE FROM $table_name WHERE view_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)");
        
        if (WPB_VIEWS_DEBUG) {
            error_log("WPB Views: Calculated trending scores for " . count($posts) . " posts");
        }
    }
    
    /**
     * Registrar tipos GraphQL
     */
    public function register_graphql_types() {
        // Campo viewCount
        register_graphql_field('Post', 'viewCount', array(
            'type'    => 'Int',
            'resolve' => function($post) {
                return (int) get_post_meta($post->databaseId, WPB_VIEWS_META_KEY, true) ?: 0;
            }
        ));
        
        // Campo trendingScore
        register_graphql_field('Post', 'trendingScore', array(
            'type'    => 'Float',
            'resolve' => function($post) {
                return (float) get_post_meta($post->databaseId, 'wpb_trending_score', true) ?: 0;
            }
        ));
        
        // Conexión popularPosts (por total de vistas)
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
        
        // Conexión trendingPosts (por trending score)
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
    
    /**
     * Programar cron schedules
     */
    public function add_cron_schedule($schedules) {
        $schedules['wpb_views_flush_interval'] = array(
            'interval' => WPB_VIEWS_BUFFER_TIMEOUT,
            'display'  => sprintf('WPB Views Flush Interval (%d seconds)', WPB_VIEWS_BUFFER_TIMEOUT),
        );
        return $schedules;
    }
    
    public function schedule_buffer_flush() {
        if (!wp_next_scheduled('wpb_views_buffer_flush_event')) {
            wp_schedule_event(time() + WPB_VIEWS_BUFFER_TIMEOUT, 'wpb_views_flush_interval', 'wpb_views_buffer_flush_event');
        }
    }
    
    public function schedule_trending_calculation() {
        if (!wp_next_scheduled('wpb_views_calculate_trending_event')) {
            wp_schedule_event(strtotime('tomorrow 02:00'), 'daily', 'wpb_views_calculate_trending_event');
        }
    }
    
    /**
     * Obtener IP del cliente
     */
    private function get_client_ip() {
        $ip_headers = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR');
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field($_SERVER[$header]);
                // Tomar solo la primera IP si hay una lista
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
    
    /**
     * Detectar bots
     */
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

// Inicializar el plugin (singleton)
function wpb_views_counter_init() {
    return WPB_Views_Counter_Pro::get_instance();
}
add_action('plugins_loaded', 'wpb_views_counter_init');