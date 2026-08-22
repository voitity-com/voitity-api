<?php

namespace App\Services\Business;

class BusinessLocalization
{
    public const SUPPORTED_LOCALES = ['es', 'en'];

    /** @var array<string, array{type: string, labels: array<string, string>, phrases: array<string, string>}> */
    private const FIELDS = [
        'full_name' => [
            'type' => 'text',
            'labels' => ['es' => 'Nombre y apellido', 'en' => 'Full name'],
            'phrases' => ['es' => 'nombre y apellido', 'en' => 'full name'],
        ],
        'email' => [
            'type' => 'email',
            'labels' => ['es' => 'Email', 'en' => 'Email'],
            'phrases' => ['es' => 'email válido', 'en' => 'valid email'],
        ],
        'phone' => [
            'type' => 'tel',
            'labels' => ['es' => 'Teléfono con indicativo', 'en' => 'Phone with country code'],
            'phrases' => ['es' => 'teléfono con indicativo de país', 'en' => 'phone with country code'],
        ],
        'whatsapp' => [
            'type' => 'tel',
            'labels' => ['es' => 'WhatsApp con indicativo', 'en' => 'WhatsApp with country code'],
            'phrases' => ['es' => 'WhatsApp con indicativo de país', 'en' => 'WhatsApp with country code'],
        ],
        'company' => [
            'type' => 'text',
            'labels' => ['es' => 'Empresa', 'en' => 'Company'],
            'phrases' => ['es' => 'empresa', 'en' => 'company'],
        ],
        'website' => [
            'type' => 'url',
            'labels' => ['es' => 'Sitio web', 'en' => 'Website'],
            'phrases' => ['es' => 'sitio web', 'en' => 'website'],
        ],
        'project_summary' => [
            'type' => 'textarea',
            'labels' => ['es' => 'Proyecto o problema', 'en' => 'Project or problem'],
            'phrases' => ['es' => 'descripción completa del problema', 'en' => 'complete problem description'],
        ],
    ];

    public function normalize(?string $locale, string $fallback = 'es'): string
    {
        $normalized = mb_strtolower(trim((string) $locale));

        return in_array($normalized, self::SUPPORTED_LOCALES, true) ? $normalized : $fallback;
    }

    /** @param array<string, mixed> $config */
    public function nodeMessage(array $config, string $locale): string
    {
        $messages = is_array($config['messages'] ?? null) ? $config['messages'] : [];

        return trim((string) ($messages[$locale] ?? $config['message'] ?? $messages['es'] ?? $messages['en'] ?? ''));
    }

    public function decisionClarification(string $question, string $locale): string
    {
        return $locale === 'en'
            ? "I could not determine whether your answer was affirmative or negative. Please clarify your response to this question: {$question}"
            : "No pude determinar si tu respuesta fue afirmativa o negativa. Por favor aclara tu respuesta a esta pregunta: {$question}";
    }

    public function instructionFallback(string $locale, bool $waitForInput): string
    {
        if ($locale === 'en') {
            return $waitForInput
                ? 'Please tell us a little more so we can continue.'
                : 'We can continue with the next step.';
        }

        return $waitForInput
            ? 'Cuéntanos un poco más para poder continuar.'
            : 'Podemos continuar con el siguiente paso.';
    }

    /**
     * @param  array<int, string>  $required
     * @param  array<int, string>  $optional
     * @return array<int, array{key: string, label: string, type: string, required: bool}>
     */
    public function fieldDefinitions(array $required, array $optional, string $locale): array
    {
        $required = array_values(array_unique($required));

        return collect([...$required, ...$optional])
            ->filter(fn (mixed $field): bool => is_string($field) && isset(self::FIELDS[$field]))
            ->unique()
            ->map(fn (string $field): array => [
                'key' => $field,
                'label' => $this->fieldLabel($field, $locale),
                'type' => self::FIELDS[$field]['type'],
                'required' => in_array($field, $required, true),
            ])
            ->values()
            ->all();
    }

    public function fieldLabel(string $field, string $locale): string
    {
        return self::FIELDS[$field]['labels'][$locale] ?? self::FIELDS[$field]['labels']['es'] ?? $field;
    }

    public function fieldPhrase(string $field, string $locale): string
    {
        return self::FIELDS[$field]['phrases'][$locale] ?? self::FIELDS[$field]['phrases']['es'] ?? $field;
    }

    /** @param array<int, string> $fields */
    public function fieldLabels(array $fields, string $locale): array
    {
        return collect($fields)->map(fn (string $field): string => $this->fieldLabel($field, $locale))->all();
    }

    /** @param array<int, string> $fields */
    public function fieldPhrases(array $fields, string $locale): array
    {
        return collect($fields)->map(fn (string $field): string => $this->fieldPhrase($field, $locale))->all();
    }

    /** @param array<int, string> $required @param array<int, string> $optional */
    public function contactRequest(array $required, array $optional, string $locale): string
    {
        $requiredList = $this->humanList($this->fieldPhrases($required, $locale), $locale);
        $optionalList = $this->humanList($this->fieldPhrases($optional, $locale), $locale);

        if ($locale === 'en') {
            if ($requiredList !== '' && $optionalList !== '') {
                return "Great! To continue, please provide: {$requiredList}. You may also provide {$optionalList}; these fields are optional.";
            }
            if ($requiredList !== '') {
                return "Great! To continue, please provide: {$requiredList}.";
            }
            if ($optionalList !== '') {
                return "Great! We already have the required information. If you want, you may also provide {$optionalList}; these fields are optional.";
            }

            return 'Great! We already have the information needed to continue.';
        }

        if ($requiredList !== '' && $optionalList !== '') {
            return "¡Perfecto! Para continuar indícanos: {$requiredList}. También puedes indicarnos {$optionalList}; son opcionales.";
        }
        if ($requiredList !== '') {
            return "¡Perfecto! Para continuar indícanos: {$requiredList}.";
        }
        if ($optionalList !== '') {
            return "¡Perfecto! Ya tenemos los datos obligatorios. Si quieres, también puedes indicarnos {$optionalList}; son opcionales.";
        }

        return '¡Perfecto! Ya tenemos los datos necesarios para continuar.';
    }

    /** @param array<int, string> $missing */
    public function phoneHint(array $missing, string $locale): string
    {
        if (! array_intersect(['phone', 'whatsapp'], $missing)) {
            return '';
        }

        return $locale === 'en'
            ? ' Remember to include the country code for phone and WhatsApp.'
            : ' Recuerda incluir el indicativo de país en teléfono y WhatsApp.';
    }

    /** @param array<string, mixed> $fields */
    public function fieldsAsMessage(array $fields, string $locale): string
    {
        return collect($fields)
            ->filter(fn (mixed $value): bool => filled($value))
            ->map(fn (mixed $value, string $field): string => $this->fieldLabel($field, $locale).': '.trim((string) $value))
            ->implode("\n");
    }

    /** @param array<int, string> $values */
    private function humanList(array $values, string $locale): string
    {
        if (count($values) < 2) {
            return $values[0] ?? '';
        }

        $last = array_pop($values);

        return implode(', ', $values).($locale === 'en' ? ' and ' : ' y ').$last;
    }
}
