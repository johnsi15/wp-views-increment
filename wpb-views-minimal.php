<?php
/**
 * Plugin Name: WPB Views Increment
 * Plugin URI: https://github.com/johnsi15/wp-views-increment
 * Description: Sistema completo de contador de vistas con REST API, buffering y protección anti-spam
 * Version: 1.2.0
 * Author: John Serrano
 * Author URI: https://johnserrano.co
 * License: MIT
 */

// Prevenir acceso directo
if (!defined('ABSPATH')) {
    exit;
}

// Solo definir constantes básicas
if (!defined('WPB_VIEWS_META_KEY')) {
    define('WPB_VIEWS_META_KEY', 'wpb_post_views_count');
}
if (!defined('WPB_VIEWS_USE_BUFFER')) {
    define('WPB_VIEWS_USE_BUFFER', true);
}
if (!defined('WPB_VIEWS_BUFFER_FLUSH_INTERVAL')) {
    define('WPB_VIEWS_BUFFER_FLUSH_INTERVAL', 5 * 60);
}
if (!defined('WPB_VIEWS_BUFFER_OPTION')) {
    define('WPB_VIEWS_BUFFER_OPTION', 'wpb_views_buffer');
}
if (!defined('WPB_VIEWS_TRANSIENT_TTL')) {
    define('WPB_VIEWS_TRANSIENT_TTL', 3600);
}
if (!defined('WPB_VIEWS_DEBUG')) {
    define('WPB_VIEWS_DEBUG', false);
}

class WPB_Views_Counter {
    
    public function __construct() {
        add_action('init', array($this, 'register_post_meta'));
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        add_action('rest_api_init', array($this, 'setup_cors'));
        add_action('wp', array($this, 'schedule_buffer_flush'));
        add_action('wpb_views_buffer_flush_event', array($this, 'flush_buffer'));
        add_filter('cron_schedules', array($this, 'add_cron_schedule'));
    }
    
    public function register_post_meta() {
        register_post_meta('post', WPB_VIEWS_META_KEY, array(
            'type'              => 'integer',
            'single'            => true,
            'sanitize_callback' => 'absint',
            'show_in_rest'      => true,
            'default'           => 0
        ));
    }
    
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
    }
    
    public function rest_get_status($request) {
        return rest_ensure_response(array(
            'version' => '1.2.0',
            'meta_key' => WPB_VIEWS_META_KEY,
            'buffer_enabled' => WPB_VIEWS_USE_BUFFER,
            'status' => 'ok'
        ));
    }
    
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
        
        // Verificar si ya vio este post en la última hora
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
        
        // Incrementar vista
        $count = (int) get_post_meta($post_id, WPB_VIEWS_META_KEY, true) ?: 0;
        $count++;
        update_post_meta($post_id, WPB_VIEWS_META_KEY, $count);
        
        // Guardar transient para evitar doble conteo (1 hora)
        set_transient($viewer_key, true, WPB_VIEWS_TRANSIENT_TTL);
        
        return rest_ensure_response(array(
            'count' => $count,
            'incremented' => true,
            'buffered' => false
        ));
    }
    
    public function add_cron_schedule($schedules) {
        $schedules['wpb_views_flush_interval'] = array(
            'interval' => WPB_VIEWS_BUFFER_FLUSH_INTERVAL,
            'display'  => 'WPB Views Flush Interval (' . WPB_VIEWS_BUFFER_FLUSH_INTERVAL . 's)',
        );
        return $schedules;
    }
    
    public function schedule_buffer_flush() {
        if (!wp_next_scheduled('wpb_views_buffer_flush_event')) {
            wp_schedule_event(time() + WPB_VIEWS_BUFFER_FLUSH_INTERVAL, 'wpb_views_flush_interval', 'wpb_views_buffer_flush_event');
        }
    }
    
    public function flush_buffer() {
        // Simple implementation for now
        return true;
    }
    
    private function get_client_ip() {
        $ip_headers = array('HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'HTTP_CLIENT_IP', 'REMOTE_ADDR');
        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = sanitize_text_field($_SERVER[$header]);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return $ip;
                }
            }
        }
        return sanitize_text_field($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
    }
    
    private function is_bot($user_agent) {
        $bot_patterns = array('bot', 'crawl', 'spider', 'scraper', 'curl', 'wget');
        $user_agent_lower = strtolower($user_agent);
        
        foreach ($bot_patterns as $pattern) {
            if (strpos($user_agent_lower, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }
}

// Inicializar el plugin
new WPB_Views_Counter();