<?php

namespace App\Services;

use App\Jobs\SendSupportRequestNotification;
use App\Models\Profile;
use App\Models\SupportRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupportRequestService
{
    /**
     * @param  array{description: string, profile_id?: int|null}  $data
     */
    public function store(User $user, array $data, Request $request): SupportRequest
    {
        return DB::transaction(function () use ($user, $data, $request): SupportRequest {
            $profile = filled($data['profile_id'] ?? null)
                ? $user->profiles()->findOrFail($data['profile_id'])
                : null;

            $supportRequest = SupportRequest::create([
                'user_id' => $user->id,
                'profile_id' => $profile?->id,
                'email' => $user->email,
                'profile_alias' => $profile instanceof Profile ? $profile->alias : null,
                'description' => $data['description'],
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);

            Log::info('Support request created.', [
                'support_request_id' => $supportRequest->id,
                'user_id' => $user->id,
                'profile_id' => $profile?->id,
            ]);

            SendSupportRequestNotification::dispatch($supportRequest)->afterCommit();

            return $supportRequest;
        });
    }
}
