<?php

namespace App\Classes\BusinessFlowAI;

use App\Services\Business\BusinessUsageRecorder;

class LocalBusinessFlowAI implements BusinessFlowAI
{
    public function __construct(private readonly BusinessUsageRecorder $usage) {}

    public function classifyTechnology(string $message): BusinessFlowAIResult
    {
        $normalized = mb_strtolower($message);
        $keywords = [
            'software', 'desarrollo', 'aplicación', 'app', 'web', 'automat', 'inteligencia artificial',
            ' ia ', 'ai', 'datos', 'data', 'infraestructura', 'cloud', 'nube', 'api', 'integración',
            'sistema', 'tecnología', 'tecnologico', 'tecnológico', 'base de datos', 'mapeo', 'mateo',
        ];
        $branch = collect($keywords)->contains(fn (string $keyword): bool => str_contains(" {$normalized} ", $keyword))
            ? 'technology'
            : 'other';

        return $this->result($message, ['branch' => $branch, 'confidence' => $branch === 'technology' ? 0.86 : 0.64]);
    }

    public function extractLeadData(string $message, array $known = [], bool $allowMessageAsProblem = false): BusinessFlowAIResult
    {
        $data = array_filter($known, fn ($value): bool => filled($value));
        if (preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', $message, $match)) {
            $data['email'] = mb_strtolower($match[0]);
        }
        if (preg_match('/(?:tel[eé]fono|tel|celular|phone)\s*(?:es|n[uú]mero|:|-)?\s*(\+\s*\d[\d\s().-]{7,}\d)/iu', $message, $match)) {
            $data['phone'] = $this->normalizeInternationalPhone($match[1]);
        }
        if (preg_match('/(?:whats\s*app|whatsapp|wa)\s*(?:es|n[uú]mero|:|-)?\s*(\+\s*\d[\d\s().-]{7,}\d)/iu', $message, $match)) {
            $data['whatsapp'] = $this->normalizeInternationalPhone($match[1]);
        }
        if (preg_match('/(?:me llamo|mi nombre es|nombre[:\s]+)\s*([\p{L}][\p{L}\s\'-]{1,80})/iu', $message, $match)) {
            $data['full_name'] = $this->cleanCaptured($match[1]);
        }
        if (preg_match('/(?:empresa|compañía|compania|company)[:\s]+\s*([\p{L}0-9][^,;\n]{1,100})/iu', $message, $match)) {
            $data['company'] = $this->cleanCaptured($match[1]);
        }
        if (preg_match('/(?:sitio\s+web|website|p[aá]gina\s+web|web)\s*(?:es|:|-)?\s*((?:https?:\/\/)?(?:www\.)?[a-z0-9][a-z0-9.-]+\.[a-z]{2,}(?:\/[^\s,;]*)?)/iu', $message, $match)) {
            $data['website'] = $this->normalizeWebsite($match[1]);
        }
        if (! isset($data['project_summary']) && preg_match('/(?:proyecto|problema|necesidad)\s*(?:es|:|-)\s*(.{20,})/isu', $message, $match)) {
            $data['project_summary'] = $this->cleanCaptured($match[1]);
        } elseif ($allowMessageAsProblem && mb_strlen(trim($message)) >= 30 && ! isset($data['project_summary'])) {
            $data['project_summary'] = trim($message);
        }

        return $this->result($message, ['lead_data' => $data]);
    }

    public function summarizeSolution(string $message, array $leadData = []): BusinessFlowAIResult
    {
        $project = trim((string) ($leadData['project_summary'] ?? $message));
        $normalized = mb_strtolower($project);
        $focus = match (true) {
            str_contains($normalized, 'base de datos'), str_contains($normalized, 'datos') => 'diseñar una arquitectura de datos, automatizar la ingesta y transformación, y exponer indicadores verificables',
            str_contains($normalized, 'infraestructura'), str_contains($normalized, 'nube'), str_contains($normalized, 'cloud') => 'definir una arquitectura cloud segura, automatizar su despliegue y habilitar observabilidad y control de costos',
            str_contains($normalized, ' ia '), str_contains($normalized, 'ai'), str_contains($normalized, 'inteligencia artificial') => 'construir un flujo asistido por IA con datos de referencia, validaciones, trazabilidad y revisión humana',
            default => 'modelar el proceso, construir el software mínimo necesario e integrar las fuentes y sistemas involucrados',
        };
        $summary = "Posible solución interna: {$focus}. Iniciar con un discovery técnico breve, definir métricas de éxito y entregar un prototipo funcional en un máximo de dos semanas. ";
        $summary .= 'Necesidad detectada: '.mb_substr($project, 0, 700);

        return $this->result($message, ['summary' => $summary]);
    }

    private function cleanCaptured(string $value): string
    {
        return trim((string) preg_replace('/(?:,|;)?\s+(?:mi|correo|email|tel[eé]fono|celular|whats\s*app|whatsapp|empresa|compañía|compania|sitio\s+web|website|proyecto|problema|necesidad)\b.*$/iu', '', $value));
    }

    private function normalizeInternationalPhone(string $value): string
    {
        return '+'.preg_replace('/\D+/', '', $value);
    }

    private function normalizeWebsite(string $value): string
    {
        return preg_match('/^https?:\/\//i', $value) ? $value : 'https://'.$value;
    }

    /** @param array<string, mixed> $data */
    private function result(string $input, array $data): BusinessFlowAIResult
    {
        $output = json_encode($data, JSON_UNESCAPED_UNICODE) ?: '';

        return new BusinessFlowAIResult(
            $data,
            $this->usage->estimateTokens($input),
            $this->usage->estimateTokens($output),
        );
    }
}
