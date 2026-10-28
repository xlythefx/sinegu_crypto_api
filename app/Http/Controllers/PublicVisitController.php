<?php

namespace App\Http\Controllers;

use App\Services\Visits\VisitRecorder;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/public/visits — the visitor counter's ping from a public page
 * (frontend `VisitTracker`). Unauthenticated; a session token, when the
 * browser has one, only tells staff apart (never counted) from customers.
 *
 * Always 204, whatever VisitRecorder decided: the answer must not tell a
 * spammer which rule caught them, and a failed write is a missing count,
 * never an error on someone's screen.
 */
class PublicVisitController extends Controller
{
    public function __construct(private VisitRecorder $recorder) {}

    public function store(Request $request): Response
    {
        $this->recorder->record($request);

        return response()->noContent();
    }
}
