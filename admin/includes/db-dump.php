<?php
// ডাটাবেস ডাম্প হেল্পার — `admin/backup.php` (ব্রাউজারে স্ট্রিম) ও `cron/db-backup.php`
// (ফাইলে সেভ) দুই জায়গায় ব্যবহার হয়, তাই এখানে শেয়ার্ড রাখা (লজিক ডুপ্লিকেট না করে)।
//
// শেয়ার্ড হোস্টিংয়ে `mysqldump` কমান্ড থাকে না — তাই বিশুদ্ধ PHP-তে PDO দিয়ে টেবিল ঘুরে
// SHOW CREATE TABLE + INSERT বানানো হয়। আউটপুট একটা callback-এ ধাপে ধাপে যায়, তাই বড়
// টেবিলেও পুরো ফাইল মেমরিতে জমে না (স্ট্রিম বা ফাইলে সরাসরি লেখা — caller ঠিক করে)।

/**
 * ডাটাবেস ডাম্প তৈরি করে, প্রতি খণ্ড `$write($text)` callback-এ পাঠায়।
 *
 * @param PDO      $db
 * @param string   $mode  'full' | 'structure' | 'data'
 * @param callable $write function(string $chunk): void — খণ্ডটা কোথায় যাবে (echo / fwrite)
 */
function db_dump_stream(PDO $db, string $mode, callable $write): void
{
    $write("-- EduCenter ডাটাবেস ব্যাকআপ\n");
    $write('-- তৈরি: ' . date('Y-m-d H:i:s') . "\n");
    $write('-- মোড: ' . $mode . "\n");
    $write("-- ফেরানোর নিয়ম: phpMyAdmin → আপনার DB সিলেক্ট → Import → এই ফাইল → Go\n");
    $write("-- ⚠️ এই ফাইলে কাস্টমারের নাম/ফোন/ঠিকানা আছে — নিরাপদ জায়গায় রাখুন।\n\n");
    $write("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");

    $tables = $db->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        if ($mode !== 'data') {
            $create = $db->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM)[1];
            $write("-- ------------------------------------------------------------\n");
            $write("-- টেবিল: $table\n");
            $write("-- ------------------------------------------------------------\n");
            $write("DROP TABLE IF EXISTS `$table`;\n" . $create . ";\n\n");
        }
        if ($mode === 'structure') {
            continue;
        }

        $stmt = $db->query("SELECT * FROM `$table`");
        $rowCount = 0;
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if ($rowCount === 0) {
                $write('-- ডেটা: ' . $table . "\n");
            }
            $vals = [];
            foreach ($row as $v) {
                $vals[] = $v === null ? 'NULL' : $db->quote((string) $v);
            }
            $cols = '`' . implode('`, `', array_keys($row)) . '`';
            $write("INSERT INTO `$table` ($cols) VALUES (" . implode(', ', $vals) . ");\n");
            $rowCount++;
        }
        if ($rowCount > 0) {
            $write("\n");
        }
    }
    $write("SET FOREIGN_KEY_CHECKS = 1;\n");
}

/**
 * অটো-ব্যাকআপ ফোল্ডারের পাথ (public_html/storage/backups)। বাইরের কেউ যাতে ডাউনলোড
 * করতে না পারে সেজন্য ফোল্ডারে একটা deny-all .htaccess বসানো হয় (কাস্টমার ডেটা আছে)।
 * অ্যাডমিন প্যানেল থেকে PHP নিজে ফাইল পড়ে ডাউনলোড করায় — সরাসরি URL কাজ করে না।
 */
function db_backup_dir(): string
{
    $dir = realpath(__DIR__ . '/../..') . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
        @chmod($dir, 0755);
    }
    // ⚠️ সরাসরি HTTP অ্যাক্সেস বন্ধ — এই ফোল্ডারের ফাইল ব্রাউজারে খোলা যাবে না
    $ht = $dir . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht, "Require all denied\nDeny from all\n");
    }
    return $dir;
}

/**
 * ডাটাবেস ব্যাকআপ ফাইলে সেভ করে, পুরনো ব্যাকআপ ঘুরিয়ে (rotation) রাখে।
 * @return array{ok:bool, file:string, size:int, error:string}
 */
function db_backup_to_file(PDO $db, int $keep = 7): array
{
    $dir  = db_backup_dir();
    $name = 'educenter-db-' . date('Ymd-His') . '.sql';
    $path = $dir . DIRECTORY_SEPARATOR . $name;

    $fh = @fopen($path, 'wb');
    if (!$fh) {
        return ['ok' => false, 'file' => $name, 'size' => 0,
                'error' => 'ব্যাকআপ ফোল্ডারে লেখা যাচ্ছে না: storage/backups (Permission 755 দরকার)'];
    }
    try {
        db_dump_stream($db, 'full', function (string $chunk) use ($fh) {
            fwrite($fh, $chunk);
        });
    } catch (Throwable $e) {
        fclose($fh);
        @unlink($path);
        return ['ok' => false, 'file' => $name, 'size' => 0, 'error' => $e->getMessage()];
    }
    fclose($fh);

    // ── rotation: সর্বশেষ $keep টা রেখে বাকি (পুরনো) মুছে ফেলা ──
    $files = glob($dir . DIRECTORY_SEPARATOR . 'educenter-db-*.sql') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a)); // নতুন আগে
    foreach (array_slice($files, $keep) as $old) {
        @unlink($old);
    }

    return ['ok' => true, 'file' => $name, 'size' => (int) filesize($path), 'error' => ''];
}
