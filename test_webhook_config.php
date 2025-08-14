<?php

/**
 * Test script to verify webhook configuration
 */

require_once 'config/init.php';

$serviceManager = \Zend\Mvc\Application::init(require 'config/application.config.php')->getServiceManager();
$optionManager = $serviceManager->get('Base\Manager\OptionManager');

echo "=== Webhook Configuration Test ===\n\n";

try {
    // Test 1: Check current webhook settings
    echo "Test 1: Current Webhook Settings\n";
    echo "--------------------------------\n";

    $useWebhook = $optionManager->get('service.payment.stripe.use_webhook', true);
    $useSessionCheck = $optionManager->get('service.payment.stripe.use_session_check', true);

    echo "Webhook validation enabled: " . ($useWebhook ? 'YES' : 'NO') . "\n";
    echo "Session check validation enabled: " . ($useSessionCheck ? 'YES' : 'NO') . "\n";

    if ($useWebhook && !$useSessionCheck) {
        echo "Mode: WEBHOOK ONLY\n";
        echo "- Relies entirely on webhook for payment validation\n";
        echo "- Session check is disabled\n";
        echo "- User sees 'Payment received! Your booking will be confirmed shortly.'\n";
    } elseif (!$useWebhook && $useSessionCheck) {
        echo "Mode: SESSION CHECK ONLY\n";
        echo "- Immediate validation via Stripe API\n";
        echo "- Webhook is disabled\n";
        echo "- User gets immediate booking confirmation\n";
    } elseif ($useWebhook && $useSessionCheck) {
        echo "Mode: HYBRID\n";
        echo "- Session check first, webhook as fallback\n";
        echo "- Immediate confirmation if session check works\n";
        echo "- Webhook backup for reliability\n";
    } else {
        echo "Mode: DEFAULT (Session check)\n";
        echo "- Neither method enabled, defaults to session check\n";
    }

    echo "\n";

    // Test 2: Check webhook processing tracking
    echo "Test 2: Webhook Processing Tracking\n";
    echo "-----------------------------------\n";

    $processedWebhooks = $optionManager->get('stripe.processed_webhooks', '[]');
    $webhookArray = json_decode($processedWebhooks, true) ?: [];

    echo "Currently tracked webhooks: " . count($webhookArray) . "\n";

    if (!empty($webhookArray)) {
        echo "Recent webhook IDs:\n";
        $recent = array_slice($webhookArray, -5);
        foreach ($recent as $webhookId) {
            echo "  - " . $webhookId . "\n";
        }
    }

    echo "\n";

    // Test 3: Check Stripe configuration
    echo "Test 3: Stripe Configuration\n";
    echo "----------------------------\n";

    $stripeConfig = $serviceManager->get('config')['stripe'] ?? [];

    echo "Test mode: " . ($stripeConfig['test_mode'] ? 'YES' : 'NO') . "\n";
    echo "Currency: " . ($stripeConfig['currency'] ?? 'NOT SET') . "\n";
    echo "Test secret key: " . (strlen($stripeConfig['test_secret_key'] ?? '') > 0 ? 'SET' : 'NOT SET') . "\n";
    echo "Test publishable key: " . (strlen($stripeConfig['test_publishable_key'] ?? '') > 0 ? 'SET' : 'NOT SET') . "\n";
    echo "Webhook secret: " . (strlen($stripeConfig['webhook_secret'] ?? '') > 0 ? 'SET' : 'NOT SET') . "\n";

    echo "\n";

    // Test 4: Webhook endpoint verification
    echo "Test 4: Webhook Endpoint Verification\n";
    echo "-------------------------------------\n";

    echo "Expected webhook endpoint: /payment/webhook\n";
    echo "Full URL (if using ngrok): https://your-ngrok-url.ngrok-free.app/payment/webhook\n";
    echo "\n";
    echo "To verify webhook endpoint:\n";
    echo "1. Check Stripe Dashboard > Webhooks\n";
    echo "2. Verify endpoint URL is correct\n";
    echo "3. Ensure 'checkout.session.completed' event is selected\n";
    echo "4. Check webhook secret matches your config\n";

    echo "\n";

    echo "=== Recommendations ===\n";

    if ($useWebhook && !$useSessionCheck) {
        echo "✅ Webhook-only mode is configured correctly\n";
        echo "📋 Next steps:\n";
        echo "  1. Make a test payment\n";
        echo "  2. Check ngrok logs for webhook requests\n";
        echo "  3. Verify webhook appears in Stripe dashboard\n";
        echo "  4. Check booking is created after webhook\n";
    } elseif (!$useWebhook && $useSessionCheck) {
        echo "✅ Session-check-only mode is configured correctly\n";
        echo "📋 Next steps:\n";
        echo "  1. Make a test payment\n";
        echo "  2. Verify immediate booking confirmation\n";
        echo "  3. Check no webhook processing occurs\n";
    } elseif ($useWebhook && $useSessionCheck) {
        echo "✅ Hybrid mode is configured correctly\n";
        echo "📋 Next steps:\n";
        echo "  1. Make a test payment\n";
        echo "  2. Check for immediate confirmation or webhook fallback\n";
        echo "  3. Monitor both session check and webhook processing\n";
    }

    echo "\n=== Troubleshooting ===\n";
    echo "If webhooks are not hitting:\n";
    echo "1. Verify ngrok is running and accessible\n";
    echo "2. Check webhook URL in Stripe dashboard\n";
    echo "3. Ensure webhook secret is correct\n";
    echo "4. Test webhook endpoint manually\n";
    echo "5. Check server logs for errors\n";
} catch (Exception $e) {
    echo "Error during testing: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
}

echo "\nTest completed successfully!\n";
