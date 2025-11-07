<?php
/**
 * Integración de WPB Views con WPGraphQL
 * Agregar esto al functions.php de tu tema
 */

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
  });
}

/**
 * OPCIONAL: Definir URL del frontend headless
 * Descomenta y ajusta según tu configuración
 */
// define('HEADLESS_URL', 'https://elpilon.com.co');