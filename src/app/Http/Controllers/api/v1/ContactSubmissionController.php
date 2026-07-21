<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Contact\StoreContactSubmissionRequest;
use App\Services\Captcha\CaptchaService;
use App\Services\ContactSubmissionService;
use Illuminate\Http\JsonResponse;

class ContactSubmissionController extends Controller
{
    public function store(
        StoreContactSubmissionRequest $request,
        CaptchaService $captchaService,
        ContactSubmissionService $service
    ): JsonResponse {
        $validated = $request->validated();

        $captchaService->validateOrFail($validated['captcha_token'] ?? null, $request->ip());

        $submission = $service->store($validated, $request);

        return response()->json([
            'message' => 'Contact request received successfully.',
            'data' => [
                'id' => $submission->id,
            ],
        ], 201);
    }
}
