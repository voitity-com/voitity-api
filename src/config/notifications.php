<?php

return [
    'preferences' => [
        'product_updates' => [
            'channel' => 'email',
            'default_enabled' => true,
        ],
        'security_updates' => [
            'channel' => 'email',
            'default_enabled' => false,
        ],
    ],

    'types' => [
        'account_email_confirmation' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'account',
            'kind' => 'log',
            'visible_in_bell' => false,
            'action_url' => '/auth/custom/sign-in',
            'copy' => [
                'en' => [
                    'subject' => 'Confirm your Bigmelo email',
                    'title' => 'Confirm your email',
                    'body' => 'We sent a verification link to :email. Confirm it before signing in.',
                    'action' => 'Open sign in',
                ],
                'es' => [
                    'subject' => 'Confirma tu correo en Bigmelo',
                    'title' => 'Confirma tu correo',
                    'body' => 'Enviamos un enlace de verificación a :email. Confírmalo antes de iniciar sesión.',
                    'action' => 'Abrir inicio de sesión',
                ],
            ],
        ],
        'welcome_after_email_verification' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'account',
            'kind' => 'log',
            'visible_in_bell' => false,
            'action_url' => '/dashboard',
            'copy' => [
                'en' => [
                    'subject' => 'Welcome to Bigmelo',
                    'title' => 'Your account is confirmed',
                    'body' => 'You can now create profiles with voice, avatar, and verified sources.',
                    'action' => 'Open dashboard',
                ],
                'es' => [
                    'subject' => 'Bienvenido a Bigmelo',
                    'title' => 'Tu cuenta está confirmada',
                    'body' => 'Ya puedes crear perfiles con voz, avatar y fuentes verificadas.',
                    'action' => 'Abrir administrador',
                ],
            ],
        ],
        'password_recovery_link' => [
            'email' => true,
            'app' => false,
            'mandatory' => true,
            'category' => 'security',
            'copy' => [
                'en' => [
                    'subject' => 'Reset your Bigmelo password',
                    'title' => 'Reset your password',
                    'body' => 'We sent a secure link to change your password.',
                ],
                'es' => [
                    'subject' => 'Restablece tu contraseña de Bigmelo',
                    'title' => 'Restablece tu contraseña',
                    'body' => 'Enviamos un enlace seguro para cambiar tu contraseña.',
                ],
            ],
        ],
        'password_changed_confirmation' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'security',
            'action_url' => '/dashboard/settings/security',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo password was updated',
                    'title' => 'Password updated',
                    'body' => 'Your password was updated successfully. Contact support if you did not make this change.',
                    'action' => 'Review security',
                ],
                'es' => [
                    'subject' => 'Tu contraseña de Bigmelo fue actualizada',
                    'title' => 'Contraseña actualizada',
                    'body' => 'Tu contraseña fue actualizada correctamente. Contacta a soporte si no hiciste este cambio.',
                    'action' => 'Revisar seguridad',
                ],
            ],
        ],
        'password_recovery_requested_for_google_account' => [
            'email' => false,
            'app' => true,
            'category' => 'security',
            'action_url' => '/auth/custom/sign-in',
            'copy' => [
                'en' => [
                    'title' => 'Use Google sign-in',
                    'body' => 'A password recovery was requested for this Google account. Use the Google button to continue.',
                    'action' => 'Open sign in',
                ],
                'es' => [
                    'title' => 'Usa ingreso con Google',
                    'body' => 'Se solicitó recuperación de contraseña para esta cuenta de Google. Usa el botón de Google para continuar.',
                    'action' => 'Abrir inicio de sesión',
                ],
            ],
        ],
        'account_email_changed' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'security',
            'action_url' => '/dashboard/settings/account',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo email was changed',
                    'title' => 'Email changed',
                    'body' => 'Your account email was changed to :email.',
                    'action' => 'Review account',
                ],
                'es' => [
                    'subject' => 'Tu correo de Bigmelo fue cambiado',
                    'title' => 'Correo cambiado',
                    'body' => 'El correo de tu cuenta fue cambiado a :email.',
                    'action' => 'Revisar cuenta',
                ],
            ],
        ],
        'password_changed_from_active_session' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'security',
            'action_url' => '/dashboard/settings/security',
            'copy' => [
                'en' => [
                    'subject' => 'Password changed from your Bigmelo session',
                    'title' => 'Password changed',
                    'body' => 'Your password was changed from an active session.',
                    'action' => 'Review security',
                ],
                'es' => [
                    'subject' => 'Contraseña cambiada desde tu sesión de Bigmelo',
                    'title' => 'Contraseña cambiada',
                    'body' => 'Tu contraseña fue cambiada desde una sesión activa.',
                    'action' => 'Revisar seguridad',
                ],
            ],
        ],
        'successful_plan_purchase' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo plan purchase was approved',
                    'title' => 'Plan purchased',
                    'body' => 'Your :plan plan purchase was approved for :amount.',
                    'action' => 'View billing',
                ],
                'es' => [
                    'subject' => 'Tu compra de plan Bigmelo fue aprobada',
                    'title' => 'Plan comprado',
                    'body' => 'Tu compra del plan :plan fue aprobada por :amount.',
                    'action' => 'Ver facturación',
                ],
            ],
        ],
        'credits_purchased' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo credits are available',
                    'title' => 'Credits purchased',
                    'body' => ':credits credits were added to your account for :amount.',
                    'action' => 'View billing',
                ],
                'es' => [
                    'subject' => 'Tus créditos de Bigmelo están disponibles',
                    'title' => 'Créditos comprados',
                    'body' => 'Se agregaron :credits créditos a tu cuenta por :amount.',
                    'action' => 'Ver facturación',
                ],
            ],
        ],
        'credits_reversed' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'A Bigmelo credit payment was reversed',
                    'title' => 'Credit payment reversed',
                    'body' => 'The payment for :credits credits was reversed. Unused credits were removed and your billing balance was updated.',
                    'action' => 'View billing',
                ],
                'es' => [
                    'subject' => 'Un pago de créditos Bigmelo fue reversado',
                    'title' => 'Pago de créditos reversado',
                    'body' => 'El pago de :credits créditos fue reversado. Se retiraron los créditos no utilizados y se actualizó tu saldo de facturación.',
                    'action' => 'Ver facturación',
                ],
            ],
        ],
        'failed_payment' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo payment failed',
                    'title' => 'Payment failed',
                    'body' => 'The payment for :plan was not approved. You can try again from billing.',
                    'action' => 'Open billing',
                ],
                'es' => [
                    'subject' => 'Tu pago de Bigmelo falló',
                    'title' => 'Pago fallido',
                    'body' => 'El pago del plan :plan no fue aprobado. Puedes intentarlo de nuevo desde facturación.',
                    'action' => 'Abrir facturación',
                ],
            ],
        ],
        'successful_subscription_renewal' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo subscription renewed',
                    'title' => 'Subscription renewed',
                    'body' => 'Your :plan subscription renewed successfully.',
                    'action' => 'View billing',
                ],
                'es' => [
                    'subject' => 'Tu suscripción de Bigmelo fue renovada',
                    'title' => 'Suscripción renovada',
                    'body' => 'Tu suscripción :plan fue renovada correctamente.',
                    'action' => 'Ver facturación',
                ],
            ],
        ],
        'failed_subscription_renewal' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo subscription renewal failed',
                    'title' => 'Renewal failed',
                    'body' => 'We could not renew your :plan subscription. Update your payment method to avoid losing access.',
                    'action' => 'Open billing',
                ],
                'es' => [
                    'subject' => 'La renovación de tu suscripción de Bigmelo falló',
                    'title' => 'Renovación fallida',
                    'body' => 'No pudimos renovar tu suscripción :plan. Actualiza tu método de pago para evitar perder acceso.',
                    'action' => 'Abrir facturación',
                ],
            ],
        ],
        'subscription_renewal_reminder' => [
            'email' => true,
            'app' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo subscription renews soon',
                    'title' => 'Subscription renews soon',
                    'body' => 'Your :plan subscription renews on :renews_at.',
                    'action' => 'View billing',
                ],
                'es' => [
                    'subject' => 'Tu suscripción de Bigmelo se renovará pronto',
                    'title' => 'Suscripción próxima a renovar',
                    'body' => 'Tu suscripción :plan se renovará el :renews_at.',
                    'action' => 'Ver facturación',
                ],
            ],
        ],
        'trial_started' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo free trial started',
                    'title' => 'Free trial started',
                    'body' => 'Your :plan trial is active. You will be charged automatically when the trial ends unless you cancel first.',
                    'action' => 'View billing',
                ],
                'es' => [
                    'subject' => 'Tu prueba gratis de Bigmelo comenzó',
                    'title' => 'Prueba gratis iniciada',
                    'body' => 'Tu prueba del plan :plan está activa. Se cobrará automáticamente cuando termine la prueba, salvo que canceles antes.',
                    'action' => 'Ver facturación',
                ],
            ],
        ],
        'trial_cancelled' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo trial was cancelled',
                    'title' => 'Trial cancellation scheduled',
                    'body' => 'Your :plan trial will stay active until :trial_ends_at. No automatic charge will be made.',
                    'action' => 'View billing',
                ],
                'es' => [
                    'subject' => 'Tu prueba de Bigmelo fue cancelada',
                    'title' => 'Cancelación de prueba programada',
                    'body' => 'Tu prueba del plan :plan seguirá activa hasta :trial_ends_at. No se hará el cobro automático.',
                    'action' => 'Ver facturación',
                ],
            ],
        ],
        'trial_converted_to_paid' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo plan is now active',
                    'title' => 'Trial converted to plan',
                    'body' => 'Your :plan trial converted to a paid subscription for :amount. Your usage limits were reset.',
                    'action' => 'View billing',
                ],
                'es' => [
                    'subject' => 'Tu plan de Bigmelo ya está activo',
                    'title' => 'Prueba convertida a plan',
                    'body' => 'Tu prueba del plan :plan pasó a suscripción pagada por :amount. Tus límites de uso se reiniciaron.',
                    'action' => 'Ver facturación',
                ],
            ],
        ],
        'trial_payment_failed' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo trial payment failed',
                    'title' => 'Trial payment failed',
                    'body' => 'We could not activate the paid :plan subscription after the trial. Start checkout again from billing.',
                    'action' => 'Open billing',
                ],
                'es' => [
                    'subject' => 'El pago de tu prueba de Bigmelo falló',
                    'title' => 'Pago de prueba fallido',
                    'body' => 'No pudimos activar la suscripción pagada :plan después de la prueba. Inicia el checkout de nuevo desde facturación.',
                    'action' => 'Abrir facturación',
                ],
            ],
        ],
        'subscription_renewal_cancelled' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo renewal was cancelled',
                    'title' => 'Renewal cancelled',
                    'body' => 'Your :plan subscription will stay active until :renews_at and will not renew automatically.',
                    'action' => 'View billing',
                ],
                'es' => [
                    'subject' => 'La renovación de Bigmelo fue cancelada',
                    'title' => 'Renovación cancelada',
                    'body' => 'Tu suscripción :plan seguirá activa hasta :renews_at y no se renovará automáticamente.',
                    'action' => 'Ver facturación',
                ],
            ],
        ],
        'subscription_renewal_reactivated' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo renewal was reactivated',
                    'title' => 'Renewal reactivated',
                    'body' => 'Automatic renewal is active again for your :plan subscription.',
                    'action' => 'View billing',
                ],
                'es' => [
                    'subject' => 'La renovación de Bigmelo fue reactivada',
                    'title' => 'Renovación reactivada',
                    'body' => 'La renovación automática volvió a quedar activa para tu suscripción :plan.',
                    'action' => 'Ver facturación',
                ],
            ],
        ],
        'subscription_expired' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo subscription ended',
                    'title' => 'Subscription ended',
                    'body' => 'Your :plan subscription ended and your published profiles were deactivated. You can start a new checkout from billing.',
                    'action' => 'Open billing',
                ],
                'es' => [
                    'subject' => 'Tu suscripción de Bigmelo terminó',
                    'title' => 'Suscripción finalizada',
                    'body' => 'Tu suscripción :plan terminó y tus perfiles publicados fueron desactivados. Puedes iniciar un nuevo checkout desde facturación.',
                    'action' => 'Abrir facturación',
                ],
            ],
        ],
        'admin_changed_user_plan' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo plan was changed',
                    'title' => 'Plan changed',
                    'body' => 'An administrator changed your plan to :plan.',
                    'action' => 'View billing',
                ],
                'es' => [
                    'subject' => 'Tu plan de Bigmelo fue cambiado',
                    'title' => 'Plan cambiado',
                    'body' => 'Un administrador cambió tu plan a :plan.',
                    'action' => 'Ver facturación',
                ],
            ],
        ],
        'subscription_cancelled_or_deactivated' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo subscription changed',
                    'title' => 'Subscription deactivated',
                    'body' => 'Your subscription was cancelled or deactivated. Some features may no longer be available.',
                    'action' => 'Open billing',
                ],
                'es' => [
                    'subject' => 'Tu suscripción de Bigmelo cambió',
                    'title' => 'Suscripción desactivada',
                    'body' => 'Tu suscripción fue cancelada o desactivada. Algunas funciones podrían dejar de estar disponibles.',
                    'action' => 'Abrir facturación',
                ],
            ],
        ],
        'critical_plan_limit_reached' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'usage',
            'action_url' => '/dashboard/analytics',
            'copy' => [
                'en' => [
                    'subject' => 'A Bigmelo plan limit was reached',
                    'title' => 'Plan limit reached',
                    'body' => 'The :metric limit was reached and this action is blocked.',
                    'action' => 'View usage',
                ],
                'es' => [
                    'subject' => 'Alcanzaste un límite de tu plan Bigmelo',
                    'title' => 'Límite alcanzado',
                    'body' => 'El límite de :metric fue alcanzado y esta acción está bloqueada.',
                    'action' => 'Ver uso',
                ],
            ],
        ],
        'monthly_usage_summary' => [
            'email' => true,
            'app' => true,
            'category' => 'usage',
            'action_url' => '/dashboard/analytics',
            'copy' => [
                'en' => [
                    'subject' => 'Your Bigmelo monthly usage summary',
                    'title' => 'Monthly usage summary',
                    'body' => 'Your monthly usage summary is ready.',
                    'action' => 'View usage',
                ],
                'es' => [
                    'subject' => 'Resumen mensual de uso de Bigmelo',
                    'title' => 'Resumen mensual de uso',
                    'body' => 'Tu resumen mensual de uso está listo.',
                    'action' => 'Ver uso',
                ],
            ],
        ],
        'critical_admin_alert' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'admin',
            'action_url' => '/dashboard/users',
            'copy' => [
                'en' => [
                    'subject' => 'Critical Bigmelo admin alert',
                    'title' => 'Critical admin alert',
                    'body' => ':message',
                    'action' => 'Open admin',
                ],
                'es' => [
                    'subject' => 'Alerta crítica de administración Bigmelo',
                    'title' => 'Alerta crítica de administración',
                    'body' => ':message',
                    'action' => 'Abrir admin',
                ],
            ],
        ],
        'profile_created' => [
            'email' => false,
            'app' => true,
            'category' => 'profile',
            'kind' => 'log',
            'visible_in_bell' => false,
            'action_url' => '/dashboard/profiles',
            'copy' => [
                'en' => ['title' => 'Profile created', 'body' => 'Profile :profile was created.'],
                'es' => ['title' => 'Perfil creado', 'body' => 'El perfil :profile fue creado.'],
            ],
        ],
        'profile_updated' => [
            'email' => false,
            'app' => true,
            'category' => 'profile',
            'kind' => 'log',
            'visible_in_bell' => false,
            'copy' => [
                'en' => ['title' => 'Profile updated', 'body' => 'Profile :profile was updated.'],
                'es' => ['title' => 'Perfil actualizado', 'body' => 'El perfil :profile fue actualizado.'],
            ],
        ],
        'profile_activated_or_published' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'profile',
            'copy' => [
                'en' => ['subject' => 'Your Bigmelo profile is live', 'title' => 'Profile published', 'body' => ':profile is active and published.', 'action' => 'Open profile'],
                'es' => ['subject' => 'Tu perfil de Bigmelo está publicado', 'title' => 'Perfil publicado', 'body' => ':profile está activo y publicado.', 'action' => 'Abrir perfil'],
            ],
        ],
        'profile_deactivated' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'profile',
            'copy' => [
                'en' => ['subject' => 'Your Bigmelo profile was deactivated', 'title' => 'Profile deactivated', 'body' => ':profile is no longer public.'],
                'es' => ['subject' => 'Tu perfil de Bigmelo fue desactivado', 'title' => 'Perfil desactivado', 'body' => ':profile ya no está público.'],
            ],
        ],
        'profile_activation_requirements_missing' => [
            'email' => false,
            'app' => true,
            'category' => 'profile',
            'copy' => [
                'en' => ['title' => 'Profile cannot be published', 'body' => ':profile is missing required items: :requirements.'],
                'es' => ['title' => 'El perfil no se puede publicar', 'body' => ':profile tiene requisitos pendientes: :requirements.'],
            ],
        ],
        'missing_avatar_required_to_publish_profile' => [
            'email' => false,
            'app' => true,
            'category' => 'profile',
            'copy' => [
                'en' => ['title' => 'Avatar required', 'body' => ':profile needs an avatar before publishing.'],
                'es' => ['title' => 'Avatar requerido', 'body' => ':profile necesita un avatar antes de publicarse.'],
            ],
        ],
        'missing_cloned_voice_required_to_publish_profile' => [
            'email' => false,
            'app' => true,
            'category' => 'profile',
            'copy' => [
                'en' => ['title' => 'Cloned voice required', 'body' => ':profile needs a cloned voice before publishing.'],
                'es' => ['title' => 'Voz clonada requerida', 'body' => ':profile necesita una voz clonada antes de publicarse.'],
            ],
        ],
        'missing_approved_synchronized_source_required_to_publish_profile' => [
            'email' => false,
            'app' => true,
            'category' => 'profile',
            'copy' => [
                'en' => ['title' => 'Approved source required', 'body' => ':profile needs at least one approved and synchronized source.'],
                'es' => ['title' => 'Fuente aprobada requerida', 'body' => ':profile necesita al menos una fuente aprobada y sincronizada.'],
            ],
        ],
        'missing_profile_alias_name_or_description' => [
            'email' => false,
            'app' => true,
            'category' => 'profile',
            'copy' => [
                'en' => ['title' => 'Profile details required', 'body' => ':profile needs alias, name, and description before publishing.'],
                'es' => ['title' => 'Datos del perfil requeridos', 'body' => ':profile necesita alias, nombre y descripción antes de publicarse.'],
            ],
        ],
        'profile_quality_incomplete' => [
            'email' => false,
            'app' => true,
            'category' => 'profile',
            'copy' => [
                'en' => ['title' => 'Profile quality incomplete', 'body' => ':profile still has incomplete quality checks.'],
                'es' => ['title' => 'Calidad del perfil incompleta', 'body' => ':profile aún tiene validaciones de calidad pendientes.'],
            ],
        ],
        'source_uploaded' => [
            'email' => false,
            'app' => true,
            'category' => 'source',
            'kind' => 'log',
            'visible_in_bell' => false,
            'copy' => [
                'en' => ['title' => 'Source uploaded', 'body' => ':source was uploaded to :profile.'],
                'es' => ['title' => 'Fuente cargada', 'body' => ':source fue cargada en :profile.'],
            ],
        ],
        'source_processing_started' => [
            'email' => false,
            'app' => true,
            'category' => 'source',
            'kind' => 'log',
            'visible_in_bell' => false,
            'copy' => [
                'en' => ['title' => 'Source processing started', 'body' => 'We started processing :source.'],
                'es' => ['title' => 'Procesamiento iniciado', 'body' => 'Empezamos a procesar :source.'],
            ],
        ],
        'source_approved' => [
            'email' => false,
            'app' => true,
            'category' => 'source',
            'kind' => 'log',
            'visible_in_bell' => false,
            'copy' => [
                'en' => ['title' => 'Source approved', 'body' => ':source was approved.'],
                'es' => ['title' => 'Fuente aprobada', 'body' => ':source fue aprobada.'],
            ],
        ],
        'source_synchronized' => [
            'email' => false,
            'app' => true,
            'category' => 'source',
            'kind' => 'log',
            'visible_in_bell' => false,
            'copy' => [
                'en' => ['title' => 'Source synchronized', 'body' => ':source is now synchronized with :profile.'],
                'es' => ['title' => 'Fuente sincronizada', 'body' => ':source ya está sincronizada con :profile.'],
            ],
        ],
        'source_rejected_or_failed' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'source',
            'copy' => [
                'en' => ['subject' => 'A Bigmelo source could not be used', 'title' => 'Source failed', 'body' => ':source could not be processed. :reason'],
                'es' => ['subject' => 'Una fuente de Bigmelo no se pudo usar', 'title' => 'Fuente fallida', 'body' => ':source no se pudo procesar. :reason'],
            ],
        ],
        'source_data_extracted_ready_to_review' => [
            'email' => false,
            'app' => true,
            'category' => 'source',
            'kind' => 'log',
            'visible_in_bell' => false,
            'copy' => [
                'en' => ['title' => 'Source data ready to review', 'body' => 'Extracted data from :source is ready for review.'],
                'es' => ['title' => 'Datos listos para revisar', 'body' => 'Los datos extraídos de :source están listos para revisión.'],
            ],
        ],
        'ai_suggested_profile_changes_ready_to_approve' => [
            'email' => false,
            'app' => true,
            'category' => 'profile',
            'copy' => [
                'en' => ['title' => 'AI suggestions ready', 'body' => 'AI-suggested changes for :profile are ready to approve.'],
                'es' => ['title' => 'Sugerencias de IA listas', 'body' => 'Los cambios sugeridos por IA para :profile están listos para aprobación.'],
            ],
        ],
        'avatar_generation_started' => [
            'email' => false,
            'app' => true,
            'category' => 'avatar',
            'kind' => 'log',
            'visible_in_bell' => false,
            'copy' => [
                'en' => ['title' => 'Avatar generation started', 'body' => 'Avatar generation started for :profile.'],
                'es' => ['title' => 'Generación de avatar iniciada', 'body' => 'La generación de avatar inició para :profile.'],
            ],
        ],
        'avatar_generated_successfully' => [
            'email' => false,
            'app' => true,
            'category' => 'avatar',
            'copy' => [
                'en' => ['title' => 'Avatar generated', 'body' => 'A new avatar for :profile is ready.'],
                'es' => ['title' => 'Avatar generado', 'body' => 'Un nuevo avatar para :profile está listo.'],
            ],
        ],
        'avatar_generation_failed' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'avatar',
            'copy' => [
                'en' => ['subject' => 'Bigmelo avatar generation failed', 'title' => 'Avatar generation failed', 'body' => 'Avatar generation for :profile failed. :reason'],
                'es' => ['subject' => 'Falló la generación de avatar en Bigmelo', 'title' => 'Generación de avatar fallida', 'body' => 'La generación de avatar para :profile falló. :reason'],
            ],
        ],
        'avatar_activated' => [
            'email' => false,
            'app' => true,
            'category' => 'avatar',
            'kind' => 'log',
            'visible_in_bell' => false,
            'copy' => [
                'en' => ['title' => 'Avatar activated', 'body' => 'The avatar for :profile was activated.'],
                'es' => ['title' => 'Avatar activado', 'body' => 'El avatar de :profile fue activado.'],
            ],
        ],
        'voice_cloning_started' => [
            'email' => false,
            'app' => true,
            'category' => 'voice',
            'kind' => 'log',
            'visible_in_bell' => false,
            'copy' => [
                'en' => ['title' => 'Voice cloning started', 'body' => 'Voice cloning started for :profile.'],
                'es' => ['title' => 'Clonación de voz iniciada', 'body' => 'La clonación de voz inició para :profile.'],
            ],
        ],
        'voice_cloned_successfully' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'voice',
            'copy' => [
                'en' => ['subject' => 'Your Bigmelo voice is ready', 'title' => 'Voice cloned', 'body' => 'The voice for :profile was cloned successfully.'],
                'es' => ['subject' => 'Tu voz de Bigmelo está lista', 'title' => 'Voz clonada', 'body' => 'La voz para :profile fue clonada correctamente.'],
            ],
        ],
        'voice_cloning_failed' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'voice',
            'copy' => [
                'en' => ['subject' => 'Bigmelo voice cloning failed', 'title' => 'Voice cloning failed', 'body' => 'Voice cloning for :profile failed. :reason'],
                'es' => ['subject' => 'Falló la clonación de voz en Bigmelo', 'title' => 'Clonación de voz fallida', 'body' => 'La clonación de voz para :profile falló. :reason'],
            ],
        ],
        'new_chat_received' => [
            'email' => false,
            'app' => true,
            'category' => 'chat',
            'copy' => [
                'en' => ['title' => 'New chat received', 'body' => ':profile received a new chat.'],
                'es' => ['title' => 'Nuevo chat recibido', 'body' => ':profile recibió un nuevo chat.'],
            ],
        ],
        'new_visitor_message_received' => [
            'email' => false,
            'app' => true,
            'category' => 'chat',
            'copy' => [
                'en' => ['title' => 'New visitor message', 'body' => ':profile received a new visitor message.'],
                'es' => ['title' => 'Nuevo mensaje de visitante', 'body' => ':profile recibió un nuevo mensaje de visitante.'],
            ],
        ],
        'ai_response_failed' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'chat',
            'copy' => [
                'en' => ['subject' => 'Bigmelo AI response failed', 'title' => 'AI response failed', 'body' => 'An AI response failed for :profile. :reason'],
                'es' => ['subject' => 'Falló una respuesta de IA en Bigmelo', 'title' => 'Respuesta de IA fallida', 'body' => 'Una respuesta de IA falló para :profile. :reason'],
            ],
        ],
        'audio_response_generation_failed' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'voice',
            'copy' => [
                'en' => ['subject' => 'Bigmelo audio response failed', 'title' => 'Audio response failed', 'body' => 'Audio response generation failed for :profile. :reason'],
                'es' => ['subject' => 'Falló una respuesta de audio en Bigmelo', 'title' => 'Respuesta de audio fallida', 'body' => 'La generación de audio falló para :profile. :reason'],
            ],
        ],
        'plan_usage_updated' => [
            'email' => false,
            'app' => true,
            'category' => 'usage',
            'kind' => 'log',
            'visible_in_bell' => false,
            'action_url' => '/dashboard/analytics',
            'copy' => [
                'en' => ['title' => 'Usage updated', 'body' => 'Your plan usage was updated.'],
                'es' => ['title' => 'Uso actualizado', 'body' => 'El uso de tu plan fue actualizado.'],
            ],
        ],
        'profile_limit_reached' => [
            'email' => false,
            'app' => true,
            'category' => 'usage',
            'copy' => [
                'en' => ['title' => 'Profile limit reached', 'body' => 'Your plan does not allow another profile.'],
                'es' => ['title' => 'Límite de perfiles alcanzado', 'body' => 'Tu plan no permite crear otro perfil.'],
            ],
        ],
        'voice_limit_reached' => [
            'email' => false,
            'app' => true,
            'category' => 'usage',
            'copy' => [
                'en' => ['title' => 'Voice limit reached', 'body' => 'Your plan does not allow another voice clone.'],
                'es' => ['title' => 'Límite de voz alcanzado', 'body' => 'Tu plan no permite otra clonación de voz.'],
            ],
        ],
        'avatar_limit_reached' => [
            'email' => false,
            'app' => true,
            'category' => 'usage',
            'copy' => [
                'en' => ['title' => 'Avatar limit reached', 'body' => 'Your plan does not allow another avatar generation.'],
                'es' => ['title' => 'Límite de avatar alcanzado', 'body' => 'Tu plan no permite otra generación de avatar.'],
            ],
        ],
        'source_limit_reached' => [
            'email' => false,
            'app' => true,
            'category' => 'usage',
            'copy' => [
                'en' => ['title' => 'Source limit reached', 'body' => 'Your plan does not allow another source upload.'],
                'es' => ['title' => 'Límite de fuentes alcanzado', 'body' => 'Tu plan no permite cargar otra fuente.'],
            ],
        ],
        'message_or_chat_limit_reached' => [
            'email' => false,
            'app' => true,
            'category' => 'usage',
            'copy' => [
                'en' => ['title' => 'Message limit reached', 'body' => 'Your plan does not allow more chat messages.'],
                'es' => ['title' => 'Límite de mensajes alcanzado', 'body' => 'Tu plan no permite más mensajes de chat.'],
            ],
        ],
        'payment_pending' => [
            'email' => false,
            'app' => true,
            'category' => 'billing',
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => ['title' => 'Payment pending', 'body' => 'Your payment for :plan is pending.'],
                'es' => ['title' => 'Pago pendiente', 'body' => 'Tu pago del plan :plan está pendiente.'],
            ],
        ],
        'payment_approved' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'kind' => 'log',
            'visible_in_bell' => false,
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => ['subject' => 'Your Bigmelo payment was approved', 'title' => 'Payment approved', 'body' => 'Your payment for :plan was approved for :amount.', 'action' => 'View billing'],
                'es' => ['subject' => 'Tu pago de Bigmelo fue aprobado', 'title' => 'Pago aprobado', 'body' => 'Tu pago del plan :plan fue aprobado por :amount.', 'action' => 'Ver facturación'],
            ],
        ],
        'payment_rejected' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'kind' => 'log',
            'visible_in_bell' => false,
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => ['subject' => 'Your Bigmelo payment was rejected', 'title' => 'Payment rejected', 'body' => 'Your payment for :plan was rejected. Try again from billing.', 'action' => 'Open billing'],
                'es' => ['subject' => 'Tu pago de Bigmelo fue rechazado', 'title' => 'Pago rechazado', 'body' => 'Tu pago del plan :plan fue rechazado. Inténtalo de nuevo desde facturación.', 'action' => 'Abrir facturación'],
            ],
        ],
        'plan_activated_or_changed' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'billing',
            'kind' => 'log',
            'visible_in_bell' => false,
            'action_url' => '/dashboard/settings/billing',
            'copy' => [
                'en' => ['subject' => 'Your Bigmelo plan is active', 'title' => 'Plan active', 'body' => 'Your :plan plan is now active.', 'action' => 'View billing'],
                'es' => ['subject' => 'Tu plan de Bigmelo está activo', 'title' => 'Plan activo', 'body' => 'Tu plan :plan ahora está activo.', 'action' => 'Ver facturación'],
            ],
        ],
        'admin_impersonation_started' => [
            'email' => false,
            'app' => true,
            'category' => 'admin',
            'kind' => 'log',
            'visible_in_bell' => false,
            'copy' => [
                'en' => ['title' => 'Admin impersonation started', 'body' => 'You started viewing the admin as :user.'],
                'es' => ['title' => 'Impersonación iniciada', 'body' => 'Empezaste a ver el admin como :user.'],
            ],
        ],
        'external_integration_error' => [
            'email' => true,
            'app' => true,
            'mandatory' => true,
            'category' => 'system',
            'copy' => [
                'en' => ['subject' => 'Bigmelo integration error', 'title' => 'Integration error', 'body' => ':service reported an error. :message'],
                'es' => ['subject' => 'Error de integración en Bigmelo', 'title' => 'Error de integración', 'body' => ':service reportó un error. :message'],
            ],
        ],
        'service_maintenance_or_degradation' => [
            'email' => true,
            'app' => true,
            'preference_key' => 'product_updates',
            'category' => 'system',
            'copy' => [
                'en' => ['subject' => 'Bigmelo service notice', 'title' => 'Service notice', 'body' => ':message'],
                'es' => ['subject' => 'Aviso de servicio de Bigmelo', 'title' => 'Aviso de servicio', 'body' => ':message'],
            ],
        ],
    ],
];
