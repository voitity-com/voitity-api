<?php

namespace App\Services\Integrations;

use App\Enums\IntegrationActionType;
use App\Enums\IntegrationDestinationType;

class IntegrationDestinationCatalog
{
    /**
     * @return array<int, array{value: string, label: string, action_type: string, action_label: string}>
     */
    public function all(?string $locale = null): array
    {
        $locale = $this->locale($locale);

        return collect((array) config('integration-destinations.destinations', []))
            ->map(function (array $destination, string $value) use ($locale): array {
                $label = $this->label($value, $locale);
                $actionType = $this->actionType($value)->value;

                return [
                    'value' => $value,
                    'label' => $label,
                    'action_type' => $actionType,
                    'action_label' => $this->actionLabel(
                        $actionType,
                        $this->actionDestinationLabel($value, $locale),
                        $locale,
                    ),
                ];
            })
            ->values()
            ->all();
    }

    public function locale(?string $locale): string
    {
        $supported = (array) config('integration-destinations.supported_locales', ['es', 'en']);
        $locale = mb_strtolower(trim((string) $locale));

        return in_array($locale, $supported, true)
            ? $locale
            : (string) config('integration-destinations.default_locale', 'es');
    }

    public function actionType(string|IntegrationDestinationType $destination): IntegrationActionType
    {
        $value = $destination instanceof IntegrationDestinationType ? $destination->value : $destination;
        $action = data_get(config('integration-destinations.destinations'), "{$value}.action");

        return IntegrationActionType::tryFrom((string) $action) ?? IntegrationActionType::ViewOn;
    }

    public function label(
        string|IntegrationDestinationType $destination,
        ?string $locale = null,
        ?string $customLabel = null,
    ): string {
        $value = $destination instanceof IntegrationDestinationType ? $destination->value : $destination;
        $locale = $this->locale($locale);
        $customLabel = trim((string) $customLabel);

        if ($value === IntegrationDestinationType::Other->value && $customLabel !== '') {
            return $customLabel;
        }

        return (string) data_get(
            config('integration-destinations.destinations'),
            "{$value}.{$locale}",
            str($value)->replace('_', ' ')->title()->toString(),
        );
    }

    public function actionLabel(
        string|IntegrationActionType $action,
        string $destinationLabel,
        ?string $locale = null,
    ): string {
        $value = $action instanceof IntegrationActionType ? $action->value : $action;
        $locale = $this->locale($locale);
        $template = (string) data_get(
            config('integration-destinations.actions'),
            "{$locale}.{$value}",
            ':destination',
        );

        return trim(str_replace(':destination', trim($destinationLabel), $template));
    }

    public function actionDestinationLabel(
        string|IntegrationDestinationType $destination,
        ?string $locale = null,
        ?string $customLabel = null,
    ): string {
        $value = $destination instanceof IntegrationDestinationType ? $destination->value : $destination;
        $locale = $this->locale($locale);
        $customLabel = trim((string) $customLabel);

        if ($value === IntegrationDestinationType::Other->value && $customLabel !== '') {
            return $customLabel;
        }

        return (string) data_get(
            config('integration-destinations.destinations'),
            "{$value}.action_{$locale}",
            $this->label($value, $locale, $customLabel),
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array{destination_type: string|null, destination_label: string|null, action_type: string|null, action_label: string|null}
     */
    public function labelsForMedia(?array $metadata, ?string $locale = null): array
    {
        $destination = IntegrationDestinationType::tryFrom((string) data_get($metadata, 'destination_type'));

        if (! $destination) {
            return [
                'destination_type' => null,
                'destination_label' => null,
                'action_type' => null,
                'action_label' => null,
            ];
        }

        $action = IntegrationActionType::tryFrom((string) data_get($metadata, 'action_type'))
            ?? $this->actionType($destination);
        $label = $this->label(
            $destination,
            $locale,
            (string) data_get($metadata, 'custom_destination_label'),
        );
        $actionDestinationLabel = $this->actionDestinationLabel(
            $destination,
            $locale,
            (string) data_get($metadata, 'custom_destination_label'),
        );

        return [
            'destination_type' => $destination->value,
            'destination_label' => $label,
            'action_type' => $action->value,
            'action_label' => $this->actionLabel($action, $actionDestinationLabel, $locale),
        ];
    }
}
