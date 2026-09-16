<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\WeighInResource;
use App\Models\Competition;
use App\Services\Competition\ParticipantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Kernscenario van de mobiele app: gewichten invoeren aan de waterkant,
 * vaak met slecht of geen netwerk. Elke weging krijgt aan de kant van de
 * app zelf al een UUID mee (client-side gegenereerd bij het lokaal
 * opslaan, vóór er ooit verbinding is) - dat UUID is wat idempotentie
 * garandeert: een weging die twee keer verstuurd wordt (bv. door een
 * afgebroken verbinding gevolgd door een automatische herhaling) wordt
 * maar één keer verwerkt (zie ParticipantService::recordWeighIn(), niet
 * in deze excerpt opgenomen).
 */
class WeighInController extends Controller
{
    use ApiResponses;

    public function __construct(
        protected ParticipantService $participants,
    ) {
    }

    /**
     * Eén gewicht toevoegen (rechtstreeks, wanneer er verbinding is).
     */
    public function store(Request $request, Competition $competition): JsonResponse
    {
        $this->authorize('manageResults', $competition);

        if ($competition->isClosed()) {
            return $this->fail(__('app.competition_already_closed'), 422);
        }

        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'weight' => ['required', 'numeric', 'min:0.01'],
            'client_uuid' => ['nullable', 'uuid'],
            'photo' => ['nullable', 'image', 'max:8192', 'dimensions:max_width=6000,max_height=6000', new \App\Rules\NotHeicImage()],
            'photo_crop' => ['nullable', 'array'],
            'photo_crop.x' => ['required_with:photo_crop', 'integer', 'min:0'],
            'photo_crop.y' => ['required_with:photo_crop', 'integer', 'min:0'],
            'photo_crop.width' => ['required_with:photo_crop', 'integer', 'min:1'],
            'photo_crop.height' => ['required_with:photo_crop', 'integer', 'min:1'],
        ]);

        try {
            $applied = $this->participants->recordWeighIn(
                $competition,
                (int) $validated['user_id'],
                (float) $validated['weight'],
                $request->user(),
                $validated['client_uuid'] ?? null,
                $request->file('photo'),
                $validated['photo_crop'] ?? null,
            );

            return $this->ok($applied, __('app.weight_recorded'));
        } catch (\Throwable $e) {
            Log::warning('API: gewicht invoeren mislukt', ['competition_id' => $competition->id, 'message' => $e->getMessage(), 'exception_class' => get_class($e)]);

            $message = str_contains($e->getMessage(), 'no peg assigned')
                ? __('app.weight_no_peg')
                : __('app.weight_record_failed_generic');

            return $this->fail($message, 422);
        }
    }

    /**
     * ================= OFFLINE-SYNC: BATCH-VERWERKING =================
     * De mobiele app verzamelt gewichten lokaal terwijl er geen verbinding
     * is, en stuurt ze in één keer hierheen zodra de verbinding
     * terugkeert - efficiënter dan één request per weging, en elk
     * individueel gewicht wordt apart verwerkt (één mislukking blokkeert
     * de rest van de batch niet). De respons vermeldt per gewicht (via het
     * client_uuid) of het gelukt is, zodat de app exact weet welke lokale
     * items ze veilig van het toestel mag verwijderen.
     */
    public function sync(Request $request, Competition $competition): JsonResponse
    {
        $this->authorize('manageResults', $competition);

        $validated = $request->validate([
            'weigh_ins' => ['required', 'array', 'max:100'],
            'weigh_ins.*.user_id' => ['required', 'integer'],
            'weigh_ins.*.weight' => ['required', 'numeric', 'min:0.01'],
            'weigh_ins.*.client_uuid' => ['required', 'uuid'],
            // Wanneer het gewicht lokaal ingevoerd werd, niet wanneer het
            // uiteindelijk gesynchroniseerd wordt - belangrijk voor de
            // penningmeester om achteraf de werkelijke volgorde/tijdstip
            // te kunnen nagaan, ook al komt alles in één keer toe.
            'weigh_ins.*.recorded_at' => ['nullable', 'date'],
        ]);

        if ($competition->isClosed()) {
            return $this->fail(__('app.competition_already_closed'), 422);
        }

        $results = [];

        foreach ($validated['weigh_ins'] as $item) {
            try {
                $applied = $this->participants->recordWeighIn(
                    $competition,
                    (int) $item['user_id'],
                    (float) $item['weight'],
                    $request->user(),
                    $item['client_uuid'],
                );

                $results[] = [
                    'client_uuid' => $item['client_uuid'],
                    'success' => true,
                    'already_processed' => $applied['already_processed'],
                    'weight' => $applied['weight'],
                    'capped' => $applied['capped'],
                    'disqualified' => $applied['disqualified'],
                ];
            } catch (\Throwable $e) {
                Log::warning('API: sync van gewicht mislukt', ['competition_id' => $competition->id, 'client_uuid' => $item['client_uuid'], 'message' => $e->getMessage(), 'exception_class' => get_class($e)]);

                $results[] = [
                    'client_uuid' => $item['client_uuid'],
                    'success' => false,
                    'message' => str_contains($e->getMessage(), 'no peg assigned')
                        ? __('app.weight_no_peg')
                        : __('app.weight_record_failed_generic'),
                ];
            }
        }

        return $this->ok(['results' => $results]);
    }

    public function index(Competition $competition): JsonResponse
    {
        $this->authorize('view', $competition);

        $weighIns = $competition->results()
            ->with(['weighIns', 'memberResults.user'])
            ->get()
            ->flatMap(fn ($result) => $result->weighIns->each(
                fn ($weighIn) => $weighIn->setRelation('participant', $result->memberResults->first()?->user)
            ));

        return $this->ok(WeighInResource::collection($weighIns));
    }
}
