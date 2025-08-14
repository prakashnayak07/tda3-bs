<?php

namespace Payment\Service;

use RuntimeException;
use Stripe\Stripe;
use Stripe\Checkout\Session;

class StripeService
{
    private $config;

    public function __construct(array $config)
    {
        $this->config = $config;

        if (empty($this->config['secret_key'])) {
            throw new RuntimeException('Stripe secret key is not configured');
        }

        Stripe::setApiKey($this->config['secret_key']);
    }

    public function isConfigured(): bool
    {
        return !empty($this->config['secret_key']) && !empty($this->config['publishable_key']);
    }

    public function createCheckoutSession(array $bookingData, string $successUrl, string $cancelUrl)
    {
        $lineItems = [];

        // Convert bills to Stripe line items
        foreach ($bookingData['bills'] as $bill) {
            // Check if this is a product (has quantity > 1) or a court booking
            $isProduct = isset($bill['quantity']) && $bill['quantity'] > 1;

            if ($isProduct) {
                // For products: calculate unit price and use actual quantity
                $totalPrice = (float)$bill['price'];
                $quantity = (int)$bill['quantity'];
                $unitPrice = $totalPrice / $quantity;
                $unitAmountInCents = (int)round($unitPrice * 100);

                error_log('Product pricing: Total ' . $totalPrice . ' AUD, Quantity ' . $quantity . ', Unit Price ' . $unitPrice . ' AUD (' . $unitAmountInCents . ' cents)');

                $lineItem = [
                    'price_data' => [
                        'currency' => $this->config['currency'] ?? 'AUD',
                        'product_data' => [
                            'name' => $bill['description'] . ' (per item)',
                        ],
                        'unit_amount' => $unitAmountInCents,
                    ],
                    'quantity' => $quantity, // Use actual product quantity
                ];
            } else {
                // For court bookings: use total price as is
                $price = (float)$bill['price'];
                $amountInCents = (int)round($price * 100);

                error_log('Court booking pricing: ' . $price . ' AUD -> ' . $amountInCents . ' cents');

                $lineItem = [
                    'price_data' => [
                        'currency' => $this->config['currency'] ?? 'AUD',
                        'product_data' => [
                            'name' => $bill['description'],
                        ],
                        'unit_amount' => $amountInCents,
                    ],
                    'quantity' => 1, // Court bookings are always quantity 1
                ];
            }

            error_log('Bill details - Gross: ' . ($bill['gross'] ? 'true' : 'false') . ', Rate: ' . ($bill['rate'] ?? 'N/A') . ', Quantity: ' . ($lineItem['quantity']));

            // Handle tax configuration for Stripe
            // If gross = true, price already includes tax, so we don't add tax in Stripe
            // If gross = false, price is net, so we need to add tax in Stripe
            if (isset($bill['gross']) && isset($bill['rate']) && $bill['rate'] > 0) {
                if (!$bill['gross']) {
                    // Net pricing - calculate final price with tax included
                    if ($isProduct) {
                        // For products: apply tax to unit price
                        $taxAmount = $unitPrice * ($bill['rate'] / 100);
                        $finalUnitPrice = $unitPrice + $taxAmount;
                        $finalAmountInCents = (int)round($finalUnitPrice * 100);

                        $lineItem['price_data']['unit_amount'] = $finalAmountInCents;
                        error_log('Product net pricing: Unit ' . $unitPrice . ' + Tax ' . $taxAmount . ' = Final Unit ' . $finalUnitPrice . ' AUD (' . $finalAmountInCents . ' cents)');
                    } else {
                        // For court bookings: apply tax to total price
                        $taxAmount = $price * ($bill['rate'] / 100);
                        $finalPrice = $price + $taxAmount;
                        $finalAmountInCents = (int)round($finalPrice * 100);

                        $lineItem['price_data']['unit_amount'] = $finalAmountInCents;
                        error_log('Court net pricing: Original ' . $price . ' + Tax ' . $taxAmount . ' = Final ' . $finalPrice . ' AUD (' . $finalAmountInCents . ' cents)');
                    }
                } else {
                    // Gross pricing - price already includes tax, use as is
                    error_log('Gross pricing: Price already includes tax');
                }
            }

            $lineItems[] = $lineItem;
        }

        $sessionData = [
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => $successUrl . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl . '?session_id={CHECKOUT_SESSION_ID}',
            'customer_email' => $bookingData['customer_email'] ?? null,
            'metadata' => [
                'user_id' => $bookingData['user_id'],
                'square_id' => $bookingData['square_id'],
                'ds' => $bookingData['ds'],
                'de' => $bookingData['de'],
                'ts' => $bookingData['ts'],
                'te' => $bookingData['te'],
                'quantity' => $bookingData['quantity'],
            ],
        ];

        // Add any additional metadata
        if (isset($bookingData['meta'])) {
            foreach ($bookingData['meta'] as $key => $value) {
                $sessionData['metadata'][$key] = is_array($value) ? json_encode($value) : $value;
            }
        }

        return Session::create($sessionData);
    }

    public function getCheckoutSession(string $sessionId)
    {
        return Session::retrieve($sessionId);
    }

    public function getPublishableKey(): string
    {
        return $this->config['publishable_key'] ?? '';
    }

    public function verifyWebhookSignature(string $payload, string $signature): array
    {
        if (empty($this->config['webhook_secret'])) {
            throw new RuntimeException('Webhook secret is not configured');
        }

        try {
            $event = \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $this->config['webhook_secret']
            );
            return $event->toArray();
        } catch (\UnexpectedValueException $e) {
            throw new RuntimeException('Invalid payload');
        } catch (\Stripe\Exception\SignatureVerificationException $e) {
            throw new RuntimeException('Invalid signature');
        }
    }
}
