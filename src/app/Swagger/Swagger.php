<?php

namespace App\Swagger;

/**
 * @OA\OpenApi(
 *     @OA\Info(
 *         version="1.0.0",
 *         title="Voitity API",
 *         description="Voitity Voice Cloning and Processing API - A comprehensive API for voice cloning, voice sample management, and voice processing using AI-powered voice synthesis.",
 *         @OA\Contact(
 *             email="support@voitity.com",
 *             name="Voitity Support"
 *         ),
 *         @OA\License(
 *             name="MIT License",
 *             url="https://opensource.org/licenses/MIT"
 *         )
 *     ),
 *     @OA\Server(
 *         url="http://localhost:8000",
 *         description="Local Development Server"
 *     ),
 *     @OA\Server(
 *         url="https://api.voitity.com",
 *         description="Production API Server"
 *     )
 * )
 * 
 * @OA\SecurityScheme(
 *     securityScheme="sanctum",
 *     type="apiKey",
 *     in="header",
 *     name="Authorization",
 *     description="Laravel Sanctum Bearer Token (format: Bearer {token})"
 * )
 * 
 * @OA\Tag(
 *     name="Authentication",
 *     description="Authentication and authorization endpoints"
 * )
 * 
 * @OA\Tag(
 *     name="Profile",
 *     description="User profile management"
 * )
 * 
 * @OA\Tag(
 *     name="Voice",
 *     description="Voice management and configuration"
 * )
 * 
 * @OA\Tag(
 *     name="Voice Samples",
 *     description="Voice sample upload, processing, and management"
 * )
 * 
 * @OA\Tag(
 *     name="Voice Processing",
 *     description="Voice cloning and AI processing workflows"
 * )
 */


