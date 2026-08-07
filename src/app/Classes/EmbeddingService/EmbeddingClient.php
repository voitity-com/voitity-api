<?php

namespace App\Classes\EmbeddingService;

interface EmbeddingClient
{
    /**
     * @param  array<int, string>  $inputs
     */
    public function embed(array $inputs): EmbeddingResult;
}
