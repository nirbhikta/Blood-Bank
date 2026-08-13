<?php
require_once __DIR__ . '/../../config/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') respond(['error' => 'Method not allowed'], 405);

respond([
    'donors'     => (int) $pdo->query("SELECT COUNT(*) FROM donations WHERE status = 'Approved'")->fetchColumn(),
    'units'      => (int) $pdo->query('SELECT COALESCE(SUM(units),0) FROM blood_inventory')->fetchColumn(),
    'hospitals'  => (int) $pdo->query("SELECT COUNT(*) FROM hospitals WHERE status = 'Active'")->fetchColumn(),
    'fulfilled'  => (int) $pdo->query("SELECT COUNT(*) FROM blood_requests WHERE status = 'Fulfilled'")->fetchColumn(),
    'units_month'=> (int) $pdo->query("SELECT COALESCE(SUM(units),0) FROM blood_requests
                                       WHERE status = 'Fulfilled'
                                         AND MONTH(created_at) = MONTH(NOW())
                                         AND YEAR(created_at) = YEAR(NOW())")->fetchColumn(),
    'by_group'   => $pdo->query('SELECT blood_group, SUM(units) AS units
                                 FROM blood_inventory
                                 GROUP BY blood_group
                                 ORDER BY blood_group')->fetchAll(),
]);
