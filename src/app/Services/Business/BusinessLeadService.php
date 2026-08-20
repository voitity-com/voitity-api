<?php

namespace App\Services\Business;

use App\Enums\BusinessLeadStatus;
use App\Mail\BusinessLeadNotification;
use App\Mail\BusinessVisitorConfirmation;
use App\Models\BusinessActionRun;
use App\Models\BusinessConversation;
use App\Models\BusinessLead;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

class BusinessLeadService
{
    public function finalize(BusinessConversation $conversation, string $nodeKey): BusinessLead
    {
        $context = $conversation->context ?? [];
        $leadData = is_array($context['lead_data'] ?? null) ? $context['lead_data'] : [];
        $required = ['full_name', 'email', 'phone', 'whatsapp', 'project_summary'];
        $missing = collect($required)->filter(fn (string $field): bool => blank($leadData[$field] ?? null))->values()->all();
        $solution = trim((string) ($context['ai_solution_summary'] ?? ''));
        if ($missing !== [] || $solution === '') {
            throw ValidationException::withMessages([
                'lead' => 'No se puede finalizar el lead sin problema, solución interna y datos obligatorios.',
            ]);
        }

        $lead = $this->once($conversation, $nodeKey, 'create_lead', function () use ($conversation, $leadData, $solution): BusinessLead {
            return BusinessLead::query()->updateOrCreate(
                ['business_conversation_id' => $conversation->id],
                [
                    'business_id' => $conversation->business_id,
                    'status' => BusinessLeadStatus::Created,
                    'full_name' => $leadData['full_name'] ?? null,
                    'email' => $leadData['email'] ?? null,
                    'phone' => $leadData['phone'] ?? null,
                    'whatsapp' => $leadData['whatsapp'] ?? null,
                    'company' => $leadData['company'] ?? null,
                    'website' => $leadData['website'] ?? null,
                    'project_summary' => $leadData['project_summary'] ?? null,
                    'ai_solution_summary' => $solution,
                    'data' => $leadData,
                ]
            );
        });
        $lead = $lead instanceof BusinessLead ? $lead : $conversation->lead()->firstOrFail();
        $lead->load('business.settings');

        $recipient = $lead->business->settings?->lead_recipient_email;
        if ($recipient) {
            $this->once($conversation, $nodeKey, 'notify_business', function () use ($recipient, $lead): bool {
                Mail::to($recipient)->queue(new BusinessLeadNotification($lead));

                return true;
            });
        }
        if ($lead->email) {
            $this->once($conversation, $nodeKey, 'notify_visitor', function () use ($lead): bool {
                Mail::to($lead->email)->queue(new BusinessVisitorConfirmation($lead));

                return true;
            });
        }

        return $lead;
    }

    private function once(BusinessConversation $conversation, string $nodeKey, string $actionKey, callable $callback): mixed
    {
        $key = "{$conversation->uuid}:{$nodeKey}:{$actionKey}";
        $run = BusinessActionRun::query()->firstOrCreate(
            ['idempotency_key' => $key],
            [
                'business_id' => $conversation->business_id,
                'business_conversation_id' => $conversation->id,
                'node_key' => $nodeKey,
                'action_key' => $actionKey,
                'status' => 'pending',
            ]
        );
        if ($run->status === 'completed') {
            return null;
        }
        try {
            $run->increment('attempts');
            $result = $callback();
            $run->update(['status' => 'completed', 'executed_at' => now(), 'last_error' => null]);

            return $result;
        } catch (\Throwable $e) {
            $run->update(['status' => 'failed', 'last_error' => $e->getMessage()]);
            throw $e;
        }
    }
}
