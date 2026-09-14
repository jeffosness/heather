<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once __DIR__ . '/../../includes/student_auth.php';

logout_student();
header('Location: /students/login.php');
exit;
