<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\ShippingProvider;
use App\Models\PancakeShop;
use App\Models\PancakePage;
use App\Models\Province;
use App\Models\District;
use App\Models\Ward;
use App\Models\Customer;
use App\Models\CustomerPhone;
use Faker\Factory as Faker;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class OrderSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        Order::truncate(); // Xóa các đơn hàng cũ trước khi seed
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        $faker = Faker::create('vi_VN');

        // Fetch prerequisite data
        $staffUsers = User::whereHas('roles', function ($query) {
            $query->whereIn('name', ['staff', 'manager']);
        })->pluck('id')->toArray();

        $warehouses = Warehouse::pluck('id')->toArray();
        $shippingProviders = ShippingProvider::pluck('id')->toArray();
        $pancakeShops = PancakeShop::pluck('id')->toArray();
        $provinces = Province::pluck('code')->toArray();

        if (empty($staffUsers) || empty($warehouses) || empty($provinces)) {
            $this->command->warn('Cannot seed orders: Missing staff users, warehouses, or provinces. Please seed them first.');
            return;
        }

        $orderStatuses = [
            Order::STATUS_MOI,
            Order::STATUS_CAN_XU_LY,
            Order::STATUS_CHO_HANG,
            Order::STATUS_DA_DAT_HANG,
            Order::STATUS_CHO_CHUYEN_HANG,
            Order::STATUS_DA_GUI_HANG,
            Order::STATUS_DA_NHAN,
            Order::STATUS_DA_THU_TIEN,
            Order::STATUS_DA_HOAN,
            Order::STATUS_DA_HUY,
        ];

        $paymentMethods = ['cod', 'banking', 'momo', 'zalopay', 'other'];

        // First, create a pool of customers
        $this->command->info("Creating customer pool...");
        $customers = [];
        for ($i = 0; $i < 20; $i++) {
            $customerName = $faker->name;
            $customerPhone = $faker->numerify('09########');
            $customerEmail = $faker->optional()->safeEmail;

            $selectedProvinceCode = $provinces[array_rand($provinces)];
            $districtsInProvince = District::where('province_code', $selectedProvinceCode)->pluck('code')->toArray();
            $selectedDistrictCode = !empty($districtsInProvince) ? $districtsInProvince[array_rand($districtsInProvince)] : null;

            $wardsInDistrict = null;
            if ($selectedDistrictCode) {
                $wardsInDistrict = Ward::where('district_code', $selectedDistrictCode)->pluck('code')->toArray();
            }
            $selectedWardCode = !empty($wardsInDistrict) ? $wardsInDistrict[array_rand($wardsInDistrict)] : null;

            $customer = Customer::create([
                'name' => $customerName,
                'email' => $customerEmail,
                'full_address' => $faker->address,
                'province' => $selectedProvinceCode,
                'district' => $selectedDistrictCode,
                'ward' => $selectedWardCode,
                'street_address' => $faker->streetAddress,
            ]);

            CustomerPhone::create([
                'customer_id' => $customer->id,
                'phone_number' => $customerPhone,
                'is_primary' => true,
            ]);

            $customers[] = [
                'customer' => $customer,
                'phone' => $customerPhone,
            ];
        }

        $this->command->info("Creating orders for each customer...");
        $progressBar = $this->command->getOutput()->createProgressBar(count($customers) * 5); // 5 orders per customer
        $progressBar->start();

        // Create multiple orders for each customer
        foreach ($customers as $customerData) {
            $customer = $customerData['customer'];
            $ordersCount = rand(3, 7); // Random number of orders between 3 and 7

            for ($i = 0; $i < $ordersCount; $i++) {
                $selectedUserId = $staffUsers[array_rand($staffUsers)];
                $selectedWarehouseId = $warehouses[array_rand($warehouses)];
                $selectedShippingProviderId = !empty($shippingProviders) ? $shippingProviders[array_rand($shippingProviders)] : null;

                $selectedPancakeShopId = null;
                $selectedPancakePageId = null;
                if (!empty($pancakeShops)) {
                    $selectedPancakeShopId = $pancakeShops[array_rand($pancakeShops)];
                    $availablePagesForShop = PancakePage::where('pancake_shop_table_id', $selectedPancakeShopId)->pluck('id')->toArray();
                    if (!empty($availablePagesForShop)) {
                        $selectedPancakePageId = $availablePagesForShop[array_rand($availablePagesForShop)];
                    }
                }

                $order = Order::create([
                    'order_code' => 'ORD-' . strtoupper(Str::random(4)) . '-' . time() . $i,
                    'customer_id' => $customer->id,
                    'customer_name' => $customer->name,
                    'customer_phone' => $customerData['phone'],
                    'shipping_fee' => $faker->numberBetween(0, 100) * 1000,
                    'transfer_money' => $faker->numberBetween(50, 500) * 1000,
                    'payment_method' => $paymentMethods[array_rand($paymentMethods)],
                    'shipping_provider_id' => $selectedShippingProviderId,
                    'internal_status' => 'Seeded Order',
                    'notes' => $faker->optional()->sentence,
                    'additional_notes' => $faker->optional()->paragraph,
                    'total_value' => 0,
                    'status' => $orderStatuses[array_rand($orderStatuses)],
                    'user_id' => $selectedUserId,
                    'created_by' => $selectedUserId,
                    'province_code' => $customer->province,
                    'district_code' => $customer->district,
                    'ward_code' => $customer->ward,
                    'street_address' => $customer->street_address,
                    'full_address' => $customer->full_address,
                    'warehouse_id' => $selectedWarehouseId,
                    'pancake_shop_id' => $selectedPancakeShopId,
                    'pancake_page_id' => $selectedPancakePageId,
                    'created_at' => $faker->dateTimeBetween('-1 year', 'now'),
                    'updated_at' => now(),
                ]);

                $totalOrderValue = 0;
                $itemCount = $faker->numberBetween(1, 5);

                for ($j = 0; $j < $itemCount; $j++) {
                    $itemPrice = $faker->numberBetween(50, 1000) * 1000;
                    $itemQuantity = $faker->numberBetween(1, 5);
                    $itemName = 'Sản phẩm ' . Str::random(3);

                    OrderItem::create([
                        'order_id' => $order->id,
                        'code' => 'SKU-' . strtoupper(Str::random(5)),
                        'quantity' => $itemQuantity,
                        'name' => $itemName,
                        'price' => $itemPrice,
                    ]);
                    $totalOrderValue += $itemPrice * $itemQuantity;
                }

                $order->total_value = $totalOrderValue + $order->shipping_fee;
                $order->save();

                // Update customer's order statistics
                $customer->total_orders_count = Order::where('customer_id', $customer->id)->count();
                $customer->total_spent = Order::where('customer_id', $customer->id)
                    ->where('status', Order::STATUS_DA_THU_TIEN)
                    ->sum('total_value');
                $customer->last_order_date = $order->created_at;
                if (!$customer->first_order_date) {
                    $customer->first_order_date = $order->created_at;
                }
                $customer->save();

                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $this->command->info("\nĐã tạo xong đơn hàng cho tất cả khách hàng.");
    }
}
