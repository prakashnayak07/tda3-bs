# Quick Setup Guide - Stripe Payment Integration

## 🚀 5-Minute Setup

### Step 1: Install Dependencies

```bash
composer install
```

### Step 2: Configure Stripe

```bash
# Copy the example file
cp config/stripe.env.example config/stripe.env

# Edit with your Stripe API keys
nano config/stripe.env
```

### Step 3: Enable Payments

1. Go to `http://your-domain.com/backend`
2. Navigate to **Configuration > Payment**
3. Check **Enable Stripe Payments**
4. Check **Test Mode**
5. Click **Save**

### Step 4: Test Payment

1. Make a test booking
2. Use test card: `4242 4242 4242 4242`
3. Verify booking appears in calendar

## 🔧 Required Stripe Setup

### Get API Keys

1. Go to [Stripe Dashboard](https://dashboard.stripe.com/)
2. Navigate to **Developers > API keys**
3. Copy **Publishable key** and **Secret key**
4. Add to `config/stripe.env`

### Setup Webhook (Production)

1. Go to **Developers > Webhooks**
2. Click **Add endpoint**
3. URL: `https://your-domain.com/payment/webhook`
4. Events: Select `checkout.session.completed`
5. Copy webhook secret to `config/stripe.env`

## ✅ Verification Checklist

- [ ] Composer dependencies installed
- [ ] `config/stripe.env` created with API keys
- [ ] Stripe enabled in backend configuration
- [ ] Test payment works
- [ ] Booking appears in calendar after payment
- [ ] Webhook configured (for production)

## 🆘 Quick Troubleshooting

**Payment not working?**

- Check API keys in `config/stripe.env`
- Verify Stripe is enabled in backend
- Check error logs

**Booking not created?**

- Verify webhook is configured
- Check webhook secret in `config/stripe.env`
- Test webhook with Stripe CLI

**Need help?**

- Check the main README.md
- Review error logs
- Contact support team
