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

    /**
     * Create a Payment Intent for direct charging
     * 
     * @param array $bookingData
     * @param string $paymentMethodId
     * @return \Stripe\PaymentIntent
     */
    public function createPaymentIntent(array $bookingData, string $paymentMethodId)
    {
        // Calculate total amount
        $totalAmount = 0;
        foreach ($bookingData['bills'] as $bill) {
            $totalAmount += (float)$bill['price'] * 100; // Convert to cents
        }

        $paymentIntentData = [
            'amount' => (int)round($totalAmount),
            'currency' => $this->config['currency'] ?? 'AUD',
            'payment_method' => $paymentMethodId,
            'confirm' => true,
            'return_url' => $this->getReturnUrl(),
            'metadata' => [
                'user_id' => $bookingData['user_id'],
                'square_id' => $bookingData['square_id'],
                'booking_type' => 'court_booking',
            ],
        ];

        // Add customer email if available
        if (isset($bookingData['customer_email'])) {
            $paymentIntentData['receipt_email'] = $bookingData['customer_email'];
        }

        error_log('Creating Payment Intent: Amount ' . ($totalAmount / 100) . ' AUD');

        return \Stripe\PaymentIntent::create($paymentIntentData);
    }

    /**
     * Create a Payment Intent without confirming (for client-side confirmation)
     * 
     * @param array $bookingData
     * @return \Stripe\PaymentIntent
     */
    public function createUnconfirmedPaymentIntent(array $bookingData)
    {
        // Calculate total amount
        $totalAmount = 0;
        foreach ($bookingData['bills'] as $bill) {
            $totalAmount += (float)$bill['price'] * 100; // Convert to cents
        }

        $paymentIntentData = [
            'amount' => (int)round($totalAmount),
            'currency' => $this->config['currency'] ?? 'AUD',
            'automatic_payment_methods' => [
                'enabled' => true,
            ],
            'metadata' => [
                'user_id' => $bookingData['user_id'],
                'square_id' => $bookingData['square_id'],
                'booking_type' => 'court_booking',
            ],
        ];

        // Add customer email if available
        if (isset($bookingData['customer_email'])) {
            $paymentIntentData['receipt_email'] = $bookingData['customer_email'];
        }

        error_log('Creating unconfirmed Payment Intent: Amount ' . ($totalAmount / 100) . ' AUD');

        return \Stripe\PaymentIntent::create($paymentIntentData);
    }

    /**
     * Confirm a Payment Intent with payment method
     * 
     * @param string $paymentIntentId
     * @param string $paymentMethodId
     * @return \Stripe\PaymentIntent
     */
    public function confirmPaymentIntent(string $paymentIntentId, string $paymentMethodId)
    {
        return \Stripe\PaymentIntent::update($paymentIntentId, [
            'payment_method' => $paymentMethodId,
        ])->confirm();
    }

    /**
     * Retrieve a Payment Intent
     * 
     * @param string $paymentIntentId
     * @return \Stripe\PaymentIntent
     */
    public function getPaymentIntent(string $paymentIntentId)
    {
        return \Stripe\PaymentIntent::retrieve($paymentIntentId);
    }

    /**
     * Create a customer for recurring payments
     * 
     * @param array $customerData
     * @return \Stripe\Customer
     */
    public function createCustomer(array $customerData)
    {
        $customerData = [
            'email' => $customerData['email'],
            'name' => $customerData['name'] ?? '',
            'metadata' => [
                'user_id' => $customerData['user_id'] ?? '',
            ],
        ];

        return \Stripe\Customer::create($customerData);
    }

    /**
     * Create a subscription for recurring payments
     * 
     * @param string $customerId
     * @param string $priceId
     * @return \Stripe\Subscription
     */
    public function createSubscription(string $customerId, string $priceId)
    {
        return \Stripe\Subscription::create([
            'customer' => $customerId,
            'items' => [
                ['price' => $priceId],
            ],
            'payment_behavior' => 'default_incomplete',
            'expand' => ['latest_invoice.payment_intent'],
        ]);
    }

    /**
     * Get return URL for payment confirmation
     * 
     * @return string
     */
    private function getReturnUrl()
    {
        // You can customize this based on your needs
        return 'https://your-domain.com/payment/return';
    }

    /**
     * Calculate Stripe processing fees for Australia
     * 
     * @param float $amount Amount in AUD
     * @param bool $isInternational Whether it's an international card
     * @return array Array with fee amount and total
     */
    public function calculateStripeFees(float $amount, bool $isInternational = false)
    {
        // Stripe fees for Australia
        $percentageFee = $isInternational ? 0.029 : 0.0175; // 2.9% or 1.75%
        $fixedFee = 0.30; // 30 cents AUD

        $percentageAmount = $amount * $percentageFee;
        $totalFee = $percentageAmount + $fixedFee;
        $totalWithFees = $amount + $totalFee;

        return [
            'original_amount' => $amount,
            'percentage_fee' => $percentageAmount,
            'fixed_fee' => $fixedFee,
            'total_fee' => $totalFee,
            'total_with_fees' => $totalWithFees,
            'fee_percentage' => ($totalFee / $amount) * 100,
        ];
    }

    /**
     * Add Stripe fees to booking data
     * 
     * @param array $bookingData
     * @param bool $isInternational
     * @return array Updated booking data with fees
     */
    public function addStripeFees(array $bookingData, bool $isInternational = false)
    {
        // Calculate total amount
        $totalAmount = 0;
        foreach ($bookingData['bills'] as $bill) {
            $totalAmount += (float)$bill['price'];
        }

        // Calculate fees
        $feeCalculation = $this->calculateStripeFees($totalAmount, $isInternational);

        // Add fee as a separate line item
        $bookingData['bills'][] = [
            'description' => 'Payment Processing Fee',
            'quantity' => 1,
            'price' => $feeCalculation['total_fee'],
            'rate' => 0, // No additional tax on fees
            'gross' => true, // Fee is gross amount
        ];

        error_log('Stripe fees added: Original ' . $feeCalculation['original_amount'] .
            ' AUD, Fee ' . $feeCalculation['total_fee'] .
            ' AUD (' . round($feeCalculation['fee_percentage'], 2) . '%), ' .
            'Total ' . $feeCalculation['total_with_fees'] . ' AUD');

        return $bookingData;
    }

    /**
     * Create checkout session with fees included
     * 
     * @param array $bookingData
     * @param string $successUrl
     * @param string $cancelUrl
     * @param bool $includeFees Whether to include Stripe fees
     * @param bool $isInternational Whether it's an international card
     * @return \Stripe\Checkout\Session
     */
    public function createCheckoutSessionWithFees(array $bookingData, string $successUrl, string $cancelUrl, bool $includeFees = false, bool $isInternational = false)
    {
        if ($includeFees) {
            $bookingData = $this->addStripeFees($bookingData, $isInternational);
        }

        return $this->createCheckoutSession($bookingData, $successUrl, $cancelUrl);
    }

    /**
     * Get fee information for display
     * 
     * @param float $amount
     * @param bool $isInternational
     * @return array Fee information for display
     */
    public function getFeeInfo(float $amount, bool $isInternational = false)
    {
        $fees = $this->calculateStripeFees($amount, $isInternational);

        return [
            'fee_amount' => $fees['total_fee'],
            'fee_percentage' => $fees['fee_percentage'],
            'total_with_fees' => $fees['total_with_fees'],
            'fee_breakdown' => [
                'percentage' => $fees['percentage_fee'],
                'fixed' => $fees['fixed_fee'],
            ],
            'card_type' => $isInternational ? 'International' : 'Domestic',
        ];
    }
}
