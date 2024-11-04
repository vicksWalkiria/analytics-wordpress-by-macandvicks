<?php
/*
Plugin Name: Analytics Tracker Plugin by MacAndVicks
Description: Plugin para mostrar un reporte de visitas en el área de administración de WordPress.
Version: 1.0
Author: MacAndVicks.com
*/

if (!defined('ABSPATH')) exit; // Proteger contra acceso directo

// Función para agregar el menú en el admin de WordPress
function analytics_report_menu() {
    add_menu_page(
        'Analytics Report',          // Título de la página
        'Analytics Report',          // Nombre del menú
        'manage_options',            // Capacidad
        'analytics-report',          // Slug de la página
        'analytics_report_page',     // Función para mostrar el contenido
        'dashicons-chart-line',      // Icono del menú
        20                           // Posición en el menú
    );
}
add_action('admin_menu', 'analytics_report_menu');

// Función para cargar el contenido de la página
function analytics_report_page() {
    global $wpdb;

    // Obtener las fechas únicas de la tabla analytics
    function getDatesFromRange($wpdb) {
        $query = "SELECT DISTINCT DATE(visit_date) as visit_date FROM {$wpdb->prefix}analytics ORDER BY visit_date";
        $dates = $wpdb->get_col($query);
        return $dates;
    }

    // Obtener fechas disponibles
    $available_dates = getDatesFromRange($wpdb);

    // Definir valores iniciales y filtros
    $date_filter = '';
    $bot_filter = '';
    $start_date = '';
    $end_date = '';
    $include_bots = isset($_POST['include_bots']) ? true : false;
    $is_single_day = false;

    if (isset($_POST['date_range'])) {
        switch ($_POST['date_range']) {
            case 'today':
                $start_date = date('Y-m-d');
                $end_date = $start_date;
                $is_single_day = true;
                break;
            case 'yesterday':
                $start_date = date('Y-m-d', strtotime('-1 day'));
                $end_date = $start_date;
                $is_single_day = true;
                break;
            case 'last7days':
                $start_date = date('Y-m-d', strtotime('-6 days'));
                $end_date = date('Y-m-d');
                break;
            case 'last30days':
                $start_date = date('Y-m-d', strtotime('-29 days'));
                $end_date = date('Y-m-d');
                break;
            case 'custom':
                $start_date = $_POST['start_date'];
                $end_date = $_POST['end_date'];
                $is_single_day = ($start_date === $end_date);
                break;
        }
        $date_filter = "DATE(visit_date) BETWEEN '$start_date' AND '$end_date'";
    }

    if (!$include_bots) {
        $bot_filter = "bot_name IS NULL";
    }

    // Filtro combinado para WHERE
    $where_clause = '';
    if ($date_filter && $bot_filter) {
        $where_clause = "WHERE $date_filter AND $bot_filter";
    } elseif ($date_filter) {
        $where_clause = "WHERE $date_filter";
    } elseif ($bot_filter) {
        $where_clause = "WHERE $bot_filter";
    }

    // Consultar visitas totales
    $total_visits_query = "SELECT COUNT(*) as total_visits FROM {$wpdb->prefix}analytics $where_clause";
    $total_visits = $wpdb->get_var($total_visits_query) ?? 0;

    // Consultar visitas por hora o por día
    $time_labels = [];
    $visit_counts = [];
    if ($is_single_day) {
        $time_visits_query = "SELECT HOUR(visit_date) as visit_hour, COUNT(*) as visit_count FROM {$wpdb->prefix}analytics $where_clause GROUP BY visit_hour ORDER BY visit_hour";
        $time_visits_results = $wpdb->get_results($time_visits_query);
        foreach ($time_visits_results as $row) {
            $time_labels[] = $row->visit_hour . ":00";
            $visit_counts[] = $row->visit_count;
        }
    } else {
        $daily_visits_query = "SELECT DATE(visit_date) as visit_day, COUNT(*) as visit_count FROM {$wpdb->prefix}analytics $where_clause GROUP BY DATE(visit_date) ORDER BY visit_day";
        $daily_visits_results = $wpdb->get_results($daily_visits_query);
        foreach ($daily_visits_results as $row) {
            $time_labels[] = $row->visit_day;
            $visit_counts[] = $row->visit_count;
        }
    }

    // Convertir datos a JSON para Chart.js
    $time_labels_json = json_encode($time_labels);
    $visit_counts_json = json_encode($visit_counts);

    // Calcular páginas vistas por sesión
    $sessions_query = "SELECT COUNT(*) as sessions FROM {$wpdb->prefix}analytics " . ($where_clause ? "$where_clause AND" : "WHERE") . " (referrer_url IS NULL OR referrer_url = '' OR (referrer_url LIKE '%http%' AND referrer_url != '" . home_url() . "'))";
    $sessions_count = $wpdb->get_var($sessions_query) ?? 1;
    $pages_per_session = $sessions_count > 0 ? ($total_visits / $sessions_count) : 0;

    // Construir el filtro combinado para la cláusula WHERE
    $where_clause = ''; // Aquí define tu cláusula WHERE de acuerdo a tus filtros

    // Consulta para las páginas vistas con detalles
    $pages_query = "
        SELECT url_visited, COUNT(*) as visit_count,
               (SELECT referrer_url FROM {$wpdb->prefix}analytics WHERE url_visited = a.url_visited GROUP BY referrer_url ORDER BY COUNT(*) DESC LIMIT 1) as top_referrer,
               MAX(bot_name) as bot_name
        FROM {$wpdb->prefix}analytics a
        $where_clause
        GROUP BY url_visited
        ORDER BY visit_count DESC";

    // Obtener los resultados de la consulta
    $pages_result = $wpdb->get_results($pages_query);

    // Consulta para el número de visitas de cada red social en el intervalo
    $social_query = "
        SELECT s.name as social_network, COUNT(a.id_social) as total_visits
        FROM {$wpdb->prefix}analytics a
        LEFT JOIN {$wpdb->prefix}social_networks s ON a.id_social = s.id_social
        $where_clause AND a.id_social IS NOT NULL
        GROUP BY a.id_social
        ORDER BY total_visits DESC";

    // Obtener los resultados de la consulta de redes sociales
    $social_result = $wpdb->get_results($social_query);

    // Contenido HTML de la página
    ?>
    <div class="wrap">
        <h1>Analytics Report</h1>

        <form method="POST" class="mb-4">
            <div class="form-group">
                <label for="date_range">Select Date Range:</label>
                <select name="date_range" id="date_range" class="form-control" onchange="toggleCustomDates(this.value)">
                    <option value="today">Today</option>
                    <option value="yesterday">Yesterday</option>
                    <option value="last7days">Last 7 Days</option>
                    <option value="last30days">Last 30 Days</option>
                    <option value="custom">Custom</option>
                </select>
            </div>

            <div id="custom_dates" class="form-group" style="display: none;">
                <label for="start_date">Start Date:</label>
                <input type="date" name="start_date" id="start_date" class="form-control" min="<?= $available_dates[0] ?>" max="<?= end($available_dates) ?>">
                <label for="end_date">End Date:</label>
                <input type="date" name="end_date" id="end_date" class="form-control" min="<?= $available_dates[0] ?>" max="<?= end($available_dates) ?>">
            </div>

            <div class="form-group form-check">
                <input type="checkbox" name="include_bots" id="include_bots" class="form-check-input" <?= $include_bots ? 'checked' : '' ?>>
                <label for="include_bots" class="form-check-label">Include Bot Visits</label>
            </div>

            <button type="submit" class="btn btn-primary mt-3">Show Report</button>
        </form>

        <h3>Total Visits: <?= $total_visits ?></h3>
        <h4>Pages per Session: <?= number_format($pages_per_session, 2) ?></h4>



        <h4>Visits Over Time</h4>
        <canvas id="visitsChart"></canvas>

        <!-- Tabla de páginas vistas -->
           <h4>Page Views</h4>
           <table class="table table-bordered">
               <thead class="thead-dark">
               <tr>
                   <th>URL</th>
                   <th>Visits</th>
                   <th>Top Referrer</th>
                   <th>Bot</th>
               </tr>
               </thead>
               <tbody>
               <?php while ($page = $pages_result->fetch_assoc()): ?>
                   <tr>
                       <td><?= htmlspecialchars($page['url_visited']) ?></td>
                       <td><?= htmlspecialchars($page['visit_count']) ?></td>
                       <td><?= htmlspecialchars($page['top_referrer'] ?? 'Direct') ?></td>
                       <td><?= $page['bot_name'] ? htmlspecialchars($page['bot_name']) : 'User' ?></td>
                   </tr>
               <?php endwhile; ?>
               </tbody>
           </table>

           <!-- Tabla de visitas por red social -->
           <h4>Social Network Visits</h4>
           <table class="table table-bordered">
               <thead class="thead-dark">
               <tr>
                   <th>Social Network</th>
                   <th>Total Visits</th>
               </tr>
               </thead>
               <tbody>
               <?php while ($social = $social_result->fetch_assoc()): ?>
                   <tr>
                       <td><?= htmlspecialchars($social['social_network']) ?></td>
                       <td><?= htmlspecialchars($social['total_visits']) ?></td>
                   </tr>
               <?php endwhile; ?>
               </tbody>
           </table>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        function toggleCustomDates(value) {
            document.getElementById('custom_dates').style.display = (value === 'custom') ? 'block' : 'none';
        }

        const ctx = document.getElementById('visitsChart').getContext('2d');
        const visitsChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: <?= $time_labels_json ?>,
                datasets: [{
                    label: 'Visits',
                    data: <?= $visit_counts_json ?>,
                    borderColor: 'rgba(75, 192, 192, 1)',
                    backgroundColor: 'rgba(75, 192, 192, 0.2)',
                    tension: 0.3
                }]
            },
            options: {
                responsive: true,
                scales: {
                    x: {
                        title: {
                            display: true,
                            text: 'Time'
                        }
                    },
                    y: {
                        title: {
                            display: true,
                            text: 'Visits'
                        },
                        beginAtZero: true
                    }
                }
            }
        });
    </script>
    <?php
}
