<?php

namespace Payment\Service;

use RuntimeException;
use Zend\ServiceManager\FactoryInterface;
use Zend\ServiceManager\ServiceLocatorInterface;

class StripeServiceFactory implements FactoryInterface
{
    public function createService(ServiceLocatorInterface $serviceLocator)
    {
        $config = $serviceLocator->get('config');
        $stripeConfig = $config['stripe'] ?? [];

        // Set the active secret key based on test mode
        $testMode = $stripeConfig['test_mode'] ?? true;
        $stripeConfig['secret_key'] = $testMode
            ? $stripeConfig['test_secret_key']
            : $stripeConfig['live_secret_key'];

        $stripeConfig['publishable_key'] = $testMode
            ? $stripeConfig['test_publishable_key']
            : $stripeConfig['live_publishable_key'];

        if (empty($stripeConfig['secret_key'])) {
            throw new RuntimeException('Stripe secret key is not configured');
        }

        return new StripeService($stripeConfig);
    }
}
