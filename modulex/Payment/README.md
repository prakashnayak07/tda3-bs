# Payment Module

This module handles Stripe payment integration for the sports booking system.

## Features

- ✅ Stripe Checkout integration
- ✅ Webhook handling for payment confirmation
- ✅ Product quantity handling
- ✅ Gross/Net pricing support
- ✅ Tax calculation and handling
- ✅ Session-based payment processing

## Structure

```
modulex/Payment/
├── Module.php                    # Module definition
├── config/
│   └── module.config.php        # Module configuration
├── src/Payment/
│   ├── Controller/
│   │   └── StripeController.php # Payment controller
│   └── Service/
│       ├── StripeService.php    # Stripe API service
│       └── StripeServiceFactory.php # Service factory
└── README.md                    # This file
```

## Configuration

The module requires Stripe API keys to be configured in `config/stripe.env`:

```env
STRIPE_TEST_SECRET_KEY=sk_test_...
STRIPE_TEST_PUBLISHABLE_KEY=pk_test_...
STRIPE_LIVE_SECRET_KEY=sk_live_...
STRIPE_LIVE_PUBLISHABLE_KEY=pk_live_...
STRIPE_WEBHOOK_SECRET=whsec_...
```

## Usage

1. Enable Stripe in backend configuration
2. Configure API keys in `config/stripe.env`
3. Set up webhook endpoint in Stripe dashboard
4. Test payment flow

## Endpoints

- `POST /payment/webhook` - Stripe webhook handler
- `GET /payment/success` - Payment success callback
- `GET /payment/cancel` - Payment cancellation callback

## Security

- Webhook signature verification
- Environment variable configuration
- Input validation and sanitization
- Error logging without exposing secrets
