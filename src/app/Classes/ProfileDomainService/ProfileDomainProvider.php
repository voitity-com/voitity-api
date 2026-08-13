<?php

namespace App\Classes\ProfileDomainService;

use App\Models\ProfileDomain;

interface ProfileDomainProvider
{
    public function provision(ProfileDomain $domain): ProfileDomainProvisioningResult;

    public function refresh(ProfileDomain $domain): ProfileDomainProvisioningResult;

    public function disconnect(ProfileDomain $domain): void;

    public function name(): string;
}
