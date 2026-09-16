<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Club;
use App\Services\Competition\StandingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StandingsController extends Controller
{
    use ApiResponses;

    public function __construct(
        protected StandingsService $standings,
    ) {
    }

    public function index(Request $request, Club $club): JsonResponse
    {
        $this->authorize('view', $club);

        $year = (int) ($request->query('year') ?: now()->year);

        // 'type' is null/leeg voor enkel officiële klassement-wedstrijden
        // (de standaardweergave), of 'all' voor alle types samen - zie
        // StandingsService::standingsWithMeta() (niet in deze excerpt)
        // voor hoe dat samenspeelt met de lidgeld-betaalmuur.
        $type = $request->query('type');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $result = $this->standings->standingsWithMeta($club, $year, $type, $dateFrom, $dateTo);

        return $this->ok([
            'standings' => $result['standings']->map(fn ($row) => [
                'rank' => $row['rank'],
                'user' => new UserResource($row['user']),
                'total_weight' => $row['total_weight'],
                'total_points' => $row['total_points'],
                'competitions_count' => $row['competitions_count'],
            ]),
            'has_competitions' => $result['hasCompetitions'],
            'has_results' => $result['hasResults'],
            'has_eligible_members' => $result['hasEligibleMembers'],
            'uses_points_calculation' => $result['usesPointsCalculation'],
        ]);
    }

    /**
     * Per-lid detail: welke wedstrijden, welk gewicht per wedstrijd, en
     * hoe vaak overgewicht/max gewicht behaald werd. Spiegelt de
     * webversie van deze controller exact, zodat beide clients dezelfde
     * cijfers tonen.
     */
    public function memberDetail(Request $request, Club $club, \App\Models\User $user): JsonResponse
    {
        $this->authorize('view', $club);

        $year = (int) ($request->query('year') ?: now()->year);
        $type = $request->query('type');
        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');

        $detail = $this->standings->memberDetail($club, $user->id, $year, $type, $dateFrom, $dateTo);

        return $this->ok([
            'competitions' => $detail['competitions']->map(fn ($row) => [
                'competition_id' => $row['competition_id'],
                'competition_name' => $row['competition_name'],
                'date' => $row['date']?->toDateString(),
                'type' => $row['type']?->value ?? $row['type'],
                'weight' => $row['weight'],
                'overweight_count' => $row['overweight_count'],
                'disqualified_count' => $row['disqualified_count'],
                'placement' => $row['placement'],
            ]),
            'total_overweight_count' => $detail['totalOverweightCount'],
            'total_disqualified_count' => $detail['totalDisqualifiedCount'],
        ]);
    }
}
