<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WeighInResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'result_id' => $this->result_id,
            'participant' => $this->whenLoaded('participant', fn () => $this->participant ? new UserResource($this->participant) : null),
            'weight' => (float) $this->weight,
            'original_weight' => (float) $this->original_weight,
            'capped' => (bool) $this->capped,
            'disqualified' => (bool) $this->disqualified,
            // Het client-gegenereerde UUID waarmee de mobiele app dit
            // gewicht oorspronkelijk indiende - laat de app toe om, na een
            // offline periode, te bevestigen dat een lokaal in de
            // wachtrij staand gewicht al dan niet succesvol verwerkt is
            // (bv. na een herstart van de app). Zie WeighInController::sync().
            'client_uuid' => $this->client_uuid,
            // Voor de smart-reading-functie: enkel het uitgeknipte,
            // geconverteerde stukje van de weegschaal-foto - null zolang
            // een gewicht manueel ingevoerd werd.
            'photo_url' => $this->photo_path ? \Illuminate\Support\Facades\Storage::url($this->photo_path) : null,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
