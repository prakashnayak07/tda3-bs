<?php

namespace Payment\Controller;

use RuntimeException;
use Zend\Mvc\Controller\AbstractActionController;
use Zend\Session\Container;

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

        if ($sessionId) {
            $serviceManager = $this->getServiceLocator();
            $stripeService = $serviceManager->get('Payment\Service\StripeService');

            try {
                $session = $stripeService->getCheckoutSession($sessionId);
                error_log('Payment status: ' . ($session->payment_status ?? 'NOT FOUND'));

                if ($session->payment_status === 'paid') {
                    error_log('Payment confirmed as paid!');

                    // Create the booking from session data
                    $this->createBookingFromSession($session);

                    $this->flashMessenger()->addSuccessMessage(
                        $this->t('Payment successful! Your booking has been confirmed.')
                    );
                } else {
                    error_log('Payment not confirmed: ' . $session->payment_status);
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

        try {
            // Verify webhook signature for security
            $event = $stripeService->verifyWebhookSignature($payload, $signature);
            error_log('Event type: ' . ($event['type'] ?? 'UNKNOWN'));

            if ($event['type'] === 'checkout.session.completed') {
                $session = $event['data']['object'];

                if ($session['payment_status'] === 'paid') {
                    error_log('Webhook: Payment confirmed as paid!');
                    $this->createBookingFromSession($session);
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

            error_log('Booking created successfully: ' . $booking->need('bid'));
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
}
