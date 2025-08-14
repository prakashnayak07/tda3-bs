<?php

namespace Payment\Controller;

use RuntimeException;
use Exception;
use Zend\Mvc\Controller\AbstractActionController;

class StripeController extends AbstractActionController
{
    public function indexAction()
    {
        return $this->redirect()->toRoute('frontend');
    }

    public function successAction()
    {
        $sessionId = $this->params()->fromQuery('session_id');

        error_log('=== PAYMENT SUCCESS CALLBACK ===');
        error_log('Session ID: ' . ($sessionId ?: 'NOT PROVIDED'));

        // Check for database-based success message (from webhook)
        $successMessage = $this->getPaymentSuccessMessage();
        if ($successMessage) {
            $this->flashMessenger()->addSuccessMessage($successMessage);
            $this->clearPaymentSuccessMessage();
            return $this->redirect()->toRoute('frontend');
        }

        if ($sessionId) {
            $serviceManager = $this->getServiceLocator();
            $stripeService = $serviceManager->get('Payment\Service\StripeService');
            $optionManager = $serviceManager->get('Base\Manager\OptionManager');

            // Check which validation methods are enabled
            $useSessionCheck = $optionManager->get('service.payment.stripe.use_session_check', true);
            $useWebhook = $optionManager->get('service.payment.stripe.use_webhook', true);

            // If neither method is enabled, default to session check
            if (!$useSessionCheck && !$useWebhook) {
                $useSessionCheck = true;
            }

            $paymentValidated = false;
            $session = null;

            try {
                // WEBHOOK-ONLY MODE: Skip session check entirely
                if ($useWebhook && !$useSessionCheck) {
                    error_log('Success: Webhook-only mode - waiting for webhook to process payment');
                    $this->flashMessenger()->addInfoMessage(
                        $this->t('Payment received! Your booking will be confirmed shortly.')
                    );
                    return $this->redirect()->toRoute('frontend');
                }

                // SESSION CHECK MODE: Try session check first
                if ($useSessionCheck) {
                    // Method 1: Direct session check via Stripe API
                    $session = $stripeService->getCheckoutSession($sessionId);
                    error_log('Payment status: ' . ($session->payment_status ?? 'NOT FOUND'));

                    if ($session->payment_status === 'paid') {
                        error_log('Success (Session Check): Payment confirmed as paid!');
                        $paymentValidated = true;
                    } else {
                        error_log('Success (Session Check): Payment status is ' . $session->payment_status);
                    }
                }

                // HYBRID MODE: If session check failed and webhook is enabled, wait for webhook
                if (!$paymentValidated && $useWebhook && $useSessionCheck) {
                    error_log('Success: Hybrid mode - session check failed, waiting for webhook...');
                    $this->flashMessenger()->addInfoMessage(
                        $this->t('Payment received! Your booking will be confirmed shortly.')
                    );
                    return $this->redirect()->toRoute('frontend');
                }

                // If payment was validated by session check, create booking immediately
                if ($paymentValidated && $session) {
                    $this->createBookingFromSession($session);

                    $this->flashMessenger()->addSuccessMessage(
                        $this->t('Payment successful! Your booking has been confirmed.')
                    );
                } else {
                    $this->flashMessenger()->addErrorMessage(
                        $this->t('Payment was not completed successfully.')
                    );
                }
            } catch (RuntimeException $e) {
                error_log('Error verifying payment: ' . $e->getMessage());
                $this->flashMessenger()->addErrorMessage(
                    $this->t('Error verifying payment: ' . $e->getMessage())
                );
            }
        } else {
            $this->flashMessenger()->addErrorMessage(
                $this->t('Invalid payment session.')
            );
        }

        return $this->redirect()->toRoute('frontend');
    }

    public function cancelAction()
    {
        $sessionId = $this->params()->fromQuery('session_id');

        error_log('=== PAYMENT CANCELLED ===');
        error_log('Session ID: ' . ($sessionId ?: 'NOT PROVIDED'));

        $this->flashMessenger()->addInfoMessage(
            $this->t('Payment was cancelled. You can try again later.')
        );

        return $this->redirect()->toRoute('frontend');
    }

    public function webhookAction()
    {
        $payload = file_get_contents('php://input');
        $signature = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

        error_log('=== WEBHOOK RECEIVED ===');
        error_log('Payload length: ' . strlen($payload));
        error_log('Signature: ' . $signature);

        $serviceManager = $this->getServiceLocator();
        $stripeService = $serviceManager->get('Payment\Service\StripeService');
        $optionManager = $serviceManager->get('Base\Manager\OptionManager');

        // Check if webhook validation is enabled
        $useWebhook = $optionManager->get('service.payment.stripe.use_webhook', true);

        if (!$useWebhook) {
            error_log('Webhook: Webhook validation is disabled, ignoring webhook');
            return $this->getResponse()->setStatusCode(200);
        }

        try {
            // Verify webhook signature for security
            $event = $stripeService->verifyWebhookSignature($payload, $signature);
            error_log('Event type: ' . ($event['type'] ?? 'UNKNOWN'));

            if ($event['type'] === 'checkout.session.completed') {
                $session = $event['data']['object'];

                if ($session['payment_status'] === 'paid') {
                    error_log('Webhook: Payment confirmed as paid! Session ID: ' . ($session['id'] ?? 'unknown'));

                    // IDEMPOTENCY: Check if we've already processed this webhook
                    $webhookId = $event['id'] ?? null;
                    $sessionId = $session['id'] ?? null;

                    if ($webhookId && $this->isWebhookProcessed($webhookId)) {
                        error_log('IDEMPOTENCY: Webhook already processed: ' . $webhookId);
                        return $this->getResponse()->setStatusCode(200);
                    }

                    // Additional check: Check if booking already exists for this session
                    if ($sessionId && $this->findBookingBySessionId($sessionId)) {
                        error_log('IDEMPOTENCY: Booking already exists for session: ' . $sessionId);
                        // Still mark webhook as processed to prevent future attempts
                        if ($webhookId) {
                            $this->markWebhookProcessed($webhookId);
                        }
                        return $this->getResponse()->setStatusCode(200);
                    }

                    $this->createBookingFromSession($session);

                    // Mark webhook as processed
                    if ($webhookId) {
                        $this->markWebhookProcessed($webhookId);
                    }

                    // Store success message in database for user to see when they return
                    $this->storePaymentSuccessMessage($session['metadata']['user_id'] ?? null, 'Payment successful! Your booking has been confirmed.');
                } else {
                    error_log('Webhook: Payment status is ' . $session['payment_status']);
                }
            } else {
                error_log('Webhook: Ignoring event type ' . $event['type']);
            }

            return $this->getResponse()->setStatusCode(200);
        } catch (RuntimeException $e) {
            error_log('Webhook error: ' . $e->getMessage());
            return $this->getResponse()->setStatusCode(400);
        }
    }

    /**
     * Create a Payment Intent for direct charging
     */
    public function createIntentAction()
    {
        $this->authorize('user');

        $serviceManager = $this->getServiceLocator();
        $stripeService = $serviceManager->get('Payment\Service\StripeService');

        try {
            // Get booking data from request
            $bookingData = $this->params()->fromPost('booking_data');

            if (!$bookingData) {
                throw new RuntimeException('Booking data is required');
            }

            // Create unconfirmed Payment Intent
            $paymentIntent = $stripeService->createUnconfirmedPaymentIntent($bookingData);

            return $this->getResponse()->setContent(json_encode([
                'success' => true,
                'client_secret' => $paymentIntent->client_secret,
                'payment_intent_id' => $paymentIntent->id,
            ]));
        } catch (RuntimeException $e) {
            error_log('Error creating Payment Intent: ' . $e->getMessage());

            return $this->getResponse()->setContent(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Confirm a Payment Intent
     */
    public function confirmIntentAction()
    {
        $this->authorize('user');

        $serviceManager = $this->getServiceLocator();
        $stripeService = $serviceManager->get('Payment\Service\StripeService');

        try {
            $paymentIntentId = $this->params()->fromPost('payment_intent_id');
            $paymentMethodId = $this->params()->fromPost('payment_method_id');

            if (!$paymentIntentId || !$paymentMethodId) {
                throw new RuntimeException('Payment Intent ID and Payment Method ID are required');
            }

            // Confirm the Payment Intent
            $paymentIntent = $stripeService->confirmPaymentIntent($paymentIntentId, $paymentMethodId);

            if ($paymentIntent->status === 'succeeded') {
                // Payment successful - create booking
                $this->createBookingFromPaymentIntent($paymentIntent);

                return $this->getResponse()->setContent(json_encode([
                    'success' => true,
                    'message' => 'Payment successful!',
                ]));
            } else {
                return $this->getResponse()->setContent(json_encode([
                    'success' => false,
                    'error' => 'Payment failed: ' . $paymentIntent->status,
                ]));
            }
        } catch (RuntimeException $e) {
            error_log('Error confirming Payment Intent: ' . $e->getMessage());

            return $this->getResponse()->setContent(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]));
        }
    }

    /**
     * Direct charge endpoint
     */
    public function chargeAction()
    {
        $this->authorize('user');

        $serviceManager = $this->getServiceLocator();
        $stripeService = $serviceManager->get('Payment\Service\StripeService');

        try {
            $bookingData = $this->params()->fromPost('booking_data');
            $paymentMethodId = $this->params()->fromPost('payment_method_id');

            if (!$bookingData || !$paymentMethodId) {
                throw new RuntimeException('Booking data and payment method are required');
            }

            // Create and confirm Payment Intent in one step
            $paymentIntent = $stripeService->createPaymentIntent($bookingData, $paymentMethodId);

            if ($paymentIntent->status === 'succeeded') {
                // Payment successful - create booking
                $this->createBookingFromPaymentIntent($paymentIntent);

                $this->flashMessenger()->addSuccessMessage(
                    $this->t('Payment successful! Your booking has been confirmed.')
                );
            } else {
                $this->flashMessenger()->addErrorMessage(
                    $this->t('Payment failed: ' . $paymentIntent->status)
                );
            }
        } catch (RuntimeException $e) {
            error_log('Error processing direct charge: ' . $e->getMessage());
            $this->flashMessenger()->addErrorMessage(
                $this->t('Payment error: ' . $e->getMessage())
            );
        }

        return $this->redirect()->toRoute('frontend');
    }

    /**
     * Calculate and display Stripe fees
     */
    public function calculateFeesAction()
    {
        $this->authorize('user');

        $serviceManager = $this->getServiceLocator();
        $stripeService = $serviceManager->get('Payment\Service\StripeService');

        try {
            $amount = (float)$this->params()->fromPost('amount', 0);
            $isInternational = (bool)$this->params()->fromPost('international', false);

            if ($amount <= 0) {
                throw new RuntimeException('Invalid amount');
            }

            $feeInfo = $stripeService->getFeeInfo($amount, $isInternational);

            return $this->getResponse()->setContent(json_encode([
                'success' => true,
                'fee_info' => $feeInfo,
            ]));
        } catch (RuntimeException $e) {
            error_log('Error calculating fees: ' . $e->getMessage());

            return $this->getResponse()->setContent(json_encode([
                'success' => false,
                'error' => $e->getMessage(),
            ]));
        }
    }

    private function createBookingFromSession($session)
    {
        $serviceManager = $this->getServiceLocator();
        $bookingService = $serviceManager->get('Booking\Service\BookingService');
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $squareManager = $serviceManager->get('Square\Manager\SquareManager');

        try {
            $metadata = $session->metadata ?? $session['metadata'];
            $sessionId = $session->id ?? $session['id'] ?? null;
            $paymentIntentId = $session->payment_intent ?? $session['payment_intent'] ?? null;

            // IDEMPOTENCY CHECK: Check if booking already exists for this session
            if ($sessionId) {
                $existingBooking = $this->findBookingBySessionId($sessionId);
                if ($existingBooking) {
                    error_log('IDEMPOTENCY: Booking already exists for session: ' . $sessionId);
                    return;
                }
            }

            // IDEMPOTENCY CHECK: Check if booking already exists for this payment intent
            if ($paymentIntentId) {
                $existingBooking = $this->findBookingByPaymentIntent($paymentIntentId);
                if ($existingBooking) {
                    error_log('IDEMPOTENCY: Booking already exists for payment intent: ' . $paymentIntentId);
                    return;
                }
            }

            // Get user and square
            $user = $userManager->get($metadata['user_id']);
            $square = $squareManager->get($metadata['square_id']);

            // Create date objects
            $dateStart = new \DateTime($metadata['ds'] . ' ' . $metadata['ts']);
            $dateEnd = new \DateTime($metadata['de'] . ' ' . $metadata['te']);

            // Create booking meta
            $meta = [];
            if (isset($metadata['player-names'])) {
                $meta['player-names'] = $metadata['player-names'];
            }
            if (isset($metadata['notes'])) {
                $meta['notes'] = $metadata['notes'];
            }

            // Add Stripe session and payment intent IDs to meta for future reference
            if ($sessionId) {
                $meta['stripe_session_id'] = $sessionId;
            }
            if ($paymentIntentId) {
                $meta['stripe_payment_intent_id'] = $paymentIntentId;
            }

            // Create the booking with paid status
            $booking = $bookingService->createSinglePaid(
                $user,
                $square,
                $metadata['quantity'],
                $dateStart,
                $dateEnd,
                [], // bills will be created by the service
                $meta
            );

            error_log('Booking created successfully: ' . $booking->need('bid') . ' for session: ' . $sessionId);
        } catch (RuntimeException $e) {
            error_log('Error creating booking: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Create booking from Payment Intent
     */
    private function createBookingFromPaymentIntent($paymentIntent)
    {
        $serviceManager = $this->getServiceLocator();
        $bookingService = $serviceManager->get('Booking\Service\BookingService');
        $userManager = $serviceManager->get('User\Manager\UserManager');
        $squareManager = $serviceManager->get('Square\Manager\SquareManager');

        try {
            $metadata = $paymentIntent->metadata;

            // Get user and square
            $user = $userManager->get($metadata['user_id']);
            $square = $squareManager->get($metadata['square_id']);

            // Create date objects from metadata
            $dateStart = new \DateTime($metadata['ds'] . ' ' . $metadata['ts']);
            $dateEnd = new \DateTime($metadata['de'] . ' ' . $metadata['te']);

            // Create booking meta
            $meta = [];
            if (isset($metadata['player-names'])) {
                $meta['player-names'] = $metadata['player-names'];
            }
            if (isset($metadata['notes'])) {
                $meta['notes'] = $metadata['notes'];
            }

            // Create the booking with paid status
            $booking = $bookingService->createSinglePaid(
                $user,
                $square,
                $metadata['quantity'],
                $dateStart,
                $dateEnd,
                [], // bills will be created by the service
                $meta
            );

            error_log('Booking created from Payment Intent: ' . $booking->need('bid'));
        } catch (RuntimeException $e) {
            error_log('Error creating booking from Payment Intent: ' . $e->getMessage());
            throw $e;
        }
    }

    /**
     * Find existing booking by Stripe session ID
     */
    private function findBookingBySessionId($sessionId)
    {
        $serviceManager = $this->getServiceLocator();
        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');

        // Get all bookings and filter for paid status manually
        $allBookings = $bookingManager->getAll();

        foreach ($allBookings as $booking) {
            try {
                // Check if booking is paid
                if ($booking->need('status_billing') !== 'paid') {
                    continue;
                }

                // Check for specific metadata key
                $stripeSessionId = $booking->getMeta('stripe_session_id', null);
                if ($stripeSessionId === $sessionId) {
                    return $booking;
                }
            } catch (Exception $e) {
                error_log('Error checking booking metadata: ' . $e->getMessage());
                continue;
            }
        }

        return null;
    }

    /**
     * Find existing booking by Stripe payment intent ID
     */
    private function findBookingByPaymentIntent($paymentIntentId)
    {
        $serviceManager = $this->getServiceLocator();
        $bookingManager = $serviceManager->get('Booking\Manager\BookingManager');

        // Get all bookings and filter for paid status manually
        $allBookings = $bookingManager->getAll();

        foreach ($allBookings as $booking) {
            try {
                // Check if booking is paid
                if ($booking->need('status_billing') !== 'paid') {
                    continue;
                }

                // Check for specific metadata key
                $stripePaymentIntentId = $booking->getMeta('stripe_payment_intent_id', null);
                if ($stripePaymentIntentId === $paymentIntentId) {
                    return $booking;
                }
            } catch (Exception $e) {
                error_log('Error checking booking metadata: ' . $e->getMessage());
                continue;
            }
        }

        return null;
    }

    /**
     * Check if webhook has already been processed
     */
    private function isWebhookProcessed($webhookId)
    {
        $serviceManager = $this->getServiceLocator();
        $optionManager = $serviceManager->get('Base\Manager\OptionManager');

        $processedWebhooks = $optionManager->get('stripe.processed_webhooks', '[]');
        $webhookArray = json_decode($processedWebhooks, true) ?: [];

        return in_array($webhookId, $webhookArray);
    }

    /**
     * Mark webhook as processed
     */
    private function markWebhookProcessed($webhookId)
    {
        $serviceManager = $this->getServiceLocator();
        $optionManager = $serviceManager->get('Base\Manager\OptionManager');

        $processedWebhooks = $optionManager->get('stripe.processed_webhooks', '[]');
        $webhookArray = json_decode($processedWebhooks, true) ?: [];

        // Add new webhook ID
        $webhookArray[] = $webhookId;

        // Keep only last 1000 webhook IDs to prevent unlimited growth
        if (count($webhookArray) > 1000) {
            $webhookArray = array_slice($webhookArray, -1000);
        }

        $optionManager->set('stripe.processed_webhooks', json_encode($webhookArray));
        error_log('Webhook marked as processed: ' . $webhookId);
    }

    /**
     * Store payment success message for user
     */
    private function storePaymentSuccessMessage($userId, $message)
    {
        if (!$userId) {
            return;
        }

        $serviceManager = $this->getServiceLocator();
        $optionManager = $serviceManager->get('Base\Manager\OptionManager');

        $key = 'stripe.payment_success_' . $userId;
        $optionManager->set($key, $message);
        error_log('Payment success message stored for user: ' . $userId);
    }

    /**
     * Get payment success message for current user
     */
    private function getPaymentSuccessMessage()
    {
        $serviceManager = $this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $optionManager = $serviceManager->get('Base\Manager\OptionManager');

        try {
            $user = $userSessionManager->getSessionUser();
            if (!$user) {
                return null;
            }

            $userId = $user->need('uid');
            $key = 'stripe.payment_success_' . $userId;

            return $optionManager->get($key, null);
        } catch (Exception $e) {
            error_log('Error getting payment success message: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Clear payment success message for current user
     */
    private function clearPaymentSuccessMessage()
    {
        $serviceManager = $this->getServiceLocator();
        $userSessionManager = $serviceManager->get('User\Manager\UserSessionManager');
        $optionManager = $serviceManager->get('Base\Manager\OptionManager');

        try {
            $user = $userSessionManager->getSessionUser();
            if (!$user) {
                return;
            }

            $userId = $user->need('uid');
            $key = 'stripe.payment_success_' . $userId;

            $optionManager->set($key, null);
            error_log('Payment success message cleared for user: ' . $userId);
        } catch (Exception $e) {
            error_log('Error clearing payment success message: ' . $e->getMessage());
        }
    }
}
