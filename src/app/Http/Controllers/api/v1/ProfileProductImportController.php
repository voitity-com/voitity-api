<?php

namespace App\Http\Controllers\api\v1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Products\ApplyProfileProductCsvRequest;
use App\Http\Requests\Products\PreviewProfileProductCsvRequest;
use App\Http\Responses\Products\ProfileProductImportResponse;
use App\Models\Profile;
use App\Models\ProfileProductImport;
use App\Models\User;
use App\Services\Products\ProfileProductCsvImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileProductImportController extends Controller
{
    public function preview(
        PreviewProfileProductCsvRequest $request,
        Profile $profile,
        ProfileProductCsvImportService $service
    ): JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        try {
            $import = $service->preview($profile, $request->user(), $request->file('file'));

            return response()->json([
                'message' => 'Product CSV preview created successfully.',
                'data' => (new ProfileProductImportResponse($import))->toArray(),
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function show(Request $request, Profile $profile, ProfileProductImport $productImport): JsonResponse
    {
        if ($response = $this->authorizeImport($request, $profile, $productImport)) {
            return $response;
        }

        return response()->json([
            'message' => 'Product CSV preview retrieved successfully.',
            'data' => (new ProfileProductImportResponse($productImport))->toArray(),
        ]);
    }

    public function apply(
        ApplyProfileProductCsvRequest $request,
        Profile $profile,
        ProfileProductImport $productImport,
        ProfileProductCsvImportService $service
    ): JsonResponse {
        if ($response = $this->authorizeImport($request, $profile, $productImport)) {
            return $response;
        }

        try {
            return response()->json([
                'message' => 'Product CSV import applied successfully.',
                'data' => $service->apply($profile, $request->user(), $productImport, $request->validated('rows')),
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    public function template(Request $request, Profile $profile): StreamedResponse|JsonResponse
    {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        $headers = [
            'name',
            'description',
            'image',
            'link',
        ];
        $example = [
            'Cuaderno universitario',
            'Cuaderno A4 de 100 hojas, precio $28.000.',
            'https://example.com/images/cuaderno.jpg',
            'https://example.com/productos/cuaderno-universitario',
        ];

        return response()->streamDownload(function () use ($headers, $example): void {
            $stream = fopen('php://output', 'w');
            fputcsv($stream, $headers);
            fputcsv($stream, $example);
            fclose($stream);
        }, 'bigmelo-products-template.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function authorizeImport(
        Request $request,
        Profile $profile,
        ProfileProductImport $import
    ): ?JsonResponse {
        if ($response = $this->authorizeProfile($request, $profile)) {
            return $response;
        }

        if ((int) $import->profile_id !== (int) $profile->id) {
            return response()->json(['message' => 'Profile product import not found.'], 404);
        }

        return null;
    }

    private function authorizeProfile(Request $request, Profile $profile): ?JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        if ($user->role === 'admin' || (int) $profile->user_id === (int) $user->id) {
            return null;
        }

        return response()->json(['message' => 'Profile not found.'], 404);
    }
}
