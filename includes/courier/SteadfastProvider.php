<?php
// Steadfast Courier API ইন্টিগ্রেশন (ডকুমেন্টেশন: https://steadfast.com.bd/ merchant panel)
// এটা একটা example/default ইমপ্লিমেন্টেশন — অন্য কুরিয়ার (Pathao/RedX) ব্যবহার করতে চাইলে
// CourierProviderInterface implement করে একই প্যাটার্নে নতুন ক্লাস বানালেই যথেষ্ট।

require_once __DIR__ . '/CourierProviderInterface.php';

class SteadfastProvider implements CourierProviderInterface
{
    private string $apiKey;
    private string $apiSecret;
    private string $baseUrl;

    public function __construct(string $apiKey, string $apiSecret, string $baseUrl = '')
    {
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
        $this->baseUrl = $baseUrl !== '' ? rtrim($baseUrl, '/') : 'https://portal.packzy.com/api/v1';
    }

    public function createShipment(array $order): array
    {
        if ($this->apiKey === '' || $this->apiSecret === '') {
            return [
                'success' => false,
                'consignment_id' => null,
                'tracking_url' => null,
                'delivery_fee' => null,
                'message' => 'Steadfast API Key/Secret সেট করা নেই। এডমিন সেটিংসে গিয়ে বসান।',
                'raw' => '',
            ];
        }

        // কোর্স রেজিস্ট্রেশনে রিসিভার তথ্য আলাদা (child এর নাম/মায়ের নম্বর পার্সেল রিসিভারের সাথে এক নাও হতে পারে)
        // অ্যাডমিন courier.php ডিটেইল পেজ থেকে _ প্রিফিক্স দিয়ে এই মানগুলো ওভাররাইড করে পাঠাতে পারে
        $recipientName = $order['_recipient_name'] ?? ($order['receiver_name'] ?: $order['customer_name']);
        $recipientPhone = $order['_recipient_phone'] ?? ($order['receiver_phone'] ?: $order['phone']);
        $addressParts = array_filter([$order['address'] ?? '', $order['thana'] ?? '', $order['district'] ?? '']);
        $address = $order['_recipient_address'] ?? implode(', ', $addressParts);

        $payload = [
            'invoice' => 'REG-' . $order['id'],
            'recipient_name' => $recipientName,
            'recipient_phone' => $recipientPhone,
            'recipient_address' => $address,
            'cod_amount' => (float) ($order['_amount_to_collect'] ?? 0),
            'note' => (string) ($order['_special_instruction'] ?? ($order['notes'] ?? '')),
        ];

        $ch = curl_init($this->baseUrl . '/create_order');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
            CURLOPT_HTTPHEADER => [
                'Api-Key: ' . $this->apiKey,
                'Secret-Key: ' . $this->apiSecret,
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
                'success' => false,
                'consignment_id' => null,
                'tracking_url' => null,
                'delivery_fee' => null,
                'message' => 'কুরিয়ার API তে সংযোগ ব্যর্থ হয়েছে: ' . $curlError,
                'raw' => '',
            ];
        }

        $data = json_decode($response, true);

        if ($httpCode !== 200 || !is_array($data) || empty($data['consignment'])) {
            $errMsg = is_array($data) && !empty($data['message']) ? $data['message'] : 'অজানা এরর (HTTP ' . $httpCode . ')';
            return [
                'success' => false,
                'consignment_id' => null,
                'tracking_url' => null,
                'delivery_fee' => null,
                'message' => 'কুরিয়ার অর্ডার তৈরি ব্যর্থ হয়েছে: ' . $errMsg,
                'raw' => $response,
            ];
        }

        $consignment = $data['consignment'];
        return [
            'success' => true,
            'consignment_id' => (string) ($consignment['consignment_id'] ?? ''),
            'tracking_url' => !empty($consignment['tracking_code'])
                ? 'https://steadfast.com.bd/t/' . $consignment['tracking_code']
                : null,
            'delivery_fee' => null, // Steadfast অর্ডার তৈরির রেসপন্সে ডেলিভারি ফি রিটার্ন করে না
            'message' => $data['message'] ?? 'সফলভাবে কুরিয়ারে পাঠানো হয়েছে।',
            'raw' => $response,
        ];
    }
}
