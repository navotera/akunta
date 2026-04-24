<?php

namespace App\Filament\Resources\ApiTokenResource\Pages;

use App\Filament\Resources\ApiTokenResource;
use App\Models\ApiToken;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateApiToken extends CreateRecord
{
    protected static string $resource = ApiTokenResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        [$token, $plain] = ApiToken::issue($data);

        session()->flash('api_token_plain', $plain);

        return $token;
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('index');
    }
}
