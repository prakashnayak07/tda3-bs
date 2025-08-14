# Sports Booking System with Stripe Payment Integration

A complete sports court booking system built with Zend Framework 2.5, featuring secure Stripe payment processing, webhook handling, and comprehensive booking management.

## 🏆 Features

### Core Booking System

- ✅ **Court/Space Booking** with real-time availability
- ✅ **User Management** with registration and authentication
- ✅ **Calendar Interface** with visual booking slots
- ✅ **Product Management** with multiple items and quantities
- ✅ **Pricing Rules** with flexible time-based pricing
- ✅ **Booking Cancellation** with policy enforcement

### Payment Integration

- ✅ **Stripe Checkout** for secure payment processing
- ✅ **Webhook Handling** for automatic booking confirmation
- ✅ **Test/Live Mode** switching
- ✅ **Price Conversion** (dollars to cents) for Stripe compatibility
- ✅ **Signature Verification** for webhook security
- ✅ **Backend Configuration** for payment management

### Security Features

- ✅ **Environment Variables** for API key management
- ✅ **Webhook Signature Verification** to prevent spoofing
- ✅ **Input Validation** and sanitization
- ✅ **Database Transactions** for data integrity
- ✅ **HTTPS Enforcement** recommendations

## 🚀 Quick Start

### Prerequisites

- PHP 7.4+ with Composer
- MySQL 5.7+
- Zend Framework 2.5
- Stripe Account (test and live)

### Installation

1. **Clone the repository**

   ```bash
   git clone <repository-url>
   cd tdasmkbs
   ```

2. **Install dependencies**

   ```bash
   composer install
   ```

3. **Set up environment**

   ```bash
   # Copy the example environment file
   cp config/stripe.env.example config/stripe.env

   # Edit with your Stripe API keys
   nano config/stripe.env
   ```

4. **Configure database**

   ```bash
   # Import the database schema
   mysql -u username -p database_name < data/db/ep3-bs.sql
   ```

5. **Set up web server**
   - Point document root to `public/`
   - Ensure mod_rewrite is enabled
   - Configure virtual host

## ⚙️ Configuration

### Stripe API Keys

Create `config/stripe.env` with your Stripe credentials:

```env
# Test Mode Keys (for development)
STRIPE_TEST_SECRET_KEY=sk_test_your_test_secret_key_here
STRIPE_TEST_PUBLISHABLE_KEY=pk_test_your_test_publishable_key_here

# Live Mode Keys (for production)
STRIPE_LIVE_SECRET_KEY=sk_live_your_live_secret_key_here
STRIPE_LIVE_PUBLISHABLE_KEY=pk_live_your_live_publishable_key_here

# Webhook Secret (from Stripe Dashboard)
STRIPE_WEBHOOK_SECRET=whsec_your_webhook_secret_here
```

### Backend Configuration

1. **Access admin panel**: `http://your-domain.com/backend`
2. **Go to Configuration > Payment**
3. **Enable Stripe Payments**
4. **Select Test Mode** for development

### Webhook Setup

1. **In Stripe Dashboard**:

   - Go to **Developers > Webhooks**
   - Click **Add endpoint**
   - URL: `https://your-domain.com/payment/webhook`
   - Events: Select `checkout.session.completed`

2. **Copy webhook secret** to `config/stripe.env`

## 📁 Project Structure

```
tdasmkbs/
├── config/
│   ├── stripe.env              # Stripe API keys (not in Git)
│   ├── stripe.env.example      # Template for setup
│   ├── load_env.php           # Environment loading
│   └── autoload/global.php    # Global configuration
├── modulex/Payment/           # Payment module
│   ├── Module.php
│   ├── config/module.config.php
│   └── src/Payment/
│       ├── Controller/StripeController.php
│       └── Service/StripeService.php
├── module/
│   ├── Backend/              # Admin interface
│   ├── Booking/              # Booking management
│   ├── Square/               # Court/space management
│   └── User/                 # User management
├── public/                   # Web root
└── vendor/                   # Composer dependencies
```

## 🔧 Payment Flow

### 1. User Booking Process

```
User selects court/time → Customization → Confirmation → Stripe Checkout → Payment Success
```

### 2. Payment Processing

```
Stripe Checkout → Payment Success → Webhook → Booking Creation → Calendar Update
```

### 3. Webhook Security

```
Webhook Received → Signature Verification → Event Processing → Booking Creation
```

## 🧪 Testing

### Test Payment Flow

1. **Enable test mode** in backend configuration
2. **Make a test booking** through frontend
3. **Use test card**: `4242 4242 4242 4242`
4. **Verify booking** appears in calendar

### Test Webhook

```bash
# Install Stripe CLI
stripe listen --forward-to localhost/payment/webhook

# In another terminal, trigger test event
stripe trigger checkout.session.completed
```

### Debug Commands

```bash
# Check configuration
php -r "require 'config/load_env.php'; echo getenv('STRIPE_TEST_SECRET_KEY') ? 'OK' : 'NOT SET';"

# Check error logs
tail -f /path/to/error.log

# Verify routes
php public/index.php route:list | grep payment
```

## 🔒 Security

### Implemented Security Features

- ✅ **Environment variables** for API keys
- ✅ **Webhook signature verification**
- ✅ **Input validation** and sanitization
- ✅ **Database transactions**
- ✅ **Error logging** without exposing secrets

### Security Checklist

- [ ] API keys in environment variables
- [ ] Webhook signature verification enabled
- [ ] HTTPS enforced in production
- [ ] Error logging configured
- [ ] Input validation implemented
- [ ] Database transactions used
- [ ] Secrets not committed to Git

## 🐛 Troubleshooting

### Common Issues

#### "Stripe secret key is not configured"

- Check `config/stripe.env` exists
- Verify API keys are correct
- Ensure `config/load_env.php` is loaded

#### "Payment setup failed"

- Check Stripe API keys
- Verify webhook URL is accessible
- Check error logs

#### "Webhook signature verification failed"

- Verify webhook secret in `stripe.env`
- Check webhook endpoint URL
- Ensure HTTPS in production

#### "Booking not created after payment"

- Check webhook is receiving events
- Verify `createSinglePaid` method exists
- Check database connection

### Debug Steps

1. **Check error logs**: `tail -f /path/to/error.log`
2. **Verify configuration**: Check all environment variables
3. **Test webhook**: Use Stripe CLI for local testing
4. **Check database**: Verify booking tables exist
5. **Monitor network**: Check webhook delivery in Stripe Dashboard

## 📚 API Reference

### Payment Endpoints

- `POST /payment/webhook` - Stripe webhook handler
- `GET /payment/success` - Payment success callback
- `GET /payment/cancel` - Payment cancellation callback

### Backend Configuration

- `GET /backend/config/payment` - Payment settings page
- `POST /backend/config/payment` - Save payment settings

### Booking Endpoints

- `GET /square/booking/customization` - Booking customization
- `GET /square/booking/confirmation` - Booking confirmation
- `POST /square/booking/confirmation` - Process booking

## 🔄 Version History

### v1.0.0 - Initial Release

- ✅ Basic booking system
- ✅ Stripe payment integration
- ✅ Webhook handling
- ✅ Backend configuration
- ✅ Security features

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Add tests if applicable
5. Submit a pull request

## 📄 License

This project is licensed under the MIT License - see the LICENSE file for details.

## 🆘 Support

For support and questions:

- Check the troubleshooting section
- Review error logs
- Contact the development team
- Create an issue on GitHub

## 🔗 Links

- [Stripe Documentation](https://stripe.com/docs)
- [Zend Framework Documentation](https://docs.zendframework.com/)
- [Composer Documentation](https://getcomposer.org/doc/)

---

**Built with ❤️ using Zend Framework 2.5 and Stripe**
