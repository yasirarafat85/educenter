<?php
// 📸 গেটেড করণীয়-স্ক্রিনশট — শুধু লগইন করা অ্যাডমিন/মডারেটর দেখতে পারেন।
// ছবি uploads/tasks/-এ deny-all .htaccess দিয়ে সুরক্ষিত; সরাসরি URL দিয়ে খোলা যায় না —
// এই পাতাটাই একমাত্র পথ, আর admin_require_login() + 'tasks' view অনুমতি যাচাই করে তবেই পাঠায়।
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/tasks.php';
admin_require_login(); // কেন্দ্রীয় গার্ড: 'tasks' সেকশনের view অনুমতি লাগে (permissions.php ম্যাপ)

function task_img_deny(int $code): void
{
    http_response_code($code);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'পাওয়া যায়নি।';
    exit;
}

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0 || !task_table_ready()) {
    task_img_deny(404);
}

$db = get_db();
try {
    $stmt = $db->prepare('SELECT image_path FROM registration_tasks WHERE id = :id');
    $stmt->execute(['id' => $id]);
    $row = $stmt->fetch();
} catch (Throwable $e) {
    $row = null;
}
if (!$row || (string) $row['image_path'] === '') {
    task_img_deny(404);
}

$abs = task_image_abs_path((string) $row['image_path']);
if ($abs === '') {
    task_img_deny(404);
}

$ext  = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
$types = ['webp' => 'image/webp', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif'];
$mime = $types[$ext] ?? 'application/octet-stream';

header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($abs));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($abs);
exit;
