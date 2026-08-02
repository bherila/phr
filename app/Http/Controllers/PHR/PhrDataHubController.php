<?php

namespace App\Http\Controllers\PHR;

use App\Http\Controllers\Controller;
use App\Services\PHR\DataHub\PhrDataInventoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PhrDataHubController extends Controller
{
    public function __construct(private readonly PhrDataInventoryService $inventoryService) {}

    public function index(Request $request): JsonResponse
    {
        $userId = (int) $request->user()?->id;
        $response = response()->json($this->inventoryService->forUser($userId));
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
