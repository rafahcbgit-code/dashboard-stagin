<?php

namespace App\Services\WooCommerce\Webhooks\Schemas;

class WooCommerceAddToCartFieldSchema
{
    public function fields(): array
    {
        return [
            ['label' => 'Action', 'path' => 'action'],
            ['label' => 'Arg', 'path' => 'arg'],
            ['label' => 'Product ID', 'path' => 'chatberry_add_to_cart.product_id'],
            ['label' => 'Product Name', 'path' => 'chatberry_add_to_cart.product.name'],
            ['label' => 'Product Price', 'path' => 'chatberry_add_to_cart.product.price'],
            ['label' => 'Customer ID', 'path' => 'chatberry_add_to_cart.customer_id'],
            ['label' => 'Customer First Name', 'path' => 'chatberry_add_to_cart.customer.first_name'],
            ['label' => 'Customer Last Name', 'path' => 'chatberry_add_to_cart.customer.last_name'],
            ['label' => 'Customer Email', 'path' => 'chatberry_add_to_cart.customer.email'],
            ['label' => 'Customer Billing Phone', 'path' => 'chatberry_add_to_cart.customer.billing.phone'],
            ['label' => 'Customer Shipping Phone', 'path' => 'chatberry_add_to_cart.customer.shipping.phone'],
            ['label' => 'Cart Token', 'path' => 'chatberry_add_to_cart.cart_token'],
            ['label' => 'Cart Total', 'path' => 'chatberry_add_to_cart.cart.totals.total_price'],
            ['label' => 'Cart Item Count', 'path' => 'chatberry_add_to_cart.cart.items_count'],
            ['label' => 'First Cart Item Name', 'path' => 'chatberry_add_to_cart.cart.items.0.name'],
            ['label' => 'First Cart Item Quantity', 'path' => 'chatberry_add_to_cart.cart.items.0.quantity'],
            ['label' => 'First Cart Item Price', 'path' => 'chatberry_add_to_cart.cart.items.0.prices.price'],
        ];
    }
}
