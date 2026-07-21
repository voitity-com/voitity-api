<?php

namespace App\Classes\UsdCopRateService;

interface UsdCopRateClient
{
    public function latest(): UsdCopRate;
}
