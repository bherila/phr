<?php

namespace App\Http\Controllers\Api;

use App\GenAiProcessor\Models\GenAiImportJob;
use App\GenAiProcessor\Services\PhrGenAiExecutionModeService;
use App\Models\User;
use App\Support\AgentApi\AgentApiScopes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final readonly class UserAiExecutionModeController
{
    public function __construct(private PhrGenAiExecutionModeService $modes) {}

    public function show(Request $request): JsonResponse
    {
        return $this->response($request->user());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::in(GenAiImportJob::VALID_EXECUTION_MODES)],
        ]);
        /** @var User $user */
        $user = $request->user();

        return $this->response($this->modes->update($user, $validated['mode']));
    }

    private function response(User $user): JsonResponse
    {
        return response()->json([
            'mode' => $user->genAiExecutionMode(),
            'mcp_url' => url('/api/v1/mcp'),
            'rest_base_url' => url('/api/v1/genai'),
            'required_scopes' => [
                AgentApiScopes::MCP_USE,
                AgentApiScopes::GENAI_READ,
                AgentApiScopes::GENAI_WORK,
            ],
        ]);
    }
}
