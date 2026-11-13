<?php
// जुना पगार Print — आता "कर्मचारी यादी Print / PDF" मध्ये (Basic, HRA, TA, वैद्यकीय, कपात, निव्वळ स्तंभांसह)
require_once __DIR__ . '/../includes/auth.php';
requireLogin('../');
header('Location: list-print.php?' . http_build_query(['out' => 'html', 'cols' => ['name', 'employee_code', 'designation', 'basic', 'hra', 'ta', 'medical', 'salary', 'deduct', 'net_salary'], 'group' => 'dept', 'title' => 'पगार अहवाल', 'orient' => 'L']), true, 302);
