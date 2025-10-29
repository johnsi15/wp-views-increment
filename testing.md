# 📘 Guía de Implementación - WPB Views Counter Pro v2.1

## 🚨 IMPORTANTE: No implementar directamente en producción

Esta guía te ayudará a probar el plugin de forma segura antes de llevarlo a producción.

---

## 📋 Pre-requisitos

1. **Acceso a cPanel o SSH**
2. **Backup completo** de la base de datos
3. **Acceso FTP/SFTP**
4. **Entorno de staging** (altamente recomendado)

---

## 🔄 Opción 1: Testing en Staging (RECOMENDADO)

### Paso 1: Crear entorno de staging

```bash
# Si tienes cPanel, usa la función "Staging" de WordPress Toolkit
# O crea manualmente:
1. Duplicar archivos de WordPress
2. Exportar base de datos
3. Importar a nueva base de datos
4. Actualizar wp-config.php con nuevas credenciales
5. Buscar/reemplazar URLs en la DB
```

### Paso 2: Instalar plugin en staging

```bash
# Subir vía FTP a /wp-content/plugins/wpb-views-counter-pro/
# O usar WP-CLI:
wp plugin install --activate /path/to/plugin.zip
```

### Paso 3: Configurar en WP Admin

1. Ir a **Settings > WPB Views**
2. Configurar:
   - ✅ Enable Buffering
   - Buffer Size: `100`
   - Buffer Timeout: `300` (5 minutos)
   - Trending Weight: `0.7`
   - ❌ Use External Cron (dejar desactivado por ahora)
   - ✅ Debug Mode (para ver logs)

### Paso 4: Testing funcional

```bash
# 1. Test de incremento de vista
curl -X POST https://staging.elpilon.com.co/wp-json/wpb/v1/increment-view \
  -H "Content-Type: application/json" \
  -d '{"post_id": 1}'

# Respuesta esperada:
# {"count":1,"incremented":true,"buffered":true}

# 2. Verificar buffer
curl https://staging.elpilon.com.co/wp-json/wpb/v1/status

# 3. Flush manual (desde admin o curl)
curl -X POST https://staging.elpilon.com.co/wp-json/wpb/v1/flush-buffer \
  -H "X-WP-Nonce: YOUR_NONCE_HERE"

# 4. Verificar que los datos se guardaron
# Revisar en wp_postmeta si wpb_post_views_count se actualizó
```

### Paso 5: Testing de carga (simulación de tráfico)

Crea este script `test-load.sh`:

```bash
#!/bin/bash

# Simular 100 vistas de diferentes posts
for i in {1..100}
do
  POST_ID=$((1 + $RANDOM % 50))  # Posts del 1 al 50
  curl -s -X POST https://staging.elpilon.com.co/wp-json/wpb/v1/increment-view \
    -H "Content-Type: application/json" \
    -d "{\"post_id\": $POST_ID}" &
  
  if [ $((i % 10)) -eq 0 ]; then
    echo "Enviadas $i vistas..."
    sleep 1
  fi
done

wait
echo "Test completado. Verifica el buffer en /wp-json/wpb/v1/status"
```

```bash
chmod +x test-load.sh
./test-load.sh
```

### Paso 6: Verificar resultados

1. **En Admin**: Settings > WPB Views > revisar logs
2. **En DB**: Verificar tabla `wp_post_views`
3. **GraphQL**: Probar query de trending posts

---

## 🔄 Opción 2: Testing en Producción (con precauciones)

⚠️ **Solo si NO tienes staging**

### Paso 1: Backup

```bash
# Backup de archivos
tar -czf wordpress-backup-$(date +%Y%m%d).tar.gz /path/to/wordpress

# Backup de base de datos
wp db export backup-$(date +%Y%m%d).sql
# O vía phpMyAdmin
```

### Paso 2: Instalar en modo "shadow"

1. Subir plugin PERO NO activar aún
2. Crear archivo `test-plugin.php` en la raíz de WP:

```php
<?php
// test-plugin.php - Testing manual del plugin
define('WP_USE_THEMES', false);
require('./wp-load.php');

// Cargar el plugin manualmente
require_once('./wp-content/plugins/wpb-views-counter-pro/wpb-views-counter-pro.php');

// Test 1: Verificar que se carga
echo "✓ Plugin cargado\n";

// Test 2: Simular vista
$_SERVER['REQUEST_METHOD'] = 'POST';
$_SERVER['REMOTE_ADDR'] = '192.168.1.1';
$_POST['post_id'] = 1;

$plugin = WPB_Views_Counter_Pro::get_instance();
echo "✓ Instancia creada\n";

// Test 3: Verificar buffer
$buffer = get_option('wpb_views_buffer', array());
echo "Buffer actual: " . count($buffer) . " items\n";

echo "\n¡Tests básicos OK! Puedes activar el plugin.\n";
```

```bash
php test-plugin.php
```

### Paso 3: Activar gradualmente

1. Activar plugin
2. **NO habilitar buffering aún**
3. Monitorear durante 1 hora
4. Si todo OK, habilitar buffering con `buffer_size: 10` (pequeño)
5. Aumentar gradualmente a 100

---

## ⏰ Configuración de External Cron (CRÍTICO para producción)

### ¿Por qué External Cron?

WP-Cron se ejecuta solo cuando hay visitas. En horarios de bajo tráfico (2-6 AM):
- El buffer puede NO hacer flush → pérdida de vistas
- Trending NO se calcula → rankings obsoletos

### Configuración en cPanel

1. **Obtener token de seguridad**:
   - Ir a Settings > WPB Views
   - Copiar el token que aparece en "Use External Cron"

2. **Configurar Cron Jobs en cPanel**:

```bash
# Cron 1: Flush buffer cada 5 minutos
*/5 * * * * curl -s "https://elpilon.com.co/wp-json/wpb/v1/cron/flush-buffer?token=TU_TOKEN_AQUI" > /dev/null 2>&1

# Cron 2: Trending diario a las 2 AM
0 2 * * * curl -s "https://elpilon.com.co/wp-json/wpb/v1/cron/calculate-trending?token=TU_TOKEN_AQUI" > /dev/null 2>&1
```

3. **En WPB Views Settings**:
   - ✅ Marcar "Use External Cron"
   - Guardar

4. **Verificar**:
```bash
# Probar manualmente
curl "https://elpilon.com.co/wp-json/wpb/v1/cron/flush-buffer?token=TU_TOKEN"

# Debe responder: {"success":true}
```

### Configuración vía SSH (si tienes acceso)

```bash
crontab -e

# Agregar:
*/5 * * * * /usr/bin/curl -s "https://elpilon.com.co/wp-json/wpb/v1/cron/flush-buffer?token=TOKEN" > /dev/null 2>&1
0 2 * * * /usr/bin/curl -s "https://elpilon.com.co/wp-json/wpb/v1/cron/calculate-trending?token=TOKEN" > /dev/null 2>&1
```

---

## 🧪 Testing Simple sin PHPUnit

Crea `wp-content/plugins/wpb-views-test.php`:

```php
<?php
/**
 * Plugin Name: WPB Views - Simple Tests
 * Description: Testing básico del plugin WPB Views
 */

add_action('admin_menu', function() {
    add_management_page(
        'WPB Views Tests',
        'WPB Views Tests',
        'manage_options',
        'wpb-views-tests',
        'wpb_views_render_tests'
    );
});

function wpb_views_render_tests() {
    ?>
    <div class="wrap">
        <h1>WPB Views Counter - Tests</h1>
        
        <?php
        // Test 1: Plugin activo
        echo '<h2>Test 1: Plugin Status</h2>';
        if (class_exists('WPB_Views_Counter_Pro')) {
            echo '<p style="color: green;">✓ Plugin cargado correctamente</p>';
        } else {
            echo '<p style="color: red;">✗ Plugin NO encontrado</p>';
            return;
        }
        
        // Test 2: Tabla existe
        global $wpdb;
        $table_name = $wpdb->prefix . 'post_views';
        echo '<h2>Test 2: Database Table</h2>';
        if ($wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name) {
            echo '<p style="color: green;">✓ Tabla wp_post_views existe</p>';
            
            $count = $wpdb->get_var("SELECT COUNT(*) FROM $table_name");
            echo "<p>Registros: $count</p>";
        } else {
            echo '<p style="color: red;">✗ Tabla NO existe</p>';
        }
        
        // Test 3: Buffer
        echo '<h2>Test 3: Buffer</h2>';
        $buffer = get_option('wpb_views_buffer', array());
        echo '<p>Items en buffer: ' . count($buffer) . '</p>';
        if (is_array($buffer) && !empty($buffer)) {
            echo '<pre>' . print_r($buffer, true) . '</pre>';
        }
        
        // Test 4: Settings
        echo '<h2>Test 4: Settings</h2>';
        $settings = get_option('wpb_views_settings');
        if ($settings) {
            echo '<pre>' . print_r($settings, true) . '</pre>';
        }
        
        // Test 5: Cron jobs
        echo '<h2>Test 5: Cron Jobs</h2>';
        $flush_scheduled = wp_next_scheduled('wpb_views_buffer_flush_event');
        $trending_scheduled = wp_next_scheduled('wpb_views_calculate_trending_event');
        
        if ($flush_scheduled) {
            echo '<p style="color: green;">✓ Flush cron programado para: ' . date('Y-m-d H:i:s', $flush_scheduled) . '</p>';
        } else {
            echo '<p style="color: orange;">⚠ Flush cron NO programado (OK si usas external cron)</p>';
        }
        
        if ($trending_scheduled) {
            echo '<p style="color: green;">✓ Trending cron programado para: ' . date('Y-m-d H:i:s', $trending_scheduled) . '</p>';
        } else {
            echo '<p style="color: orange;">⚠ Trending cron NO programado (OK si usas external cron)</p>';
        }
        
        // Test 6: Simular vista
        echo '<h2>Test 6: Simular Vista</h2>';
        echo '<form method="post">';
        echo '<input type="number" name="test_post_id" placeholder="Post ID" value="1" />';
        echo '<button type="submit" name="test_view" class="button">Simular Vista</button>';
        echo '</form>';
        
        if (isset($_POST['test_view'])) {
            $post_id = intval($_POST['test_post_id']);
            $plugin = WPB_Views_Counter_Pro::get_instance();
            
            // Simular vista directamente
            $buffer = get_option('wpb_views_buffer', array());
            if (!isset($buffer[$post_id])) {
                $buffer[$post_id] = 0;
            }
            $buffer[$post_id]++;
            update_option('wpb_views_buffer', $buffer, false);
            
            echo '<p style="color: green;">✓ Vista simulada para post ' . $post_id . '</p>';
            echo '<p>Refresca la página para ver el buffer actualizado</p>';
        }
        
        // Test 7: Logs
        echo '<h2>Test 7: Recent Logs</h2>';
        $logs = get_option('wpb_views_logs', array());
        if (!empty($logs)) {
            echo '<div style="background: #f5f5f5; padding: 10px; max-height: 200px; overflow-y: auto; font-family: monospace; font-size: 11px;">';
            foreach (array_slice($logs, 0, 20) as $log) {
                echo esc_html($log) . '<br>';
            }
            echo '</div>';
        } else {
            echo '<p>No hay logs (activa Debug Mode en Settings)</p>';
        }
        ?>
        
        <hr>
        <h2>Acciones Manuales</h2>
        <form method="post">
            <button type="submit" name="manual_flush" class="button button-primary">Flush Buffer</button>
            <button type="submit" name="manual_trending" class="button button-primary">Calculate Trending</button>
        </form>
        
        <?php
        if (isset($_POST['manual_flush'])) {
            $plugin = WPB_Views_Counter_Pro::get_instance();
            $result = $plugin->flush_buffer();
            echo '<p style="color: green;">Buffer flushed: ' . ($result ? 'OK' : 'No items') . '</p>';
        }
        
        if (isset($_POST['manual_trending'])) {
            $plugin = WPB_Views_Counter_Pro::get_instance();
            try {
                $plugin->calculate_trending_scores();
                echo '<p style="color: green;">Trending scores calculated</p>';
            } catch (Exception $e) {
                echo '<p style="color: red;">Error: ' . $e->getMessage() . '</p>';
            }
        }
        ?>
    </div>
    <?php
}
```

**Uso**:
1. Activa este plugin de testing
2. Ve a **Tools > WPB Views Tests**
3. Verás todos los tests y podrás simular vistas

---

## 📊 Monitoreo Post-Deploy

### Día 1-3: Monitoreo intensivo

```bash
# Ver logs en tiempo real (si tienes SSH)
tail -f /path/to/wordpress/wp-content/debug.log | grep "WPB Views"

# O revisar en Admin: Settings > WPB Views > Logs
```

### Qué verificar:

- ✅ Buffer hace flush correctamente
- ✅ No hay errores en logs
- ✅ Trending scores se calculan diariamente
- ✅ GraphQL queries funcionan
- ✅ Rendimiento del sitio no se degrada

### Queries útiles:

```sql
-- Ver posts con más vistas hoy
SELECT pv.post_id, p.post_title, pv.view_count
FROM wp_post_views pv
JOIN wp_posts p ON pv.post_id = p.ID
WHERE pv.view_date = CURDATE()
ORDER BY pv.view_count DESC
LIMIT 10;

-- Ver trending scores
SELECT p.ID, p.post_title, 
       pm1.meta_value as total_views,
       pm2.meta_value as trending_score
FROM wp_posts p
LEFT JOIN wp_postmeta pm1 ON p.ID = pm1.post_id AND pm1.meta_key = 'wpb_post_views_count'
LEFT JOIN wp_postmeta pm2 ON p.ID = pm2.post_id AND pm2.meta_key = 'wpb_trending_score'
WHERE p.post_type = 'post'
ORDER BY CAST(pm2.meta_value AS DECIMAL(10,2)) DESC
LIMIT 10;
```

---

## 🆘 Rollback Plan

Si algo sale mal:

### Plan A: Desactivar buffering
1. Settings > WPB Views
2. ❌ Desmarcar "Enable Buffering"
3. Guardar

### Plan B: Desactivar plugin
```bash
wp plugin deactivate wpb-views-counter-pro
```

### Plan C: Restaurar plugin anterior
```bash
# Eliminar nuevo plugin
rm -rf wp-content/plugins/wpb-views-counter-pro

# Subir plugin anterior
# Activar
wp plugin activate wpb-views-increment
```

### Plan D: Restaurar DB (último recurso)
```bash
wp db import backup-FECHA.sql
```

---

## ✅ Checklist Final

Antes de dar por completada la migración:

- [ ] Plugin activo en producción
- [ ] External cron configurado
- [ ] Buffer funcionando (verificar /status)
- [ ] Trending calculándose diariamente
- [ ] GraphQL queries funcionando
- [ ] Logs sin errores críticos
- [ ] Rendimiento OK (no más de +50ms por request)
- [ ] Backup reciente disponible
- [ ] Documentación actualizada
- [ ] Equipo notificado

---

## 💡 Tips Finales

1. **Empieza con buffer pequeño** (10-20) y auméntalo gradualmente
2. **Activa Debug Mode** solo temporalmente (genera logs grandes)
3. **Monitorea el tamaño del buffer** en horas pico
4. **Ajusta trending_weight** según tus necesidades (0.5-0.9)
5. **External cron es CRÍTICO** - no lo omitas

---

¿Dudas? Revisa los logs en Settings > WPB Views o activa el plugin de testing.