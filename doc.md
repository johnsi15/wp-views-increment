# 🚀 Mejoras Implementadas

1. Buffering Real en Memoria

Las vistas se acumulan en un array en wp_options (no en postmeta)
Flush automático cuando:

El buffer alcanza 100 entradas (configurable)
Pasan 5 minutos desde el último flush


Reduce drasticamente los locks de base de datos

# 2. Sistema de Trending Score
Tu problema principal resuelto! Ahora tienes dos tipos de ranking:
popularPosts - Posts con más vistas totales (histórico)
trendingPosts - Posts "hot" calculados con:

70% peso para vistas recientes (últimos 7-30 días)
30% peso para vistas totales
Se recalcula diariamente a las 2 AM

Esto significa que un post nuevo con 5,000 vistas esta semana puede superar a uno viejo con 50,000 vistas totales!
# 3. Tabla Personalizada

Nueva tabla wp_post_views para vistas diarias
Índices optimizados
Permite queries rápidas por fecha
Auto-limpieza de datos antiguos (>90 días)

# 4. Estadísticas Detalladas
Ahora puedes obtener:

Vistas últimas 24 horas
Vistas últimos 7 días
Vistas últimos 30 días
Total histórico

# 5. GraphQL Mejorado

viewStats - objeto con todas las estadísticas
trendingScore - score calculado
Integración opcional (solo si WPGraphQL está activo)

# 📋 Pasos de Instalación

Reemplaza el plugin actual con el código del artifact "WPB Views Increment Pro"
Actualiza functions.php con el código del artifact "functions.php - GraphQL Integration"
Activa el plugin (o desactiva y reactiva):

Esto creará la tabla wp_post_views
Programará los cron jobs


Ejecuta manualmente el primer cálculo de trending:

php// En WP-CLI o temporalmente en functions.php
do_action('wpb_views_calculate_trending_event');

Prueba las queries GraphQL usando los ejemplos del artifact

# 🎯 Queries Recomendadas para Tu Caso
Para tu sidebar "Lo más leído", usa trendingPosts:
graphqlquery GetTrendingThisWeek {
  trendingPosts(first: 10) {
    nodes {
      title
      headlessUri
      viewStats {
        views_7d
      }
      featuredImage {
        node {
          sourceUrl
        }
      }
    }
  }
}

# ⚙️ Configuración
Puedes ajustar estas constantes en el plugin:
```php
phpdefine('WPB_VIEWS_BUFFER_SIZE', 100);      // Vistas antes de flush
define('WPB_VIEWS_BUFFER_TIMEOUT', 300);   // Segundos antes de flush
define('WPB_VIEWS_TRENDING_WEIGHT', 0.7);  // 70% peso reciente, 30% total
define('WPB_VIEWS_DEBUG', true);           // Logs para debugging
```

## 🔍 Monitoreo

Para ver el estado del buffer:
```cli
GET /wp-json/wpb/v1/status
```

Para flush manual (solo admin):
```
POST /wp-json/wpb/v1/flush-buffer
```
## ⚠️ Importante

- El trending score se calcula diariamente a las 2 AM (cron job)
- Los primeros días los trending scores pueden parecer bajos hasta que se acumulen datos
- El buffer se flush automáticamente, pero puedes hacerlo manual para testing
- La tabla se limpia cada 90 días (configurable en calculate_trending_scores)