<?php

namespace App\Classes\BusinessFlowAI;

interface BusinessFlowAI
{
    public function classifyTechnology(string $message): BusinessFlowAIResult;

    /** @param array<string, mixed> $known */
    public function extractLeadData(string $message, array $known = [], bool $allowMessageAsProblem = false): BusinessFlowAIResult;

    /** @param array<string, mixed> $leadData */
    public function summarizeSolution(string $message, array $leadData = []): BusinessFlowAIResult;
}
