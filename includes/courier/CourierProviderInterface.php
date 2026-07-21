<?php
// প্রতিটা কুরিয়ার প্রোভাইডার (Steadfast/Pathao/RedX) এই ইন্টারফেস implement করবে
// নতুন কুরিয়ার যোগ করতে চাইলে শুধু এই ইন্টারফেস মেনে একটা নতুন ক্লাস বানালেই হবে,
// বাকি সিস্টেমের (admin/send-to-courier.php) কোনো কোড পরিবর্তন লাগবে না

interface CourierProviderInterface
{
    /**
     * @param array $order registrations টেবিলের একটা row (customer_name, phone, address, district, thana, item_title, quantity, notes ইত্যাদি)
     *   — এছাড়া অ্যাডমিন পেজ থেকে ইনজেক্ট করা দুইটা এক্সট্রা কী থাকতে পারে:
     *   `_amount_to_collect` (float, COD কালেকশন পরিমাণ) ও `_item_description` (string)
     * @return array{success: bool, consignment_id: ?string, tracking_url: ?string, delivery_fee: ?float, message: string, raw: string}
     */
    public function createShipment(array $order): array;
}
