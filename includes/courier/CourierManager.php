<?php
// এডমিন সেটিংসে যে প্রোভাইডার সেট করা আছে (settings.php -> কুরিয়ার API সেকশন)
// তার উপর ভিত্তি করে সঠিক কুরিয়ার ক্লাসের instance রিটার্ন করে

require_once __DIR__ . '/SteadfastProvider.php';
require_once __DIR__ . '/PathaoProvider.php';

// নতুন কুরিয়ার প্রোভাইডার যোগ করতে চাইলে:
// 1) CourierProviderInterface implement করে একটা নতুন ক্লাস বানান (যেমন RedXProvider.php)
// 2) নিচের switch এ একটা case যোগ করুন
function get_active_courier_provider(): ?CourierProviderInterface
{
    $provider = get_setting('courier_active_provider');

    switch (strtolower(trim($provider))) {
        case 'steadfast':
            return new SteadfastProvider(
                get_setting('courier_api_key'),
                get_setting('courier_api_secret'),
                get_setting('courier_base_url')
            );
        case 'pathao':
            return new PathaoProvider(
                get_setting('pathao_client_id'),
                get_setting('pathao_client_secret'),
                get_setting('pathao_username'),
                get_setting('pathao_password'),
                get_setting('pathao_store_id'),
                get_setting('pathao_base_url')
            );
        default:
            return null;
    }
}

// courier.php ডিটেইল ফর্মের POST ডেটা থেকে একটা "কুরিয়ার ব্যাচ" (একটা registration এর একটা নির্দিষ্ট
// মাস/কিস্তির চালান) resolve করে courier_batches এ সেভ/আপডেট করে, ও resolved মানগুলো (batch id সহ)
// রিটার্ন করে (send-to-courier.php তে ব্যবহারের জন্য) — "শুধু সেভ" ও "সেভ করে পাঠান" দুই জায়গা থেকেই
// ব্যবহার হয় (DRY)। $batchId দিলে সেই নির্দিষ্ট ব্যাচ আপডেট হয়, না দিলে নতুন ব্যাচ তৈরি হয়
// (একই registration এর জন্য একাধিক মাসের ব্যাচ রাখার এটাই মূল মেকানিজম)।
function save_courier_batch(PDO $db, array $order, array $post, ?int $batchId = null): array
{
    $itemDetails = get_item_details($order['type'], $order['item_id']);
    $defaultAmount = $itemDetails ? parse_price_to_number($itemDetails['price']) * max(1, (int) $order['quantity']) : 0;

    $existing = null;
    if ($batchId) {
        $existingStmt = $db->prepare('SELECT * FROM courier_batches WHERE id = :id AND registration_id = :reg_id');
        $existingStmt->execute(['id' => $batchId, 'reg_id' => $order['id']]);
        $existing = $existingStmt->fetch() ?: null;
    }

    $resolve = function (string $key, string $existingKey, $default) use ($post, $existing) {
        if (trim((string) ($post[$key] ?? '')) !== '') {
            return trim((string) $post[$key]);
        }
        if ($existing && $existing[$existingKey] !== null && $existing[$existingKey] !== '') {
            return $existing[$existingKey];
        }
        return $default;
    };

    $resolved = [
        'period_label' => $resolve('period_label', 'period_label', ''),
        'recipient_name' => $resolve('recipient_name', 'recipient_name', $order['receiver_name'] ?: $order['customer_name']),
        'recipient_phone' => $resolve('recipient_phone', 'recipient_phone', $order['receiver_phone'] ?: $order['phone']),
        'recipient_secondary_phone' => $resolve('recipient_secondary_phone', 'recipient_secondary_phone', ''),
        'recipient_address' => $resolve('recipient_address', 'recipient_address', implode(', ', array_filter([$order['address'] ?? '', $order['thana'] ?? '', $order['district'] ?? '']))),
        'item_description' => $resolve('item_description', 'item_description', $itemDetails['title'] ?? $order['item_title']),
        'item_quantity' => (int) $resolve('item_quantity', 'item_quantity', max(1, (int) $order['quantity'])),
        'item_weight' => (float) $resolve('item_weight', 'item_weight', 0.5),
        'item_type' => (int) $resolve('item_type', 'item_type', 2),
        'delivery_type' => (int) $resolve('delivery_type', 'delivery_type', 48),
        'special_instruction' => $resolve('special_instruction', 'special_instruction', $order['notes'] ?? ''),
        'amount_to_collect' => (float) $resolve('amount_to_collect', 'amount_to_collect', $defaultAmount),
    ];

    // PDO নন-emulated prepare এ query তে রেফারেন্স না করা extra bound param থাকলে "Invalid parameter number"
    // এরর দেয় — তাই UPDATE/INSERT দুটোর জন্য আলাদা param অ্যারে বানানো হলো (একটাতে reg_id নেই, অন্যটাতে id নেই)
    $fieldParams = [
        'pl' => $resolved['period_label'],
        'rn' => $resolved['recipient_name'], 'rp' => $resolved['recipient_phone'],
        'rsp' => $resolved['recipient_secondary_phone'] ?: null, 'ra' => $resolved['recipient_address'],
        'idesc' => $resolved['item_description'], 'iq' => $resolved['item_quantity'], 'iw' => $resolved['item_weight'],
        'it' => $resolved['item_type'], 'dt' => $resolved['delivery_type'],
        'si' => $resolved['special_instruction'] ?: null, 'atc' => $resolved['amount_to_collect'],
    ];

    if ($existing) {
        $db->prepare(
            'UPDATE courier_batches SET
                period_label = :pl, recipient_name = :rn, recipient_phone = :rp,
                recipient_secondary_phone = :rsp, recipient_address = :ra,
                item_description = :idesc, item_quantity = :iq, item_weight = :iw,
                item_type = :it, delivery_type = :dt, special_instruction = :si, amount_to_collect = :atc
             WHERE id = :id'
        )->execute($fieldParams + ['id' => $existing['id']]);
        $resolved['id'] = (int) $existing['id'];
    } else {
        $db->prepare(
            'INSERT INTO courier_batches
                (registration_id, period_label, recipient_name, recipient_phone, recipient_secondary_phone, recipient_address,
                 item_description, item_quantity, item_weight, item_type, delivery_type, special_instruction, amount_to_collect)
             VALUES
                (:reg_id, :pl, :rn, :rp, :rsp, :ra, :idesc, :iq, :iw, :it, :dt, :si, :atc)'
        )->execute($fieldParams + ['reg_id' => $order['id']]);
        $resolved['id'] = (int) $db->lastInsertId();
    }

    return $resolved;
}

// একটা ব্যাচ resolve/সেভ করে (save_courier_batch), active provider দিয়ে shipment তৈরি করে,
// courier_shipments/courier_batches/registrations আপডেট করে — একক পাঠানো (send-to-courier.php) ও
// বাল্ক পাঠানো (bulk-courier-action.php) দুই জায়গা থেকেই ব্যবহার হয় (DRY, DB-write লজিক একবারই লেখা)
function send_courier_batch(PDO $db, CourierProviderInterface $provider, array $order, array $post, ?int $batchId = null): array
{
    $resolved = save_courier_batch($db, $order, $post, $batchId);
    $batchId = $resolved['id'];

    $order['_recipient_name'] = $resolved['recipient_name'];
    $order['_recipient_phone'] = $resolved['recipient_phone'];
    $order['_recipient_secondary_phone'] = $resolved['recipient_secondary_phone'];
    $order['_recipient_address'] = $resolved['recipient_address'];
    $order['_item_description'] = $resolved['item_description'];
    $order['_item_quantity'] = $resolved['item_quantity'];
    $order['_item_weight'] = $resolved['item_weight'];
    $order['_item_type'] = $resolved['item_type'];
    $order['_delivery_type'] = $resolved['delivery_type'];
    $order['_special_instruction'] = $resolved['special_instruction'];
    $order['_amount_to_collect'] = $resolved['amount_to_collect'];

    $result = $provider->createShipment($order);
    $providerName = get_setting('courier_active_provider');

    $db->prepare(
        'INSERT INTO courier_shipments (registration_id, batch_id, provider, consignment_id, tracking_url, delivery_fee, status, raw_response)
         VALUES (:reg_id, :batch_id, :provider, :cid, :turl, :fee, :status, :raw)'
    )->execute([
        'reg_id' => $order['id'], 'batch_id' => $batchId, 'provider' => $providerName,
        'cid' => $result['consignment_id'], 'turl' => $result['tracking_url'],
        'fee' => $result['delivery_fee'] ?? null, 'status' => $result['success'] ? 'created' : 'failed',
        'raw' => $result['raw'],
    ]);

    $db->prepare(
        'UPDATE courier_batches SET courier_provider = :provider, courier_consignment_id = :cid, tracking_url = :turl,
            delivery_fee = :fee, send_status = :status, sent_at = CURRENT_TIMESTAMP WHERE id = :id'
    )->execute([
        'provider' => $providerName, 'cid' => $result['consignment_id'], 'turl' => $result['tracking_url'],
        'fee' => $result['delivery_fee'] ?? null, 'status' => $result['success'] ? 'sent' : 'failed', 'id' => $batchId,
    ]);

    if ($result['success']) {
        // registrations টেবিলের courier_consignment_id সবসময় সর্বশেষ সফল ব্যাচটাই দেখায় (দ্রুত-দেখার জন্য) —
        // পুরো ইতিহাস courier_batches/courier_shipments এ থাকে। registrations.status ইচ্ছাকৃতভাবে এখানে
        // বদলানো হয় না — একটা registration থেকে একাধিক মাসের ব্যাচ পাঠানো যায় বলে প্রথম সফল পাঠানোর পরই
        // status="shipped" করে দিলে সেটা courier.php এর "confirmed" ফিল্টার থেকে বাদ পড়ে যেত, পরের মাসের
        // ব্যাচ আর তৈরি/পাঠানো যেত না। স্ট্যাটাস এখন সম্পূর্ণ admin-নিয়ন্ত্রিত (registrations.php থেকে)।
        $db->prepare(
            'UPDATE registrations SET courier_provider = :provider, courier_consignment_id = :cid WHERE id = :id'
        )->execute(['provider' => $providerName, 'cid' => $result['consignment_id'], 'id' => $order['id']]);
    }

    $result['batch_id'] = $batchId;
    return $result;
}
