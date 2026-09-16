<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

/**
 * Bewust een witlijst van velden, nooit toArray()/(array) op het model
 * zelf - zo kan een nieuw, per ongeluk gevoelig veld op User (bv. een
 * toekomstig "internal_notes") nooit stilzwijgend mee de API in lekken.
 * Elk veld hier is bewust individueel goedgekeurd om publiek zichtbaar
 * te zijn.
 */
class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'email' => $this->when($request->user()?->id === $this->id, $this->email),
            'avatar_url' => $this->avatar ? Storage::url($this->avatar) : null,
            // De club-specifieke rol (owner/co_owner/treasurer/member) -
            // enkel aanwezig wanneer deze resource effectief vanuit een
            // club_user-pivot-relatie geladen werd, zoals bij de
            // ledenlijst. Bij elk ander gebruik van UserResource (bv.
            // klassement, waar geen pivot geladen is) blijft dit veld
            // gewoon afwezig - geen extra databasequery hier veroorzaakt.
            'role' => $this->whenPivotLoaded('club_user', fn () => $this->pivot->role),
        ];
    }
}
