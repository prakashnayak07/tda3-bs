<?php

return array(
    'controllers' => array(
        'invokables' => array(
            'Payment\Controller\Stripe' => 'Payment\Controller\StripeController',
        ),
    ),

    'service_manager' => array(
        'factories' => array(
            'Payment\Service\StripeService' => 'Payment\Service\StripeServiceFactory',
        ),
    ),

    'router' => array(
        'routes' => array(
            'payment' => array(
                'type' => 'Literal',
                'options' => array(
                    'route' => '/payment',
                    'defaults' => array(
                        'controller' => 'Payment\Controller\Stripe',
                        'action' => 'index',
                    ),
                ),
                'child_routes' => array(
                    'success' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/success',
                            'defaults' => array(
                                'action' => 'success',
                            ),
                        ),
                    ),
                    'cancel' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/cancel',
                            'defaults' => array(
                                'action' => 'cancel',
                            ),
                        ),
                    ),
                    'webhook' => array(
                        'type' => 'Literal',
                        'options' => array(
                            'route' => '/webhook',
                            'defaults' => array(
                                'action' => 'webhook',
                            ),
                        ),
                    ),
                ),
            ),
        ),
    ),

    'view_manager' => array(
        'template_path_stack' => array(
            __DIR__ . '/../view',
        ),
    ),
);
