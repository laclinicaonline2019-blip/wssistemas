<?php

namespace App\Core\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

/** Violação de regra de negócio: 422 na API, retorno com erro no web. */
class BusinessRuleViolation extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'business_rule', public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
        }

        return back()->withInput($request->except(['password', 'password_confirmation']))->with('error', $this->getMessage());
    }
}
