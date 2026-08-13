<?php
require_once __DIR__ . '/../../config/db.php';
requireAdmin();

$stats = [];

$stats['total_donors'] = $pdo
    ->query('SELECT COUNT(*) FROM donations')->fetchColumn();

$stats['donors_this_month'] = $pdo
    ->query("SELECT COUNT(*) FROM donations
             WHERE MONTH(created_at)=MONTH(NOW())
               AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();

$stats['total_units'] = $pdo
    ->query('SELECT COALESCE(SUM(units),0) FROM blood_inventory')->fetchColumn();

$stats['hospitals'] = $pdo
    ->query("SELECT COUNT(*) FROM hospitals WHERE status='Active'")->fetchColumn();

$stats['hospitals_this_month'] = $pdo
    ->query("SELECT COUNT(*) FROM hospitals
             WHERE MONTH(created_at)=MONTH(NOW())
               AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();

$stats['total_requests'] = $pdo
    ->query('SELECT COUNT(*) FROM blood_requests')->fetchColumn();

$stats['requests_this_month'] = $pdo
    ->query("SELECT COUNT(*) FROM blood_requests
             WHERE MONTH(created_at)=MONTH(NOW())
               AND YEAR(created_at)=YEAR(NOW())")->fetchColumn();

$stats['requests_fulfilled'] = $pdo
    ->query("SELECT COUNT(*) FROM blood_requests WHERE status='Fulfilled'")->fetchColumn();

$stats['inventory'] = $pdo
    ->query('SELECT blood_group, SUM(units) AS units FROM blood_inventory GROUP BY blood_group')
    ->fetchAll();

$stats['monthly_donations'] = $pdo
    ->query("SELECT DATE_FORMAT(created_at,'%b') AS label, COUNT(*) AS value
             FROM donations
             WHERE created_at >= DATE_SUB(DATE_FORMAT(NOW(),'%Y-%m-01'), INTERVAL 5 MONTH)
             GROUP BY YEAR(created_at), MONTH(created_at)
             ORDER BY YEAR(created_at), MONTH(created_at)")
    ->fetchAll();

$stats['top_hospitals'] = $pdo
    ->query("SELECT h.name,
                    COUNT(r.id) AS requests,
                    COALESCE(SUM(CASE WHEN r.status='Fulfilled' THEN r.units ELSE 0 END),0) AS units_received
             FROM hospitals h
             JOIN blood_requests r ON r.hospital_id = h.id
             GROUP BY h.id, h.name
             ORDER BY requests DESC
             LIMIT 5")
    ->fetchAll();

respond($stats);
