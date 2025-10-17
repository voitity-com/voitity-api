<?php

namespace App\Http\Controllers\api\v1;

use App\Classes\ChatAIService\ChatAIClient;
use App\Http\Controllers\Controller;
use App\Http\Requests\Message\StoreMessageRequest;
use App\Models\Profile;
use Illuminate\Http\JsonResponse;

class MessageController extends Controller
{
    public function __construct(private readonly ChatAIClient $chatAIClient)
    {
    }

    public function store(StoreMessageRequest $request, Profile $profile): JsonResponse
    {
        $payload = $request->validated();

        $answer = $this->chatAIClient->getAnswer($profile, $payload['message']);

        return response()->json([
            'message' => 'Message processed successfully.',
            'data' => $answer->toArray(),
        ]);
    }
}
