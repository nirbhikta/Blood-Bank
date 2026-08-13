<?php
require_once __DIR__ . '/../../config/db.php';
session_destroy();
respond(['success' => true]);