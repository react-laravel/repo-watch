<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Services\RepoWatch\FleetActivityDigestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class FleetActivityDigestController extends Controller
{
    public function __construct(
        private readonly FleetActivityDigestService $digestService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'since' => ['sometimes', 'nullable', 'date'],
            'hours' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:168'],
        ]);

        try {
            $digest = $this->digestService->build(
                (int) $request->user()->id,
                isset($validated['since']) && is_string($validated['since']) ? $validated['since'] : null,
                isset($validated['hours']) ? (int) $validated['hours'] : null,
            );

            return $this->success($digest);
        } catch (Throwable $exception) {
            report($exception);

            return $this->error('无法生成活动摘要', null, 500);
        }
    }
}
