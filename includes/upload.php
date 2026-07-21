<?php
// ছবি আপলোড হ্যান্ডলিং — CRUD ফর্ম থেকে ব্যবহার হয়

// $fieldName এ কোনো ফাইল আপলোড করা হয়েছে কিনা যাচাই করে uploads/$subDir/ এ সেভ করে
// রিটার্ন করে: uploads/... পাথ (স্ট্রিং) অথবা null (কোনো ফাইল আপলোড না হলে)
function handle_image_upload(string $fieldName, string $subDir): ?string
{
    if (empty($_FILES[$fieldName]) || $_FILES[$fieldName]['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }

    $file = $_FILES[$fieldName];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('ছবি আপলোড ব্যর্থ হয়েছে (এরর কোড: ' . $file['error'] . ')');
    }

    $maxSize = 3 * 1024 * 1024; // 3MB
    if ($file['size'] > $maxSize) {
        throw new RuntimeException('ছবির সাইজ ৩ মেগাবাইটের বেশি হতে পারবে না।');
    }

    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    ];

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);

    if (!isset($allowed[$mime])) {
        throw new RuntimeException('শুধু JPG, PNG, WEBP অথবা GIF ছবি আপলোড করা যাবে।');
    }

    $uploadRoot = __DIR__ . '/../uploads/' . $subDir;

    // ⚠️ ফোল্ডার তৈরি/লেখার সমস্যাটা এখানেই ধরা হয়, নিচে গিয়ে "ছবি সেভ করতে সমস্যা" নামের অস্পষ্ট
    // এররে নয় — নাহলে আসল কারণ (পারমিশন/ফোল্ডার নেই) চাপা পড়ে যায় এবং ডিবাগ করা যায় না।
    // (২০২৬-০৭-২০ এ লাইভে এই বাগ ধরা পড়েছিল: mkdir ব্যর্থ হলেও রিটার্ন ভ্যালু চেক করা হচ্ছিল না।)
    if (!is_dir($uploadRoot)) {
        if (!@mkdir($uploadRoot, 0755, true) && !is_dir($uploadRoot)) {
            throw new RuntimeException(
                'আপলোড ফোল্ডার তৈরি করা যাচ্ছে না: uploads/' . $subDir . ' — '
                . 'cPanel → File Manager → public_html → uploads ফোল্ডারের Permission 755 করে দিন '
                . '(ডান-ক্লিক → Change Permissions), তারপর আবার চেষ্টা করুন।'
            );
        }
        @chmod($uploadRoot, 0755); // umask-এর কারণে mkdir এর mode কমে যেতে পারে — নিশ্চিত করে নেওয়া
    }
    // ⚠️ is_writable() কিছু হোস্টে ভুল বলে (২০২৬-০৭-২০ এ লাইভে ধরা পড়েছে: is_writable() true
    // বলছিল অথচ আসল লেখা ব্যর্থ হচ্ছিল)। তাই সত্যিকারের এক-বাইট রাইট-টেস্ট করা হয়, আর ব্যর্থ হলে
    // নিজেই chmod 0755 দিয়ে সারানোর চেষ্টা করা হয় — তাতেও না হলে তবেই ইউজারকে বলা হয়।
    if (!upload_dir_is_writable($uploadRoot)) {
        @chmod($uploadRoot, 0755);
        if (!upload_dir_is_writable($uploadRoot)) {
            throw new RuntimeException(
                'আপলোড ফোল্ডারে লেখার অনুমতি নেই: uploads/' . $subDir . ' — '
                . 'cPanel → File Manager → public_html/uploads ফোল্ডারে ডান-ক্লিক → Change Permissions → '
                . '755 দিন এবং "Recurse into subdirectories" টিক দিয়ে Apply করুন, তারপর আবার চেষ্টা করুন।'
            );
        }
    }

    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('ছবি আপলোড যাচাই ব্যর্থ হয়েছে।');
    }

    $srcExt = $allowed[$mime];
    // WebP আউটপুট — ছবি সাধারণত ~৩০% ছোট/দ্রুত (সব আধুনিক ব্রাউজারে সাপোর্টেড)। GD-তে imagewebp
    // থাকলে সব আপলোড WebP-তে সেভ হয়; নাহলে (বা রিসাইজ ব্যর্থ) আসল ফরম্যাটে।
    $outExt = (extension_loaded('gd') && function_exists('imagewebp')) ? 'webp' : $srcExt;
    $filename = bin2hex(random_bytes(8)) . '.' . $outExt;
    $destPath = $uploadRoot . '/' . $filename;

    // অটো-রিসাইজ: ছবিকে স্ট্যান্ডার্ড ৪:৩ ক্যানভাসে (১০০০×৭৫০) fit+pad করে বসানো হয় — পুরো ছবি দেখা
    // যায় (দরকারে সাদা প্যাডিং), কিছু কাটে না, সব কার্ড একরকম। GD না থাকলে/ব্যর্থ হলে আসল ছবিই সেভ হয়।
    if (!resize_image_to_canvas($file['tmp_name'], $destPath, $srcExt, $outExt)) {
        // fallback: আসল ছবি আসল ফরম্যাটে (WebP না)
        $filename = bin2hex(random_bytes(8)) . '.' . $srcExt;
        $destPath = $uploadRoot . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destPath)) {
            throw new RuntimeException(upload_failure_reason($file, $uploadRoot, $subDir));
        }
    }

    return 'uploads/' . $subDir . '/' . $filename;
}

/**
 * ফোল্ডারে সত্যিই ফাইল লেখা যায় কিনা — আসল একটা ফাইল লিখে-মুছে দেখা হয়।
 * `is_writable()` কিছু শেয়ার্ড হোস্টে (ACL/suEXEC/অদ্ভুত পারমিশন বিটে) ভুল উত্তর দেয়, তাই এটাই ভরসা।
 */
function upload_dir_is_writable(string $dir): bool
{
    $probe = $dir . '/.wtest-' . bin2hex(random_bytes(4));
    if (@file_put_contents($probe, 'x') === false) {
        return false;
    }
    @unlink($probe);
    return true;
}

/**
 * আপলোড ব্যর্থ হলে **আসল কারণটা** বের করে মানুষের বোঝার মতো বার্তা বানায়।
 *
 * ২০২৬-০৭-২০: লাইভে "পিসি থেকে ছবি যায়, মোবাইল থেকে যায় না" সমস্যা ধরতে গিয়ে বোঝা গেল শুধু
 * "সার্ভারে ফাইল লিখতে ব্যর্থ" বললে ডিবাগ করা অসম্ভব — তাই এখানে একটা আসল রাইট-টেস্ট চালিয়ে ও
 * PHP-র সীমাগুলো দেখে নির্দিষ্ট কারণ বলা হয়। অ্যাডমিন-only পেজ বলে টেকনিক্যাল তথ্য দেখানো নিরাপদ।
 */
function upload_failure_reason(array $file, string $uploadRoot, string $subDir): string
{
    $tmp = $file['tmp_name'] ?? '';

    // ১) tmp ফাইলটা কি আদৌ আছে? (PHP-র upload_tmp_dir ভুল হলে/সেশনের মাঝে মুছে গেলে)
    if ($tmp === '' || !is_file($tmp)) {
        return 'ছবি সেভ করা যায়নি — সার্ভারে সাময়িক ফাইলটাই পাওয়া যাচ্ছে না। '
             . 'সাধারণত ছবিটা খুব বড় হলে (মোবাইলের ক্যামেরার ছবি প্রায়ই ৫-১০ MB) আপলোড অসম্পূর্ণ থেকে যায়। '
             . 'ছবিটা ছোট করে (বা স্ক্রিনশট নিয়ে) আবার চেষ্টা করুন।';
    }

    $sizeBytes = (int) ($file['size'] ?? 0);

    // ২) ছোট (১ বাইট) লেখা যায় কিনা — পারমিশনের সমস্যা ধরতে
    if (!upload_dir_is_writable($uploadRoot)) {
        return 'আপলোড ফোল্ডারে (uploads/' . $subDir . ') ফাইল লেখা যাচ্ছে না। '
             . 'cPanel → File Manager → public_html/uploads ফোল্ডারে ডান-ক্লিক → Change Permissions → '
             . '755 দিন, "Recurse into subdirectories" টিক দিয়ে Apply করুন।';
    }

    // ৩) এবার **ছবিটার সমান সাইজের** একটা টেস্ট — এটাই ডিস্ক কোটা ধরার আসল পরীক্ষা।
    //    ⚠️ cPanel-এর অ্যাকাউন্ট কোটা শেষ হলে ছোট ফাইল লেখা যায় কিন্তু বড় ফাইল যায় না — আর
    //    disk_free_space() পুরো সার্ভারের খালি জায়গা দেখায় (অ্যাকাউন্টের কোটা না), তাই সেটা
    //    দিয়ে এই অবস্থাটা ধরাই যায় না। এভাবেই "পিসির ছোট ছবি যায়, মোবাইলের বড় ছবি যায় না"।
    if ($sizeBytes > 0) {
        $bigProbe = $uploadRoot . '/.size-test-' . bin2hex(random_bytes(4));
        $fh = @fopen($bigProbe, 'wb');
        $wrote = false;
        if ($fh) {
            $chunk = str_repeat('0', 65536);
            $written = 0;
            $wrote = true;
            while ($written < $sizeBytes) {
                $n = @fwrite($fh, substr($chunk, 0, min(65536, $sizeBytes - $written)));
                if ($n === false || $n === 0) { $wrote = false; break; }
                $written += $n;
            }
            @fclose($fh);
        }
        @unlink($bigProbe);
        if (!$wrote) {
            return 'হোস্টিংয়ে জায়গা শেষ — ' . round($sizeBytes / 1048576, 1) . ' MB এর ছবিটা রাখার জায়গা নেই '
                 . '(ছোট ফাইল লেখা যাচ্ছে, বড়টা যাচ্ছে না — তাই পিসির ছোট ছবি যায়, মোবাইলের বড় ছবি যায় না)। '
                 . 'cPanel-এর হোম পেজে বাঁ পাশে "Disk Usage" দেখুন — কোটা প্রায় পূর্ণ হলে পুরনো ফাইল/ব্যাকআপ/ইমেইল মুছে জায়গা খালি করুন, '
                 . 'অথবা হোস্টিং সাপোর্টকে কোটা বাড়াতে বলুন। ততক্ষণ ছবিটা ছোট করে (রিসাইজ/স্ক্রিনশট) দিলে কাজ চলবে।';
        }
    }

    // ৩) ফোল্ডারে লেখা যায় অথচ এই ছবিটা যাচ্ছে না → ছবির মাপ/মেমরির সমস্যা
    $sizeMb = round(((int) ($file['size'] ?? 0)) / 1048576, 1);
    $dim = @getimagesize($tmp);
    $dimTxt = ($dim && isset($dim[0], $dim[1])) ? $dim[0] . '×' . $dim[1] . ' পিক্সেল' : 'মাপ পড়া যায়নি';
    return 'এই ছবিটা সার্ভারে সেভ করা যাচ্ছে না (' . $dimTxt . ', ' . $sizeMb . ' MB) — '
         . 'ফোল্ডারে লেখার অনুমতি ঠিকই আছে, তাই সমস্যাটা ছবিটার মাপ নিয়ে। '
         . 'মোবাইলের ক্যামেরার ছবি সাধারণত অনেক বড় হয় — ছবিটা ক্রপ/রিসাইজ করে (বা স্ক্রিনশট নিয়ে) আবার দিন।';
}

// ছবিকে target ক্যানভাসে (ডিফল্ট ৪:৩, ১০০০×৭৫০) fit+pad করে সেভ করে — পুরো ছবি অক্ষত থাকে, অনুপাত
// না মিললে দু'পাশে/উপর-নিচে সাদা প্যাডিং বসে। সফল হলে true; GD না থাকলে/ফরম্যাট না পড়লে false
// (তখন caller move_uploaded_file দিয়ে আসল ছবি সেভ করে — কখনো আপলোড ব্যর্থ হয় না)।
function resize_image_to_canvas(string $srcPath, string $destPath, string $ext, string $outExt = '', int $targetW = 1000, int $targetH = 750): bool
{
    if (!extension_loaded('gd')) {
        return false;
    }
    if ($outExt === '') {
        $outExt = $ext; // আউটপুট ফরম্যাট না দিলে সোর্সের মতোই
    }

    // ⚠️ মেমরি গার্ড (২০২৬-০৭-২০): মোবাইলের ক্যামেরার ছবি প্রায়ই ৪০০০×৩০০০+ — GD-তে খুলতে
    // width×height×4 বাইট (এখানে ~৪৮ MB) লাগে। PHP-র memory_limit ছোট হলে imagecreatefrom*()
    // **fatal error** দিয়ে পুরো রিকোয়েস্ট মেরে ফেলে (সাদা পেজ) — সেটা catch করা যায় না। তাই আগেই
    // হিসাব করে দেখি জায়গা হবে কিনা; না হলে false রিটার্ন করে caller-এর move_uploaded_file()
    // fallback-এ চলে যাই (ছবি রিসাইজ হয় না, কিন্তু আপলোড সফল হয় — আগে ক্র্যাশ করত)।
    $limit = trim((string) ini_get('memory_limit'));
    if ($limit !== '' && $limit !== '-1') {
        $unit = strtolower(substr($limit, -1));
        $limitBytes = (int) $limit;
        if ($unit === 'g') { $limitBytes *= 1024 * 1024 * 1024; }
        elseif ($unit === 'm') { $limitBytes *= 1024 * 1024; }
        elseif ($unit === 'k') { $limitBytes *= 1024; }

        $info = @getimagesize($srcPath);
        $srcBytes = ($info && isset($info[0], $info[1])) ? $info[0] * $info[1] * 4 : 0;
        // ⚠️ ক্যানভাসটাও ধরতে হবে — সেটা সবসময় ১০০০×৭৫০ (≈৩ MB), সোর্স ছোট হলেও।
        // (প্রথম সংস্করণে শুধু সোর্স ধরা হয়েছিল, ফলে ছোট ছবিতেও 8M লিমিটে fatal হচ্ছিল।)
        $needed = (int) (($srcBytes + $targetW * $targetH * 4) * 1.6); // + GD ওভারহেড
        if ($needed > max(0, $limitBytes - memory_get_usage(true))) {
            return false; // মেমরিতে কুলাবে না — নিরাপদে আসল ছবিই সেভ হোক (ক্র্যাশের বদলে)
        }
    }
    switch ($ext) {
        case 'jpg':  $src = @imagecreatefromjpeg($srcPath); break;
        case 'png':  $src = @imagecreatefrompng($srcPath); break;
        case 'gif':  $src = @imagecreatefromgif($srcPath); break;
        case 'webp': $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($srcPath) : false; break;
        default:     return false;
    }
    if (!$src) {
        return false;
    }
    $sw = imagesx($src);
    $sh = imagesy($src);
    if ($sw < 1 || $sh < 1) {
        imagedestroy($src);
        return false;
    }

    $scale = min($targetW / $sw, $targetH / $sh); // ভেতরে fit — পুরো ছবি ধরে রাখে
    $nw = max(1, (int) round($sw * $scale));
    $nh = max(1, (int) round($sh * $scale));
    $dx = (int) (($targetW - $nw) / 2);
    $dy = (int) (($targetH - $nh) / 2);

    $canvas = imagecreatetruecolor($targetW, $targetH);
    $white = imagecolorallocate($canvas, 255, 255, 255);
    imagefilledrectangle($canvas, 0, 0, $targetW, $targetH, $white);
    imagecopyresampled($canvas, $src, $dx, $dy, 0, 0, $nw, $nh, $sw, $sh);

    switch ($outExt) {
        case 'webp': $ok = function_exists('imagewebp') ? imagewebp($canvas, $destPath, 82) : imagejpeg($canvas, $destPath, 85); break;
        case 'jpg':  $ok = imagejpeg($canvas, $destPath, 85); break;
        case 'png':  $ok = imagepng($canvas, $destPath, 6); break;
        case 'gif':  $ok = imagegif($canvas, $destPath); break;
        default:     $ok = false;
    }
    imagedestroy($src);
    imagedestroy($canvas);
    return (bool) $ok;
}

// পুরনো ছবি ডিলিট করে, শুধু আমাদের uploads/ এর ভেতরের ফাইল হলে (বাইরের URL হলে কিছু করবে না)
function delete_uploaded_image(?string $relativePath): void
{
    if (!$relativePath || strpos($relativePath, 'uploads/') !== 0) {
        return;
    }
    $fullPath = __DIR__ . '/../' . $relativePath;
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}
