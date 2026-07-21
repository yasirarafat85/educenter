<?php
// Pathao Courier Merchant API ইন্টিগ্রেশন (ডকুমেন্টেশন: Developer API — Merchant Panel — Pathao)
// Steadfast এর মতো সাধারণ API Key/Secret না — OAuth 2.0 (password grant) দিয়ে access_token/refresh_token
// নিতে হয়, যেগুলো settings টেবিলে (get_setting/update_setting) সেভ থাকে ও দরকার হলে অটো-রিফ্রেশ হয়।

require_once __DIR__ . '/CourierProviderInterface.php';

class PathaoProvider implements CourierProviderInterface
{
    private string $clientId;
    private string $clientSecret;
    private string $username;
    private string $password;
    private string $storeId;
    private string $baseUrl;

    public function __construct(string $clientId, string $clientSecret, string $username, string $password, string $storeId, string $baseUrl = '')
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->username = $username;
        $this->password = $password;
        $this->storeId = $storeId;
        $this->baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : 'https://api-hermes.pathao.com';
    }

    // বৈধ token ফেরত দেয় — সেভ করা token এখনো মেয়াদ থাকলে সেটাই, নাহলে refresh_token দিয়ে নতুন নেয়,
    // সেটাও ব্যর্থ হলে username/password দিয়ে একদম নতুন করে issue করে (ঠিক .gs এর getValidToken() এর প্যাটার্নে)
    private function getValidToken(): ?string
    {
        $token = get_setting('pathao_access_token');
        $expiry = get_setting('pathao_token_expiry');
        $refresh = get_setting('pathao_refresh_token');

        if ($token !== '' && $expiry !== '' && strtotime($expiry) > time() + 300) {
            return $token;
        }

        if ($refresh !== '') {
            $refreshed = $this->requestToken(['grant_type' => 'refresh_token', 'refresh_token' => $refresh]);
            if ($refreshed) {
                return $refreshed;
            }
        }

        return $this->requestToken([
            'grant_type' => 'password',
            'username' => $this->username,
            'password' => $this->password,
        ]);
    }

    private function requestToken(array $extra): ?string
    {
        $payload = array_merge([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ], $extra);

        $ch = curl_init($this->baseUrl . '/aladdin/api/v1/issue-token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 20,
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        if ($response === false) {
            return null;
        }
        $data = json_decode($response, true);
        if (!is_array($data) || empty($data['access_token'])) {
            return null;
        }

        update_setting('pathao_access_token', $data['access_token']);
        update_setting('pathao_refresh_token', $data['refresh_token'] ?? '');
        update_setting('pathao_token_expiry', date('Y-m-d H:i:s', time() + (int) ($data['expires_in'] ?? 0)));

        return $data['access_token'];
    }

    // বাংলাদেশি ফোন নম্বর 11 ডিজিটে ফরম্যাট করা (880 প্রিফিক্স থাকলে সরিয়ে 0 বসানো — ঠিক .gs এর formatPhone() এর মতো)
    private function formatPhone(string $phone): string
    {
        $p = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($p) === 13 && strpos($p, '880') === 0) {
            $p = '0' . substr($p, 3);
        }
        if (strlen($p) === 10 && strpos($p, '0') !== 0) {
            $p = '0' . $p;
        }
        return $p;
    }

    public function createShipment(array $order): array
    {
        if ($this->clientId === '' || $this->clientSecret === '' || $this->username === '' || $this->password === '') {
            return [
                'success' => false, 'consignment_id' => null, 'tracking_url' => null, 'delivery_fee' => null,
                'message' => 'Pathao Client ID/Secret/Username/Password সেট করা নেই। এডমিন সেটিংসে গিয়ে বসান।',
                'raw' => '',
            ];
        }
        if ($this->storeId === '') {
            return [
                'success' => false, 'consignment_id' => null, 'tracking_url' => null, 'delivery_fee' => null,
                'message' => 'Pathao Store ID সেট করা নেই। এডমিন সেটিংসে গিয়ে বসান।',
                'raw' => '',
            ];
        }

        $token = $this->getValidToken();
        if (!$token) {
            return [
                'success' => false, 'consignment_id' => null, 'tracking_url' => null, 'delivery_fee' => null,
                'message' => 'Pathao থেকে access token পাওয়া যায়নি। Client ID/Secret/Username/Password যাচাই করুন।',
                'raw' => '',
            ];
        }

        // কোর্স রেজিস্ট্রেশনে রিসিভার তথ্য আলাদা (child এর নাম/মায়ের নম্বর পার্সেল রিসিভারের সাথে এক নাও হতে পারে)
        // অ্যাডমিন courier.php ডিটেইল পেজ থেকে _ প্রিফিক্স দিয়ে এই মানগুলো ওভাররাইড করে পাঠাতে পারে (একবারের জন্য, রেজিস্ট্রেশনে সেভ হয় না)
        $recipientName = $order['_recipient_name'] ?? ($order['receiver_name'] ?: $order['customer_name']);
        $recipientPhoneRaw = $order['_recipient_phone'] ?? ($order['receiver_phone'] ?: $order['phone']);
        $recipientPhone = $this->formatPhone($recipientPhoneRaw);
        $addressParts = array_filter([$order['address'] ?? '', $order['thana'] ?? '', $order['district'] ?? '']);
        $address = $order['_recipient_address'] ?? implode(', ', $addressParts);

        if (strlen($recipientPhone) !== 11) {
            return [
                'success' => false, 'consignment_id' => null, 'tracking_url' => null, 'delivery_fee' => null,
                'message' => 'রিসিভারের ফোন নম্বর সঠিক না (১১ ডিজিট হতে হবে): ' . $recipientPhone,
                'raw' => '',
            ];
        }

        $payload = [
            'store_id' => (int) $this->storeId,
            'merchant_order_id' => 'REG-' . $order['id'],
            'recipient_name' => $recipientName,
            'recipient_phone' => $recipientPhone,
            'recipient_address' => $address,
            'delivery_type' => (int) ($order['_delivery_type'] ?? 48), // 48 = Normal Delivery, 12 = On Demand Delivery
            'item_type' => (int) ($order['_item_type'] ?? 2), // 1 = Document, 2 = Parcel
            'item_quantity' => max(1, (int) ($order['_item_quantity'] ?? $order['quantity'] ?? 1)),
            'item_weight' => (float) ($order['_item_weight'] ?? 0.5),
            'item_description' => (string) ($order['_item_description'] ?? ($order['item_title'] ?? '')),
            'amount_to_collect' => (int) round((float) ($order['_amount_to_collect'] ?? 0)),
            'special_instruction' => (string) ($order['_special_instruction'] ?? ($order['notes'] ?? '')),
        ];

        $secondaryPhone = trim((string) ($order['_recipient_secondary_phone'] ?? ''));
        if ($secondaryPhone !== '') {
            $payload['recipient_secondary_phone'] = $this->formatPhone($secondaryPhone);
        }

        $ch = curl_init($this->baseUrl . '/aladdin/api/v1/orders');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ],
            CURLOPT_TIMEOUT => 20,
        ]);

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false, 'consignment_id' => null, 'tracking_url' => null, 'delivery_fee' => null,
                'message' => 'Pathao API তে সংযোগ ব্যর্থ হয়েছে: ' . $curlError,
                'raw' => '',
            ];
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || !is_array($data) || empty($data['data']['consignment_id'])) {
            $errMsg = is_array($data) && !empty($data['message']) ? $data['message'] : 'অজানা এরর (HTTP ' . $httpCode . ')';
            return [
                'success' => false, 'consignment_id' => null, 'tracking_url' => null, 'delivery_fee' => null,
                'message' => 'Pathao অর্ডার তৈরি ব্যর্থ হয়েছে: ' . $errMsg,
                'raw' => $response,
            ];
        }

        $result = $data['data'];
        return [
            'success' => true,
            'consignment_id' => (string) $result['consignment_id'],
            'tracking_url' => null, // Pathao অর্ডার-ক্রিয়েট রেসপন্সে সরাসরি পাবলিক ট্র্যাকিং URL দেয় না
            'delivery_fee' => isset($result['delivery_fee']) ? (float) $result['delivery_fee'] : null,
            'message' => $data['message'] ?? 'সফলভাবে Pathao তে পাঠানো হয়েছে।',
            'raw' => $response,
        ];
    }
}
