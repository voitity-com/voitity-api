<?php

namespace App\Enums;

enum ProfileDomainStatus: string
{
    case PendingProvisioning = 'pending_provisioning';
    case PendingDns = 'pending_dns';
    case PendingCertificate = 'pending_certificate';
    case Activating = 'activating';
    case Active = 'active';
    case Failed = 'failed';
    case Disconnecting = 'disconnecting';
}
