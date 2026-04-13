<?php

declare(strict_types=1);

namespace Anibalealvarezs\NetSuiteHubDriver\Auth;

use Anibalealvarezs\ApiDriverCore\Auth\BaseAuthProvider;

class NetSuiteAuthProvider extends BaseAuthProvider
{
    public function getAccessToken(): string
    {
        return $this->data['netsuite_auth']['token_id'] ?? "";
    }

    public function getUserId(): string
    {
        return $this->data['netsuite_auth']['user_id'] ?? "";
    }

    public function setAccessToken(string $token): void
    {
        if (!isset($this->data['netsuite_auth'])) {
            $this->data['netsuite_auth'] = [];
        }
        $this->data['netsuite_auth']['token_id'] = $token;
        $this->save();
    }

    public function getCredentials(): array
    {
        return $this->data['netsuite_auth'] ?? [];
    }

    public function setCredentials(array $credentials): void
    {
        $this->data['netsuite_auth'] = $credentials;
        $this->save();
    }
}
