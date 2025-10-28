<?php
/**
 * Integración de WPB Views con WPGraphQL
 * Agregar esto al functions.php de tu tema
 */

// Solo registrar si WPGraphQL está activo
if (class_exists('WPGraphQL')) {
    
  add_action('graphql_register_types', function() {
      
      // NOTA: Los campos viewCount y trendingScore ya están registrados en el plugin
      // Solo necesitamos registrar las conexiones personalizadas aquí
      
      /**
       * Conexión: popularPosts
       * Posts ordenados por total de vistas (histórico)
       */
      register_graphql_connection([
          'fromType'           => 'RootQuery',
          'toType'             => 'Post',
          'fromFieldName'      => 'popularPosts',
          'connectionTypeName' => 'RootQueryToPopularPostsConnection',
          'connectionArgs'     => \WPGraphQL\Connection\PostObjects::get_connection_args(),
          'resolve'            => function($root, $args, \WPGraphQL\AppContext $context, $info) {
              $resolver = new \WPGraphQL\Data\Connection\PostObjectConnectionResolver($root, $args, $context, $info);
              $resolver->set_query_arg('meta_key', 'wpb_post_views_count');
              $resolver->set_query_arg('orderby', 'meta_value_num');
              $resolver->set_query_arg('order', 'DESC');
              return $resolver->get_connection();
          }
      ]);
      
      /**
       * Conexión: trendingPosts
       * Posts ordenados por trending score (vistas recientes con más peso)
       * Mejor para mostrar contenido "hot" actual
       */
      register_graphql_connection([
          'fromType'           => 'RootQuery',
          'toType'             => 'Post',
          'fromFieldName'      => 'trendingPosts',
          'connectionTypeName' => 'RootQueryToTrendingPostsConnection',
          'connectionArgs'     => \WPGraphQL\Connection\PostObjects::get_connection_args(),
          'resolve'            => function($root, $args, \WPGraphQL\AppContext $context, $info) {
              $resolver = new \WPGraphQL\Data\Connection\PostObjectConnectionResolver($root, $args, $context, $info);
              $resolver->set_query_arg('meta_key', 'wpb_trending_score');
              $resolver->set_query_arg('orderby', 'meta_value_num');
              $resolver->set_query_arg('order', 'DESC');
              return $resolver->get_connection();
          }
      ]);
      
      /**
       * Campo: headlessUri
       * URI personalizada para frontend headless
       */
      register_graphql_field('ContentNode', 'headlessUri', [
          'type' => 'String',
          'resolve' => function($contentNode) {
              $permalink = get_permalink($contentNode->databaseId);
              
              // Definir URL del frontend (ajustar según tu configuración)
              $frontendUrl = defined('HEADLESS_URL') ? HEADLESS_URL : 'https://elpilon.com.co';
              
              // Reemplazar URL de WordPress con URL del frontend
              if (false !== stristr($permalink, get_site_url())) {
                  return str_ireplace(get_site_url(), $frontendUrl, $permalink);
              }
              
              return $permalink;
          }
      ]);
      
      /**
       * Campo: viewCount en Post
       * Total de vistas históricas
       */
      register_graphql_field('Post', 'viewCount', [
          'type'    => 'Int',
          'resolve' => function($post) {
              return (int) get_post_meta($post->databaseId, 'wpb_post_views_count', true) ?: 0;
          }
      ]);
      
      /**
       * Campo: trendingScore en Post
       * Score calculado con algoritmo de trending
       */
      register_graphql_field('Post', 'trendingScore', [
          'type'    => 'Float',
          'resolve' => function($post) {
              return (float) get_post_meta($post->databaseId, 'wpb_trending_score', true) ?: 0;
          }
      ]);
      
      /**
       * Campo: viewStats en Post
       * Estadísticas detalladas de vistas (últimos 7 y 30 días)
       */
      register_graphql_field('Post', 'viewStats', [
          'type' => 'PostViewStats',
          'resolve' => function($post) {
              global $wpdb;
              $table_name = $wpdb->prefix . 'post_views';
              
              $stats = $wpdb->get_row($wpdb->prepare("
                  SELECT 
                      SUM(CASE WHEN view_date >= DATE_SUB(CURDATE(), INTERVAL 1 DAY) THEN view_count ELSE 0 END) as views_1d,
                      SUM(CASE WHEN view_date >= DATE_SUB(CURDATE(), INTERVAL 7 DAY) THEN view_count ELSE 0 END) as views_7d,
                      SUM(CASE WHEN view_date >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) THEN view_count ELSE 0 END) as views_30d,
                      SUM(view_count) as views_total
                  FROM $table_name
                  WHERE post_id = %d
              ", $post->databaseId));
              
              return $stats ?: [
                  'views_1d' => 0,
                  'views_7d' => 0,
                  'views_30d' => 0,
                  'views_total' => (int) get_post_meta($post->databaseId, 'wpb_post_views_count', true) ?: 0
              ];
          }
      ]);
      
      /**
       * Tipo: PostViewStats
       * Objeto con estadísticas de vistas
       */
      register_graphql_object_type('PostViewStats', [
          'fields' => [
              'views_1d' => [
                  'type' => 'Int',
                  'description' => 'Vistas en las últimas 24 horas'
              ],
              'views_7d' => [
                  'type' => 'Int',
                  'description' => 'Vistas en los últimos 7 días'
              ],
              'views_30d' => [
                  'type' => 'Int',
                  'description' => 'Vistas en los últimos 30 días'
              ],
              'views_total' => [
                  'type' => 'Int',
                  'description' => 'Total de vistas históricas'
              ]
          ]
      ]);
  });
}

/**
 * OPCIONAL: Definir URL del frontend headless
 * Descomenta y ajusta según tu configuración
 */
// define('HEADLESS_URL', 'https://elpilon.com.co');